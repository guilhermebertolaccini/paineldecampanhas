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
export class NoahProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'NoahProvider');
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

    // Formata payload conforme formato NOAH
    const payload = {
      name: `campanha_${Date.now()}`,
      data: data.map((dado) => ({
        telefone: this.normalizePhoneNumber(dado.telefone),
        nome: dado.nome,
        idgis_ambiente: dado.idgis_ambiente,
        idcob_contrato: dado.idcob_contrato,
        cpf_cnpj: dado.cpf_cnpj,
        mensagem: dado.mensagem,
        data_cadastro: dado.data_cadastro,
      })),
    };

    const url = `${credentials.url}/contacts`;

    try {
      const response = await this.executeWithRetry(
        async () => {
          const result = await firstValueFrom(
            this.httpService.post(url, payload, {
              headers: {
                'Content-Type': 'application/json',
                Authorization: `INTEGRATION ${credentials.token}`,
              },
              timeout: 30000,
            }),
          );
          return result;
        },
        this.getRetryStrategy(),
        { provider: 'NOAH' },
      );

      // Trunca resposta se muito grande (evita estourar banco)
      const responseBody = JSON.stringify(response.data);
      const truncatedBody =
        responseBody.length > 65000
          ? responseBody.substring(0, 65000)
          : responseBody;

      return {
        success: true,
        message: 'Campanha enviada com sucesso',
        data: {
          status: response.status,
          body: truncatedBody,
          totalSent: data.length,
        },
      };
    } catch (error: any) {
      return this.handleError(error, { provider: 'NOAH' });
    }
  }
}

