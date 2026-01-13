import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderCredentials,
  ProviderResponse,
  RetryStrategy,
} from '../base/provider.interface';

interface RCSTemplateMessage {
  date?: string;
  document?: string;
  extra_fields?: Record<string, any>;
  fallback?: {
    type?: string;
    message?: string;
  };
  phone: string;
  url_callback_mo?: string;
  url_callback_status?: string;
  variables?: Record<string, any>;
}

interface RCSOtimaPayload {
  messages: RCSTemplateMessage[];
}

@Injectable()
export class RcsOtimaProvider extends BaseProvider {
  private readonly API_URL = 'https://services.otima.digital/v1/rcs/bulk/message/template';

  constructor(httpService: HttpService) {
    super(httpService, 'RcsOtimaProvider');
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    this.logger.log(`🌐 [RCS Ótima] Iniciando envio de ${data.length} mensagens`);

    // Valida credenciais
    if (!this.validateCredentials(credentials)) {
      throw new Error('Credenciais inválidas para RCS Ótima');
    }

    const token = credentials.token || credentials.authorization;

    // Formata mensagens para o formato da API Ótima
    const messages: RCSTemplateMessage[] = data.map((item) => {
      const phone = this.normalizePhoneNumber(item.telefone);

      // Remove o prefixo +55 se houver, pois a API Ótima espera sem prefixo
      const phoneWithoutPrefix = phone.startsWith('+55')
        ? phone.substring(3)
        : phone.startsWith('55')
          ? phone.substring(2)
          : phone;

      const message: RCSTemplateMessage = {
        phone: phoneWithoutPrefix,
        document: item.cpf_cnpj?.replace(/\D/g, ''), // Remove caracteres não numéricos
        extra_fields: {
          nome: item.nome,
          id_carteira: item.idgis_ambiente,
          idcob_contrato: item.idcob_contrato,
        },
        variables: {
          nome: item.nome,
        },
      };

      // Adiciona data de agendamento se fornecida
      if (item.data_cadastro) {
        message.date = item.data_cadastro;
      }

      return message;
    });

    const payload: RCSOtimaPayload = {
      messages,
    };

    this.logger.log(`📦 [RCS Ótima] Payload preparado com ${messages.length} mensagens`);
    this.logger.debug(`📋 [RCS Ótima] Payload: ${JSON.stringify(payload, null, 2)}`);

    // Executa requisição com retry
    const result = await this.executeWithRetry(
      async () => {
        this.logger.log(`🌐 [RCS Ótima] Enviando requisição para ${this.API_URL}`);
        this.logger.log(`🔑 [RCS Ótima] Token: ${token ? token.substring(0, 10) + '...' : 'N/A'}`);

        const response = await this.httpService.axiosRef.post(
          this.API_URL,
          payload,
          {
            headers: {
              'Content-Type': 'application/json',
              'authorization': token,
            },
            timeout: 60000, // 60 segundos
          },
        );

        this.logger.log(`✅ [RCS Ótima] Resposta recebida: ${response.status} ${response.statusText}`);
        this.logger.debug(`📄 [RCS Ótima] Response body: ${JSON.stringify(response.data)}`);

        return {
          success: true,
          message: 'Mensagens RCS Ótima enviadas com sucesso',
          data: {
            status: response.status,
            statusText: response.statusText,
            body: response.data,
          },
        };
      },
      this.getRetryStrategy(),
    );

    return result;
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const token = credentials.token || credentials.authorization;

    if (!token) {
      this.logger.error('❌ [RCS Ótima] Token não fornecido');
      return false;
    }

    this.logger.log('✅ [RCS Ótima] Credenciais válidas');
    return true;
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000], // 1s, 2s, 5s
    };
  }
}
