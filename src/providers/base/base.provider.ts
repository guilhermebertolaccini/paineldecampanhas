import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { IProvider, ProviderResponse, ProviderCredentials, CampaignData, ErrorType, RetryStrategy } from './provider.interface';

@Injectable()
export abstract class BaseProvider implements IProvider {
  protected readonly logger: Logger;

  constructor(protected readonly httpService: HttpService, providerName: string) {
    this.logger = new Logger(providerName);
  }

  abstract send(data: CampaignData[], credentials: ProviderCredentials): Promise<ProviderResponse>;
  
  abstract validateCredentials(credentials: ProviderCredentials): boolean;

  abstract getRetryStrategy(): RetryStrategy;

  protected async executeWithRetry<T>(
    fn: () => Promise<T>,
    retryStrategy: RetryStrategy,
    context?: { agendamentoId?: string; provider?: string }
  ): Promise<T> {
    let lastError: Error | undefined;
    
    for (let attempt = 0; attempt <= retryStrategy.maxRetries; attempt++) {
      try {
        if (attempt > 0) {
          const delay = retryStrategy.delays[attempt - 1] || retryStrategy.delays[retryStrategy.delays.length - 1];
          this.logger.warn(
            `🔄 Retry attempt ${attempt}/${retryStrategy.maxRetries} after ${delay}ms`,
            context
          );
          await this.sleep(delay);
        }
        
        if (attempt === 0) {
          this.logger.log(`🚀 Tentativa ${attempt + 1}/${retryStrategy.maxRetries + 1}`);
        } else {
          this.logger.log(`🔄 Tentativa ${attempt + 1}/${retryStrategy.maxRetries + 1}`);
        }
        
        return await fn();
      } catch (error: any) {
        lastError = error;
        const errorType = this.classifyError(error);
        
        // Log detalhado do erro
        const errorDetails = error.code 
          ? `${error.message} (code: ${error.code})` 
          : error.message;
        
        if (error.response) {
          // Erro HTTP - API respondeu mas com erro (400, 401, 500, etc)
          this.logger.error(`❌ Erro HTTP ${error.response.status}: ${errorDetails}`);
          this.logger.error(`   URL: ${error.config?.url || 'N/A'}`);
          this.logger.error(`   Response Body: ${JSON.stringify(error.response.data)}`);
          this.logger.error(`   Response Headers: ${JSON.stringify(error.response.headers)}`);
        } else if (error.request) {
          // Erro de rede - requisição foi feita mas não houve resposta (timeout, conexão recusada, etc)
          this.logger.error(`❌ Erro de rede (sem resposta do servidor): ${errorDetails}`);
          this.logger.error(`   URL tentada: ${error.config?.url || 'N/A'}`);
          this.logger.error(`   Código: ${error.code || 'N/A'}`);
          this.logger.error(`   Request feito: ${error.request ? 'Sim' : 'Não'}`);
          
          if (error.code === 'ETIMEDOUT') {
            this.logger.error(`   ⚠️ TIMEOUT: A requisição demorou mais que o timeout configurado`);
            this.logger.error(`   💡 Isso pode indicar: API lenta, problema de rede, ou firewall bloqueando`);
          }
        } else {
          // Erro antes de fazer a requisição (configuração, etc)
          this.logger.error(`❌ Erro na configuração: ${errorDetails}`);
        }
        
        // Não retry para erros 4xx ou validação
        if (errorType === ErrorType.API_ERROR_4XX || errorType === ErrorType.VALIDATION_ERROR) {
          this.logger.error(`⛔ Erro não retryable: ${error.message}`, error.stack, context);
          throw error;
        }
        
        // Se ainda temos tentativas, continua
        if (attempt < retryStrategy.maxRetries) {
          this.logger.warn(
            `⚠️ Attempt ${attempt + 1} failed: ${errorDetails}. Will retry.`,
            context
          );
          continue;
        }
      }
    }
    
    // Todas as tentativas falharam
    if (lastError) {
      const errorWithCode = lastError as any;
      const errorDetails = errorWithCode.code 
        ? `${lastError.message} (code: ${errorWithCode.code})` 
        : lastError.message;
      this.logger.error(
        `All ${retryStrategy.maxRetries + 1} attempts failed. Last error: ${errorDetails}`,
        lastError.stack,
        context
      );
      throw lastError;
    }
    
    // Caso extremo: nenhuma tentativa foi feita
    throw new Error('No attempts were made');
  }

  protected classifyError(error: any): ErrorType {
    // Erros de conexão de rede
    if (
      error.code === 'ECONNREFUSED' || 
      error.code === 'ETIMEDOUT' || 
      error.code === 'ENOTFOUND' ||
      error.code === 'ECONNRESET' ||
      error.code === 'EPIPE' ||
      error.code === 'EAI_AGAIN'
    ) {
      return ErrorType.NETWORK_ERROR;
    }
    
    // Erros SSL/TLS
    if (
      error.code === 'UNABLE_TO_VERIFY_LEAF_SIGNATURE' ||
      error.code === 'CERT_HAS_EXPIRED' ||
      error.code === 'SELF_SIGNED_CERT_IN_CHAIN' ||
      error.message?.includes('certificate') ||
      error.message?.includes('SSL') ||
      error.message?.includes('TLS')
    ) {
      return ErrorType.NETWORK_ERROR;
    }
    
    if (error.response) {
      const status = error.response.status;
      if (status >= 400 && status < 500) {
        return ErrorType.API_ERROR_4XX;
      }
      if (status >= 500) {
        return ErrorType.API_ERROR_5XX;
      }
    }
    
    if (error.message?.includes('timeout') || error.code === 'ETIMEDOUT') {
      return ErrorType.TIMEOUT;
    }
    
    return ErrorType.NETWORK_ERROR;
  }

  protected async sleep(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Normaliza número de telefone brasileiro
   * - Remove caracteres especiais (espaços, parênteses, traços)
   * - Garante que tenha código do país (55) no início
   * - Evita duplicação do código 55
   */
  protected normalizePhoneNumber(telefone: string): string {
    if (!telefone) {
      return telefone;
    }

    // Remove caracteres especiais
    let normalized = telefone.trim().replace(/[\s\(\)\-]/g, '');

    // Se já começa com 55, mantém como está
    // Se não começa com 55, adiciona 55 no início
    if (!normalized.startsWith('55')) {
      normalized = `55${normalized}`;
    }

    return normalized;
  }

  protected handleError(error: any, context?: { agendamentoId?: string; provider?: string }): ProviderResponse {
    const errorType = this.classifyError(error);
    const errorMessage = error.response?.data?.message || error.message || 'Unknown error';
    
    this.logger.error(`Provider error (${errorType}): ${errorMessage}`, error.stack, context);
    
    return {
      success: false,
      error: errorMessage,
    };
  }
}

