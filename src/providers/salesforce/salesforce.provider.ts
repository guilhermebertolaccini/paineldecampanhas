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
export class SalesforceProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'SalesforceProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [2000, 5000, 10000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    // Credenciais estáticas para Salesforce
    return !!(
      credentials.client_id &&
      credentials.client_secret &&
      credentials.username &&
      credentials.password &&
      credentials.token_url &&
      credentials.api_url
    );
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas: client_id, client_secret, username, password, token_url e api_url são obrigatórias',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    // Verifica se tem operacao e automation_id (credenciais dinâmicas por ambiente)
    if (!credentials.operacao || !credentials.automation_id) {
      return {
        success: false,
        error: 'Credenciais de ambiente inválidas: operacao e automation_id são obrigatórias',
      };
    }

    const operacao = credentials.operacao;
    const automationId = credentials.automation_id;

    // PASSO 1: Obter token de acesso
    let accessToken: string;
    try {
      const tokenResponse = await this.executeWithRetry(
        async () => {
          // Salesforce OAuth2 requer application/x-www-form-urlencoded
          const params = new URLSearchParams();
          params.append('grant_type', 'password');
          params.append('client_id', credentials.client_id as string);
          params.append('client_secret', credentials.client_secret as string);
          params.append('username', credentials.username as string);
          params.append('password', credentials.password as string);

          const result = await firstValueFrom(
            this.httpService.post(
              credentials.token_url as string,
              params.toString(),
              {
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                  Accept: 'application/json',
                },
                timeout: 30000,
              },
            ),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'SALESFORCE' },
      );

      if (!tokenResponse.data?.access_token) {
        return {
          success: false,
          error: 'Falha ao obter token de acesso da Salesforce',
        };
      }

      accessToken = tokenResponse.data.access_token;
    } catch (error: any) {
      return this.handleError(error, { provider: 'SALESFORCE' });
    }

    // PASSO 2: Enviar contatos
    const contacts = data.map((dado) => ({
      attributes: { type: 'Contact' },
      MobilePhone: this.normalizePhoneNumber(dado.telefone),
      LastName: dado.nome,
      CPF_CNPJ__c: dado.cpf_cnpj || '12312312312',
      Operacao__c: operacao,
      disparo__c: true,
    }));

    const payload = {
      allOrNone: false,
      records: contacts,
    };

    try {
      const response = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.post(
              credentials.api_url as string,
              payload,
              {
                headers: {
                  'Content-Type': 'application/json',
                  Authorization: `Bearer ${accessToken}`,
                },
                timeout: 30000,
              },
            ),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'SALESFORCE' },
      );

      // Retorna sucesso, mas indica que precisa agendar o disparo MKC (20 minutos depois)
      return {
        success: true,
        message: 'Contatos enviados, disparo final agendado para 20 minutos',
        data: {
          automationId,
          scheduledAt: new Date(Date.now() + 20 * 60 * 1000).toISOString(), // 20 minutos
          contactsSent: contacts.length,
        },
      };
    } catch (error: any) {
      return this.handleError(error, { provider: 'SALESFORCE' });
    }
  }

  /**
   * Executa o disparo final no Marketing Cloud (chamado após 20 minutos)
   */
  async triggerMarketingCloud(
    automationId: string,
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (
      !credentials.mkc_client_id ||
      !credentials.mkc_client_secret ||
      !credentials.mkc_token_url ||
      !credentials.mkc_api_url
    ) {
      return {
        success: false,
        error: 'Credenciais do Marketing Cloud não configuradas',
      };
    }

    // PASSO 1: Obter token MKC
    let accessToken: string;
    try {
      const tokenResponse = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.post(
              credentials.mkc_token_url as string,
              {
                grant_type: 'client_credentials',
                client_id: credentials.mkc_client_id,
                client_secret: credentials.mkc_client_secret,
              },
              {
                headers: {
                  'Content-Type': 'application/json',
                },
                timeout: 30000,
              },
            ),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'SALESFORCE_MKC' },
      );

      if (!tokenResponse.data?.access_token) {
        return {
          success: false,
          error: 'Falha ao obter token do Marketing Cloud',
        };
      }

      accessToken = tokenResponse.data.access_token;
    } catch (error: any) {
      return this.handleError(error, { provider: 'SALESFORCE_MKC' });
    }

    // PASSO 2: Executar automação
    const url = `${credentials.mkc_api_url}/${automationId}/actions/runallonce`;

    try {
      const response = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.post(
              url,
              {},
              {
                headers: {
                  'Content-Type': 'application/json',
                  Authorization: `Bearer ${accessToken}`,
                },
                timeout: 30000,
              },
            ),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'SALESFORCE_MKC' },
      );

      return {
        success: true,
        message: 'Automação do Marketing Cloud executada com sucesso',
        data: {
          status: response.status,
          body: response.data,
        },
      };
    } catch (error: any) {
      return this.handleError(error, {
        provider: 'SALESFORCE_MKC',
      });
    }
  }
}

