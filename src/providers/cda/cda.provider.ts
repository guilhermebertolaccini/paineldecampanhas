import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderResponse,
  ProviderCredentials,
  RetryStrategy,
} from '../base/provider.interface';

@Injectable()
export class CdaProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'CdaProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000], // 1s, 2s, 5s
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    // Aceita tanto 'url' quanto 'api_url' (WordPress pode retornar api_url)
    const url = credentials.url || credentials.api_url;
    return !!(
      url &&
      credentials.api_key &&
      typeof url === 'string' &&
      typeof credentials.api_key === 'string'
    );
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas: URL e API Key são obrigatórias',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    // Aceita tanto 'url' quanto 'api_url' (WordPress pode retornar api_url)
    const apiUrl = credentials.url || credentials.api_url;

    // Extrai informações comuns
    const idgis_regua = data[0].idgis_ambiente;
    const mensagem_corpo = data[0].mensagem || '';

    // Formata as linhas conforme o formato CDA
    const linhas = data.map((dado) => {
      const last_cpf = dado.cpf_cnpj
        ? dado.cpf_cnpj.slice(-2)
        : '';
      
      // Normaliza o telefone usando função helper
      const telefone_normalizado = this.normalizePhoneNumber(dado.telefone);
      
      return `${dado.idgis_ambiente};${telefone_normalizado};${dado.nome};${dado.cpf_cnpj};${last_cpf}`;
    });

    const payload = {
      chave_api: credentials.api_key,
      codigo_equipe: idgis_regua,
      codigo_usuario: '1',
      nome: `campanha_${data[0].idgis_ambiente}_${Date.now()}`,
      ativo: true,
      corpo_mensagem: mensagem_corpo,
      mensagens: linhas,
    };

    // Log detalhado para debug
    const apiKeyMasked = credentials.api_key 
      ? `${credentials.api_key.substring(0, 8)}...${credentials.api_key.substring(credentials.api_key.length - 4)}`
      : 'NÃO FORNECIDA';
    
    this.logger.log(`🌐 Tentando enviar para API CDA:`);
    this.logger.log(`   URL: ${apiUrl}`);
    this.logger.log(`   API Key: ${apiKeyMasked}`);
    this.logger.log(`   Payload: ${JSON.stringify({ ...payload, chave_api: apiKeyMasked })}`);

    try {
      const response = await this.executeWithRetry(
        async () => {
          this.logger.debug(`📤 Enviando POST para: ${apiUrl}`);
          const startTime = Date.now();
          
          try {
            const result = await firstValueFrom(
              this.httpService.post(apiUrl as string, payload, {
                headers: {
                  'Content-Type': 'application/json',
                },
                timeout: 30000, // 30 segundos (mais realista)
              }),
            );
            const duration = Date.now() - startTime;
            this.logger.debug(`✅ Resposta recebida: Status ${result.status} (${duration}ms)`);
            return result;
          } catch (error: any) {
            const duration = Date.now() - startTime;
            
            // Log detalhado do erro
            if (error.response) {
              // Erro HTTP (400, 401, 500, etc)
              this.logger.error(`❌ Erro HTTP ${error.response.status} após ${duration}ms`);
              this.logger.error(`   URL: ${error.config?.url || 'N/A'}`);
              this.logger.error(`   Response: ${JSON.stringify(error.response.data)}`);
              this.logger.error(`   Headers: ${JSON.stringify(error.response.headers)}`);
            } else if (error.request) {
              // Erro de rede (timeout, conexão recusada, etc)
              this.logger.error(`❌ Erro de rede após ${duration}ms`);
              this.logger.error(`   URL tentada: ${error.config?.url || 'N/A'}`);
              this.logger.error(`   Código: ${error.code || 'N/A'}`);
              this.logger.error(`   Mensagem: ${error.message}`);
              
              // Se for timeout, mostra o timeout configurado
              if (error.code === 'ETIMEDOUT' || error.message?.includes('timeout')) {
                this.logger.error(`   ⏱️ Timeout configurado: 30000ms`);
              }
            } else {
              // Outro tipo de erro
              this.logger.error(`❌ Erro: ${error.message}`);
            }
            
            throw error;
          }
        },
        this.getRetryStrategy(),
        {
          provider: 'CDA',
        },
      );

      return {
        success: true,
        message: 'Campanha enviada com sucesso',
        data: {
          status: response.status,
          statusText: response.statusText,
          body: response.data,
        },
      };
    } catch (error: any) {
      return this.handleError(error, { provider: 'CDA' });
    }
  }
}

