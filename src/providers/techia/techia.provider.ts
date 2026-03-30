import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { BaseProvider } from '../base/base.provider';
import type {
  CampaignData,
  ProviderCredentials,
  ProviderResponse,
  RetryStrategy,
} from '../base/provider.interface';
import type { TechiaContact, TechiaProviderCredentials } from './techia.interface';
import {
  TECHIA_DEFAULT_BATCH_SIZE,
  TECHIA_DISCADOR_DEFAULT_URL,
  TECHIA_MAX_BATCH_SIZE,
} from './techia.interface';
import { chunkArray, splitBrazilPhoneForTechia } from './techia-discador.utils';

@Injectable()
export class TechiaProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'TechiaProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [2000, 5000, 10000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const c = credentials as TechiaProviderCredentials;
    const origin =
      (typeof c.campanha_origem === 'string' && c.campanha_origem.trim()) ||
      (typeof c.campaign_origin_id === 'string' && c.campaign_origin_id.trim());
    return !!origin;
  }

  /**
   * TODO(TECHIA): A documentação não especifica o header de autenticação.
   * Ajustar quando o fornecedor confirmar (Bearer, ApiKey, query, Basic).
   */
  private buildAuthHeaders(
    credentials: ProviderCredentials,
  ): Record<string, string> {
    const c = credentials as TechiaProviderCredentials;
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };

    const rawBearer = c.bearer_token || c.authorization;
    const rawToken = c.api_token || c.token;

    if (typeof rawBearer === 'string' && rawBearer.trim() !== '') {
      const v = rawBearer.trim();
      headers['Authorization'] = v.startsWith('Bearer ') ? v : `Bearer ${v}`;
    } else if (typeof rawToken === 'string' && rawToken.trim() !== '') {
      // Provisório: muitas APIs usam Bearer; se for X-Api-Key, trocar aqui.
      headers['Authorization'] = `Bearer ${rawToken.trim()}`;
    }

    return headers;
  }

  private resolveCampanhaOrigem(credentials: ProviderCredentials): string {
    const c = credentials as TechiaProviderCredentials;
    const v = c.campanha_origem || c.campaign_origin_id || '';
    return String(v).trim();
  }

  private resolveBatchSize(credentials: ProviderCredentials): number {
    const c = credentials as TechiaProviderCredentials;
    const n = Number(c.batch_size);
    if (!Number.isFinite(n) || n < 1) {
      return TECHIA_DEFAULT_BATCH_SIZE;
    }
    return Math.min(TECHIA_MAX_BATCH_SIZE, Math.floor(n));
  }

  private mapRowToContact(
    row: CampaignData,
    campanhaOrigem: string,
    numeros: { ddd: string; telefone: string }[],
  ): TechiaContact {
    const base: TechiaContact = {
      campanha_origem: campanhaOrigem,
      contrato: String(row.idcob_contrato ?? '').trim(),
      documento: String(row.cpf_cnpj ?? '').replace(/\D/g, ''),
      numeros,
      nome: String(row.nome ?? '').trim(),
    };
    return base;
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error:
          'Credenciais TECHIA: informe campanha_origem (ou campaign_origin_id) vindo do painel/API Manager.',
      };
    }

    if (!data?.length) {
      return { success: false, error: 'Nenhum dado para enviar' };
    }

    const campanhaOrigem = this.resolveCampanhaOrigem(credentials);
    const url =
      (credentials.api_url as string)?.trim() ||
      process.env.TECHIA_DISCADOR_URL?.trim() ||
      TECHIA_DISCADOR_DEFAULT_URL;

    const batchSize = this.resolveBatchSize(credentials);
    const contacts: TechiaContact[] = [];
    let skippedPhones = 0;

    for (const row of data) {
      const split = splitBrazilPhoneForTechia(row.telefone);
      if (!split) {
        skippedPhones++;
        this.logger.warn(
          `Telefone inválido para TECHIA (DDD+8/9 dígitos após 55): ${row.telefone}`,
        );
        continue;
      }
      contacts.push(this.mapRowToContact(row, campanhaOrigem, [split]));
    }

    if (contacts.length === 0) {
      return {
        success: false,
        error: `Nenhum contato com telefone válido para o discador TECHIA (ignorados: ${skippedPhones}).`,
      };
    }

    if (skippedPhones > 0) {
      this.logger.warn(
        `TECHIA: ${skippedPhones} linha(s) ignoradas por telefone inválido; enviando ${contacts.length}.`,
      );
    }

    const chunks = chunkArray(contacts, batchSize);
    const headers = this.buildAuthHeaders(credentials);

    try {
      for (let i = 0; i < chunks.length; i++) {
        const payload = chunks[i];
        await this.executeWithRetry(
          async () => {
            const res = await firstValueFrom(
              this.httpService.post(url, payload, {
                headers,
                timeout: 120_000,
                validateStatus: (status) => status < 600,
              }),
            );
            const status = res.status;
            if (status >= 400) {
              const body =
                typeof res.data === 'object'
                  ? JSON.stringify(res.data)
                  : String(res.data);
              throw new Error(`TECHIA HTTP ${status}: ${body}`);
            }
            return res;
          },
          this.getRetryStrategy(),
          { provider: 'TECHIA' },
        );
        this.logger.log(
          `TECHIA: lote ${i + 1}/${chunks.length} enviado (${payload.length} itens)`,
        );
      }

      return {
        success: true,
        message: `Enviados ${contacts.length} contatos à TECHIA em ${chunks.length} lote(s).`,
        data: { batches: chunks.length, sent: contacts.length, skipped: skippedPhones },
      };
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'TECHIA' });
    }
  }
}
