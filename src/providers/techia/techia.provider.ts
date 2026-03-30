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
import {
  parseTechiaVariablesFromMensagem,
  pickTechiaScalar,
  type TechiaRowVariables,
} from './techia-payload.utils';

const TECHIA_CORE_VAR_KEYS = new Set([
  'campanha_origem',
  'contrato',
  'documento',
  'valor',
  'atraso',
  'COD_DEPARA',
  'nome',
]);

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
    const tok =
      (typeof c.bearer_token === 'string' && c.bearer_token.trim() !== '') ||
      (typeof c.authorization === 'string' && c.authorization.trim() !== '') ||
      (typeof c.api_token === 'string' && c.api_token.trim() !== '') ||
      (typeof c.token === 'string' && c.token.trim() !== '');
    return !!tok;
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

  private mergeExtraTechiaFields(
    base: TechiaContact,
    vars: TechiaRowVariables,
  ): void {
    const b = base as Record<string, unknown>;
    for (const [k, v] of Object.entries(vars)) {
      if (TECHIA_CORE_VAR_KEYS.has(k)) continue;
      if (v === undefined || v === null || String(v).trim() === '') continue;
      b[k] = String(v).trim();
    }
  }

  /**
   * Monta um item do array do discador a partir do JSON `mensagem` (WordPress)
   * e do telefone normalizado na linha.
   */
  private buildDiscadorItem(
    row: CampaignData,
    credentials: ProviderCredentials,
  ): TechiaContact | null {
    const vars =
      row.variables && Object.keys(row.variables).length > 0
        ? (row.variables as TechiaRowVariables)
        : parseTechiaVariablesFromMensagem(row.mensagem);

    const split = splitBrazilPhoneForTechia(row.telefone);
    if (!split) {
      return null;
    }

    const credOrigem = this.resolveCampanhaOrigem(credentials);
    const campanhaOrigem =
      pickTechiaScalar(vars, 'campanha_origem', '').trim() || credOrigem;
    if (!campanhaOrigem) {
      this.logger.warn(
        `TECHIA: linha ignorada — sem campanha_origem (mapeamento e credencial vazios).`,
      );
      return null;
    }

    const documentoRaw = pickTechiaScalar(vars, 'documento', row.cpf_cnpj ?? '');
    const documento = documentoRaw.replace(/\D/g, '');
    const contrato = pickTechiaScalar(
      vars,
      'contrato',
      String(row.idcob_contrato ?? ''),
    );
    const nome = pickTechiaScalar(vars, 'nome', row.nome ?? '');
    const valor = pickTechiaScalar(vars, 'valor', '');
    const atraso = pickTechiaScalar(vars, 'atraso', '');
    const codDepara =
      pickTechiaScalar(vars, 'COD_DEPARA', '') ||
      pickTechiaScalar(vars, 'cod_depara', '');

    const base: TechiaContact = {
      campanha_origem: campanhaOrigem,
      contrato,
      documento,
      valor,
      atraso,
      COD_DEPARA: codDepara,
      nome,
      numeros: [split],
    };

    this.mergeExtraTechiaFields(base, vars);

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
          'Credenciais TECHIA: configure token (API Manager) para autenticação na API.',
      };
    }

    if (!data?.length) {
      return { success: false, error: 'Nenhum dado para enviar' };
    }

    const url =
      (credentials.api_url as string)?.trim() ||
      process.env.TECHIA_DISCADOR_URL?.trim() ||
      TECHIA_DISCADOR_DEFAULT_URL;

    const batchSize = this.resolveBatchSize(credentials);
    const contacts: TechiaContact[] = [];
    let skippedPhones = 0;
    let skippedCampanha = 0;

    for (const row of data) {
      const phoneOk = !!splitBrazilPhoneForTechia(row.telefone);
      const item = this.buildDiscadorItem(row, credentials);
      if (!item) {
        if (!phoneOk) {
          skippedPhones++;
          this.logger.warn(
            `Telefone inválido para TECHIA (DDD+8/9 após 55): ${row.telefone}`,
          );
        } else {
          skippedCampanha++;
        }
        continue;
      }
      contacts.push(item);
    }

    if (contacts.length === 0) {
      return {
        success: false,
        error: `Nenhum contato válido para TECHIA (telefones inválidos: ${skippedPhones}, sem campanha_origem: ${skippedCampanha}).`,
      };
    }

    if (skippedPhones > 0 || skippedCampanha > 0) {
      this.logger.warn(
        `TECHIA: ignoradas ${skippedPhones} linha(s) por telefone e ${skippedCampanha} por campanha_origem; enviando ${contacts.length}.`,
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
        data: {
          batches: chunks.length,
          sent: contacts.length,
          skippedPhones,
          skippedCampanha,
        },
      };
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'TECHIA' });
    }
  }
}
