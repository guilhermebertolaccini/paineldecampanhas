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
 * NOAH Oficial API Provider
 * Documentação: API Noah - Guia de integração
 *
 * Endpoints:
 * - POST /v1/api/external/:apiId - Envio de texto
 * - POST /v1/api/external/:apiId/send-template - Envio de template aprovado
 * - GET /v1/api/external/:apiId/message-templates - Lista templates
 * - GET /v1/api/external/:apiId/phone-quality?channelId=X - Saúde da linha
 * - GET /v1/api/external/:apiId/channels - Lista canais WABA
 *
 * Auth: Authorization: Bearer {token}
 * Credenciais dinâmicas por carteira: url (base com apiId) + token
 */
@Injectable()
export class NoahOficialProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'NoahOficialProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const token = credentials.token;
    const url = credentials.url;
    return !!(
      url &&
      token &&
      typeof url === 'string' &&
      typeof token === 'string' &&
      url.trim().length > 0 &&
      token.trim().length > 0
    );
  }

  /**
   * Envia mensagens para a API NOAH Oficial.
   * A API NOAH envia uma mensagem por requisição - fazemos loop sequencial.
   */
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

    const baseUrl = (credentials.url as string).replace(/\/$/, '');
    const authHeader =
      (credentials.token as string).startsWith('Bearer ')
        ? (credentials.token as string)
        : `Bearer ${credentials.token}`;

    let successCount = 0;
    let lastError: string | null = null;

    for (let i = 0; i < data.length; i++) {
      const item = data[i];
      const phone = this.normalizePhoneNumber(item.telefone);
      const name = item.nome || '';
      const externalKey = `camp_${item.idcob_contrato || i}_${Date.now()}`;

      try {
        const isTemplate = this.detectTemplateMessage(item.mensagem);
        if (isTemplate) {
          await this.sendTemplateMessage(
            baseUrl,
            authHeader,
            phone,
            name,
            item,
            externalKey,
          );
        } else {
          await this.sendTextMessage(
            baseUrl,
            authHeader,
            phone,
            name,
            item.mensagem,
            externalKey,
          );
        }
        successCount++;
      } catch (err: any) {
        lastError =
          err.response?.data?.message ||
          err.message ||
          'Erro desconhecido ao enviar';
        this.logger.warn(
          `Falha ao enviar para ${phone} (${i + 1}/${data.length}): ${lastError}`,
        );
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

  private detectTemplateMessage(mensagem: string): boolean {
    if (!mensagem || typeof mensagem !== 'string') return false;
    const trimmed = mensagem.trim();
    if (trimmed.startsWith('{')) {
      try {
        const parsed = JSON.parse(trimmed);
        return !!(parsed.templateName || parsed.template_id);
      } catch {
        return false;
      }
    }
    return false;
  }

  private async sendTextMessage(
    baseUrl: string,
    authHeader: string,
    number: string,
    contactName: string,
    body: string,
    externalKey: string,
  ): Promise<void> {
    const payload = {
      number,
      contactName: contactName || undefined,
      body,
      externalKey,
    };

    await this.executeWithRetry(
      async () => {
        const result = await firstValueFrom(
          this.httpService.post(baseUrl, payload, {
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              Authorization: authHeader,
            },
            timeout: 30000,
          }),
        );
        return result;
      },
      this.getRetryStrategy(),
      { provider: 'NOAH_OFICIAL' },
    );
  }

  private async sendTemplateMessage(
    baseUrl: string,
    authHeader: string,
    number: string,
    contactName: string,
    item: CampaignData,
    externalKey: string,
  ): Promise<void> {
    let parsed: any = {};
    try {
      parsed =
        typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')
          ? JSON.parse(item.mensagem)
          : {};
    } catch {
      throw new Error('Mensagem template inválida (JSON malformado)');
    }

    const channelId = parsed.channelId ?? parsed.channel_id;
    const templateId = parsed.templateId ?? parsed.template_id;
    const templateName = parsed.templateName ?? parsed.template_name;
    const language = parsed.language ?? 'pt_BR';
    const components = parsed.components ?? [];

    if (!channelId || !templateName) {
      throw new Error(
        'Template NOAH requer channelId e templateName no JSON da mensagem',
      );
    }

    const payload = {
      number,
      channelId: Number(channelId),
      templateId: templateId ? Number(templateId) : undefined,
      templateName,
      language,
      components,
      externalKey,
    };

    const url = `${baseUrl}/send-template`;

    await this.executeWithRetry(
      async () => {
        const result = await firstValueFrom(
          this.httpService.post(url, payload, {
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              Authorization: authHeader,
            },
            timeout: 30000,
          }),
        );
        return result;
      },
      this.getRetryStrategy(),
      { provider: 'NOAH_OFICIAL' },
    );
  }
}
