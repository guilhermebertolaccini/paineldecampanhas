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

/**
 * Provider para integração com API CromosApp RCS
 * Documentação: Manual de Integração CromosApp com CRM - Sistema CromosApp RCS
 * URL: https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI
 */
@Injectable()
export class RcsProvider extends BaseProvider {
  private readonly DEFAULT_API_URL = 'https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI';

  constructor(httpService: HttpService) {
    super(httpService, 'RcsProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000], // 1s, 2s, 5s
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    // RCS CDA funciona igual ao CDA:
    // - chave_api é obrigatória (vem das credenciais estáticas)
    // - codigo_equipe e codigo_usuario não vêm das credenciais, são definidos no send()
    return !!(
      credentials.chave_api &&
      typeof credentials.chave_api === 'string'
    );
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas: chave_api, codigo_equipe e codigo_usuario são obrigatórias',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    // Extrai informações comuns da primeira mensagem
    const mensagem_corpo = data[0].mensagem || '';
    const idgis_regua = data[0].idgis_ambiente;

    // RCS CDA funciona igual ao CDA:
    // codigo_equipe = idgis_ambiente (vem dos dados)
    // codigo_usuario = sempre '1'
    if (!idgis_regua) {
      return {
        success: false,
        error: 'idgis_ambiente não encontrado nos dados',
      };
    }

    // Formata as mensagens no formato CSV conforme o manual
    // Formato: "1;5541999998888;FULANO;TAG2;TAG3;TAG4;..."
    // - Primeira coluna: sempre "1"
    // - Segunda coluna: telefone com 55 na frente
    // - Terceira coluna: nome do cliente (TAG1)
    // - Próximas colunas: conforme uso (TAG2, TAG3, etc)
    const mensagens = data
      .filter((dado) => dado.telefone)
      .map((dado) => {
        const telefone_normalizado = this.normalizePhoneNumber(dado.telefone);
        const nome = dado.nome || '';
        
        // Monta a linha CSV: 1;telefone;nome;TAG2;TAG3;...
        // TAG2 pode ser idcob_contrato, TAG3 pode ser cpf_cnpj, etc
        const campos = [
          '1', // Primeira coluna fixa
          telefone_normalizado, // Telefone com 55
          nome, // Nome (TAG1)
        ];

        // Adiciona campos adicionais se existirem
        if (dado.idcob_contrato) {
          campos.push(dado.idcob_contrato);
        }
        if (dado.cpf_cnpj) {
          // Remove pontos e traços do CPF conforme o manual
          campos.push(dado.cpf_cnpj.replace(/[.\-]/g, ''));
        }

        return campos.join(';');
      });

    if (mensagens.length === 0) {
      return {
        success: false,
        error: 'Nenhuma mensagem válida para enviar',
      };
    }

    // Determina a URL da API: usa base_url das credenciais se disponível, senão usa a padrão
    const apiUrl = credentials.base_url || this.DEFAULT_API_URL;

    // Monta o payload conforme o manual
    // codigo_equipe = idgis_ambiente (vem dos dados)
    // codigo_usuario = sempre '1' (igual ao CDA)
    // ativo = true para iniciar o envio assim que disparar
    const payload: any = {
      chave_api: credentials.chave_api,
      codigo_equipe: idgis_regua,
      codigo_usuario: '1',
      nome: `campanha_${idgis_regua}_${Date.now()}`,
      ativo: true, // Inicia o envio assim que disparar
      corpo_mensagem: mensagem_corpo,
      mensagens: mensagens,
    };

    if (credentials.tag_numero_contrato) {
      payload.tag_numero_contrato = credentials.tag_numero_contrato;
    }

    if (credentials.tag_codigo_externo_mensagem) {
      payload.tag_codigo_externo_mensagem = credentials.tag_codigo_externo_mensagem;
    }

    if (credentials.tag_cpf) {
      payload.tag_cpf = credentials.tag_cpf;
    }

    if (credentials.tag_boleto) {
      payload.tag_boleto = credentials.tag_boleto;
    }

    if (credentials.dthr_agendado) {
      // Formato: YYYY-MM-DD HH:mm:ss
      payload.dthr_agendado = credentials.dthr_agendado;
    }

    if (credentials.codigo_externo) {
      payload.codigo_externo = credentials.codigo_externo;
    }

    // Log detalhado para debug
    const apiKeyMasked = credentials.chave_api 
      ? `${credentials.chave_api.substring(0, 8)}...${credentials.chave_api.substring(credentials.chave_api.length - 4)}`
      : 'NÃO FORNECIDA';
    
    this.logger.log(`🌐 Tentando enviar para API CromosApp RCS:`);
    this.logger.log(`   URL: ${apiUrl}`);
    this.logger.log(`   API Key: ${apiKeyMasked}`);
    this.logger.log(`   Total de mensagens: ${mensagens.length}`);
    this.logger.debug(`   Payload: ${JSON.stringify({ ...payload, chave_api: apiKeyMasked, mensagens: mensagens.slice(0, 2) })}`);

    try {
      const response = await this.executeWithRetry(
        async () => {
          this.logger.debug(`📤 Enviando POST para: ${apiUrl}`);
          const startTime = Date.now();
          
          try {
            const result = await firstValueFrom(
              this.httpService.post(apiUrl, payload, {
                headers: {
                  'Content-Type': 'application/json',
                },
                timeout: 30000, // 30 segundos
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
          provider: 'RCS',
        },
      );

      return {
        success: true,
        message: 'Campanha RCS enviada com sucesso',
        data: {
          status: response.status,
          statusText: response.statusText,
          body: response.data,
        },
      };
    } catch (error: any) {
      return this.handleError(error, { provider: 'RCS' });
    }
  }
}

