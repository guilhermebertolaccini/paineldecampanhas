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
export class GosacProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'GosacProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    return !!(
      credentials.url &&
      credentials.token &&
      typeof credentials.url === 'string' &&
      typeof credentials.token === 'string'
    );
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas: URL e Token são obrigatórias',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    const id_regua = data[0].idgis_ambiente;
    const mensagem = data[0].mensagem || 'Olá';

    // Formata contatos
    const contacts = data
      .filter((dado) => dado.nome && dado.telefone)
      .map((dado) => ({
        name: dado.nome,
        number: this.normalizePhoneNumber(dado.telefone),
        hasWhatsapp: true,
      }));

    if (contacts.length === 0) {
      return {
        success: false,
        error: 'Nenhum contato válido para enviar',
      };
    }

    const now = new Date();
    const campanha = `campanha_${now.getTime()}`;

    const payload = {
      name: `${campanha}_${now.toISOString().replace(/[:.]/g, '-')}`,
      message: mensagem,
      kind: 'whats',
      connectionId: null,
      contacts: contacts,
      defaultQueueId: 1,
      initialMinutes: 480,
      endMinutes: 1140,
      customProps: [],
      scheduled: false,
      scheduledAt: now.toISOString(),
      speed: 'low',
      tagId: 0,
      templateId: null,
    };

    try {
      // PASSO 1: Criar campanha
      const createResponse = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.post(credentials.url as string, payload, {
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'User-Agent': 'Mozilla/5.0',
                Authorization: credentials.token as string,
              },
              timeout: 30000,
            }),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'GOSAC' },
      );

      // Extrai o ID da campanha da resposta
      const campaignId =
        createResponse.data?.id ||
        createResponse.data?.campaign_id ||
        createResponse.data?.campaignId ||
        createResponse.data?.data?.id ||
        null;

      if (!campaignId) {
        return {
          success: false,
          error: 'ID da campanha não encontrado na resposta',
          data: createResponse.data,
        };
      }

      // PASSO 2: Retorna sucesso, mas indica que precisa agendar o PUT
      // O PUT será feito via BullMQ delayed job (2 minutos depois)
      return {
        success: true,
        message: 'Campanha criada e agendada para iniciar em 2 minutos',
        campaignId: campaignId.toString(),
        data: {
          campaignId,
          url: `${credentials.url}/${campaignId}/status/started`,
          token: credentials.token,
          scheduledAt: new Date(Date.now() + 120000).toISOString(), // 2 minutos
        },
      };
    } catch (error: any) {
      return this.handleError(error, { provider: 'GOSAC' });
    }
  }

  /**
   * Inicia a campanha (PUT request) - chamado após 2 minutos
   */
  async startCampaign(
    campaignId: string,
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas',
      };
    }

    const url = `${credentials.url}/${campaignId}/status/started`;

    try {
      const response = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.put(
              url,
              {},
              {
                headers: {
                  'Content-Type': 'application/json',
                  Authorization: credentials.token as string,
                },
                timeout: 60000,
              },
            ),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'GOSAC' },
      );

      return {
        success: true,
        message: 'Campanha iniciada com sucesso',
        data: {
          status: response.status,
          body: response.data,
        },
      };
    } catch (error: any) {
      return this.handleError(error, {
        provider: 'GOSAC',
      });
    }
  }
}

