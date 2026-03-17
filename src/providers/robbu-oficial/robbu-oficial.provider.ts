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
 * ROBBU Oficial API Provider
 * Documentação: https://api.robbu.global
 *
 * - Login: POST /v1/login → access_token (Bearer)
 * - Envio: POST /v1/sendmessage com Bearer + invenioPrivateToken
 * - channel: 3 = WhatsApp
 */
@Injectable()
export class RobbOficialProvider extends BaseProvider {
  private accessToken: string | null = null;
  private tokenExpiresAt: number = 0;

  constructor(httpService: HttpService) {
    super(httpService, 'RobbOficialProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    return !!(
      credentials.company &&
      credentials.username &&
      credentials.password &&
      credentials.invenio_private_token &&
      typeof credentials.company === 'string' &&
      typeof credentials.username === 'string' &&
      typeof credentials.password === 'string' &&
      typeof credentials.invenio_private_token === 'string'
    );
  }

  private async getAccessToken(credentials: ProviderCredentials): Promise<string> {
    const now = Date.now();
    if (this.accessToken && this.tokenExpiresAt > now + 60000) {
      return this.accessToken;
    }

    const response = await firstValueFrom(
      this.httpService.post(
        'https://api.robbu.global/v1/login',
        {
          Company: credentials.company,
          Username: credentials.username,
          Password: credentials.password,
        },
        {
          headers: { 'Content-Type': 'application/json' },
          timeout: 15000,
        },
      ),
    );

    const token = response.data?.access_token;
    const expiresIn = response.data?.expires_in ?? 28800000; // 3333 dias em segundos ≈ 288M
    if (!token) {
      throw new Error('ROBBU: access_token não retornado no login');
    }

    this.accessToken = token;
    this.tokenExpiresAt = now + expiresIn * 1000;
    return token;
  }

  private detectTemplateMessage(mensagem: string): boolean {
    if (!mensagem || typeof mensagem !== 'string') return false;
    const trimmed = mensagem.trim();
    if (trimmed.startsWith('{')) {
      try {
        const parsed = JSON.parse(trimmed);
        return !!(parsed.template_source === 'robbu_oficial' && parsed.templateName);
      } catch {
        return false;
      }
    }
    return false;
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error: 'Credenciais inválidas: Company, Username, Password e Token Privado são obrigatórios',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    const invenioToken = (credentials.invenio_private_token as string).trim();
    const sourcePhone = (credentials.source_phone as string)?.replace(/\D/g, '') || '';

    let successCount = 0;
    let lastError: string | null = null;

    for (let i = 0; i < data.length; i++) {
      const item = data[i];
      const phone = this.normalizePhoneNumber(item.telefone);
      const destPhone = phone.replace(/^55/, '');

      try {
        const bearerToken = await this.getAccessToken(credentials);

        const isTemplate = this.detectTemplateMessage(item.mensagem);
        let payload: any;

        if (isTemplate) {
          payload = this.buildTemplatePayload(item, invenioToken, sourcePhone, destPhone);
        } else {
          payload = this.buildTextPayload(item, invenioToken, sourcePhone, destPhone);
        }

        await this.executeWithRetry(
          async () => {
            return firstValueFrom(
              this.httpService.post('https://api.robbu.global/v1/sendmessage', payload, {
                headers: {
                  'Content-Type': 'application/json',
                  Authorization: `Bearer ${bearerToken}`,
                },
                timeout: 30000,
              }),
            );
          },
          this.getRetryStrategy(),
          { provider: 'ROBBU_OFICIAL' },
        );

        successCount++;
      } catch (err: any) {
        const msg = err.response?.data?.message || err.response?.data?.error || err.message;
        lastError = msg;
        this.logger.warn(`Falha ao enviar para ${phone} (${i + 1}/${data.length}): ${msg}`);

        if (err.response?.status === 401) {
          this.accessToken = null;
          this.tokenExpiresAt = 0;
        }
      }
    }

    if (successCount === 0) {
      return {
        success: false,
        error: lastError || 'Nenhuma mensagem enviada com sucesso',
      };
    }

    return {
      success: true,
      message: `${successCount}/${data.length} mensagens enviadas`,
      data: {
        totalSent: successCount,
        totalRequested: data.length,
        failed: data.length - successCount,
      },
    };
  }

  private buildTextPayload(
    item: CampaignData,
    invenioToken: string,
    sourcePhone: string,
    destPhone: string,
  ): any {
    const text = typeof item.mensagem === 'string' ? item.mensagem : '';
    return {
      invenioPrivateToken: invenioToken,
      text,
      channel: 3,
      source: {
        countryCode: 55,
        phoneNumber: sourcePhone || destPhone,
        prospect: false,
      },
      destination: {
        countryCode: 55,
        phoneNumber: destPhone,
        email: '',
      },
      contact: {
        name: item.nome || '',
        customCode: item.id_carteira || '',
        id: item.cpf_cnpj || '',
        updateIfExists: true,
      },
      discardSettings: {
        recentContactLastHours: 0,
        InAttendance: true,
      },
    };
  }

  private buildTemplatePayload(
    item: CampaignData,
    invenioToken: string,
    sourcePhone: string,
    destPhone: string,
  ): any {
    let parsed: any = {};
    try {
      parsed =
        typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')
          ? JSON.parse(item.mensagem)
          : {};
    } catch {
      throw new Error('Mensagem template ROBBU inválida (JSON malformado)');
    }

    const templateName = parsed.templateName || parsed.template_code;
    let templateParameters = parsed.templateParameters ?? [];
    const variablesMap = parsed.variables_map ?? {};
    const channel = parsed.channel ?? 3;

    if (!templateName) {
      throw new Error('Template ROBBU requer templateName no JSON da mensagem');
    }

    if (templateParameters.length === 0 && Object.keys(variablesMap).length > 0) {
      const record = item as any;
      templateParameters = Object.entries(variablesMap).map(([paramName, field]) => {
        const f = String(field);
        const val = record[f] ?? record[f.toUpperCase()];
        return {
          parameterName: paramName,
          parameterValue: String(val ?? ''),
        };
      });
    }

    return {
      invenioPrivateToken: invenioToken,
      channel: Number(channel),
      templateName,
      templateParameters,
      source: {
        countryCode: 55,
        phoneNumber: sourcePhone || destPhone,
        prospect: false,
      },
      destination: {
        countryCode: 55,
        phoneNumber: destPhone,
        email: '',
      },
      contact: {
        name: item.nome || '',
        customCode: item.id_carteira || '',
        id: item.cpf_cnpj || '',
        updateIfExists: true,
      },
      discardSettings: {
        recentContactLastHours: 0,
        InAttendance: true,
      },
    };
  }
}
