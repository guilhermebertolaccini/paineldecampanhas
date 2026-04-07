import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderCredentials,
  ProviderResponse,
  RetryStrategy,
} from '../base/provider.interface';

/** Fallback amigável para nome vazio (alinhado ao GOSAC Oficial). */
const MAKING_DEFAULT_CONTACT_NAME = 'Cliente';

/** Endpoint padrão da API Making (WhatsApp Oficial). */
export const MAKING_CREATE_API_OFICIAL_URL =
  'https://campanhas.makingpublicidade.com.br/campaign/create_api_oficial';

/**
 * Corpo POST `/campaign/create_api_oficial` — contrato Making.
 */
export type MakingOfficialContactPayload = {
  phone: string;
  id_message: string;
  variables: Record<string, string>;
};

export type MakingOfficialRequestBody = {
  campaign_description: string;
  cost_center_id: number;
  team_id: number[];
  send_meta_template: string;
  phone_number_id: number;
  contacts: MakingOfficialContactPayload[];
};

export type MakingOfficialCredentials = {
  token?: string;
  bearer_token?: string;
  cost_center_id?: number;
  costCenterId?: number;
  team_id?: unknown;
  teamId?: unknown;
  phone_number_id?: number;
  phoneNumberId?: number;
  /** Opcional: sobrescreve URL (homologação). */
  url?: string;
  api_url?: string;
  making_api_url?: string;
};

@Injectable()
export class MakingOficialProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'MakingOficialProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000],
    };
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const c = credentials as MakingOfficialCredentials;
    const token = String(c.token ?? c.bearer_token ?? '').trim();
    const cc = Number(c.cost_center_id ?? c.costCenterId);
    const pn = Number(c.phone_number_id ?? c.phoneNumberId);
    const teams = this.normalizeTeamIds(c.team_id ?? c.teamId);
    return !!(
      token &&
      Number.isFinite(cc) &&
      cc > 0 &&
      Number.isFinite(pn) &&
      pn > 0 &&
      teams.length > 0
    );
  }

  /**
   * Mesma lógica restrita do GOSAC Oficial: barra `{}`, `[object Object]` e dados corrompidos.
   */
  private coerceToDisplayString(raw: unknown): string {
    if (raw == null) {
      return '';
    }
    if (typeof raw === 'string') {
      const s = raw.trim();
      if (s === '' || s === '{}' || /^\{\s*\}$/.test(s)) {
        return '';
      }
      const low = s.toLowerCase();
      if (low === '[object object]') {
        return '';
      }
      if (s.startsWith('{') && s.endsWith('}')) {
        try {
          const j = JSON.parse(s) as unknown;
          if (
            typeof j === 'object' &&
            j !== null &&
            !Array.isArray(j) &&
            Object.keys(j).length === 0
          ) {
            return '';
          }
        } catch {
          /* manter string original se não for JSON */
        }
      }
      return s;
    }
    if (typeof raw === 'number' && Number.isFinite(raw)) {
      return String(raw);
    }
    if (typeof raw === 'boolean') {
      return raw ? 'true' : 'false';
    }
    if (typeof raw === 'object') {
      if (Array.isArray(raw)) {
        return raw.length === 0 ? '' : JSON.stringify(raw);
      }
      return Object.keys(raw as object).length === 0 ? '' : JSON.stringify(raw);
    }
    const s = String(raw).trim();
    if (s.toLowerCase() === '[object object]') {
      return '';
    }
    return s;
  }

  private normalizeTeamIds(raw: unknown): number[] {
    if (raw == null) {
      return [];
    }
    if (Array.isArray(raw)) {
      return raw
        .map((x) => parseInt(String(x), 10))
        .filter((n) => !Number.isNaN(n) && n > 0);
    }
    if (typeof raw === 'number' && Number.isFinite(raw) && raw > 0) {
      return [raw];
    }
    if (typeof raw === 'string' && raw.trim() !== '') {
      try {
        const j = JSON.parse(raw) as unknown;
        if (Array.isArray(j)) {
          return this.normalizeTeamIds(j);
        }
      } catch {
        /* segue para inteiro único */
      }
      const n = parseInt(raw, 10);
      if (!Number.isNaN(n) && n > 0) {
        return [n];
      }
    }
    return [];
  }

  private parseMakingMensagemJson(mensagem: string): Record<string, unknown> | null {
    if (!mensagem || typeof mensagem !== 'string' || !mensagem.trim().startsWith('{')) {
      return null;
    }
    try {
      const parsed = JSON.parse(mensagem) as unknown;
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? (parsed as Record<string, unknown>)
        : null;
    } catch {
      return null;
    }
  }

  private extractSendMetaTemplateFromBatch(data: CampaignData[]): string | null {
    for (const item of data) {
      const p = this.parseMakingMensagemJson(item.mensagem);
      if (!p) {
        continue;
      }
      const raw =
        p.send_meta_template ??
        p.template_name ??
        p.templateName ??
        p.template_code ??
        p.name;
      if (raw != null && String(raw).trim() !== '') {
        return String(raw).trim();
      }
    }
    return null;
  }

  private resolveCampaignDescription(first: CampaignData | undefined): string {
    if (!first) {
      return `Campanha ${Date.now()}`;
    }
    const fromCol = this.coerceToDisplayString(first.nome_campanha);
    if (fromCol) {
      return fromCol.slice(0, 500);
    }
    return `Campanha ${Date.now()}`;
  }

  private pickRowFieldValue(dado: CampaignData, fieldName: string): string {
    const f = (fieldName || '').trim();
    if (!f) {
      return '';
    }
    const fl = f.toLowerCase();

    const vars = dado.variables;
    if (vars && typeof vars === 'object' && !Array.isArray(vars)) {
      const mk = Object.keys(vars).find((k) => k.toLowerCase() === fl);
      if (mk != null) {
        const coerced = this.coerceToDisplayString(vars[mk]);
        if (coerced !== '') {
          return coerced;
        }
      }
    }

    const rec = dado as unknown as Record<string, unknown>;
    const mk2 = Object.keys(rec).find(
      (k) =>
        k.toLowerCase() === fl && k !== 'variables' && k !== 'mensagem',
    );
    if (mk2 != null) {
      const coerced = this.coerceToDisplayString(rec[mk2]);
      if (coerced !== '') {
        return coerced;
      }
    }
    return '';
  }

  /**
   * Objeto chave-valor simples exigido pela Making (sem arrays de componentes).
   * `coerceToDisplayString` em todos os valores; nome vazio → `Cliente`.
   */
  mapMakingVariables(dado: CampaignData): Record<string, string> {
    const out: Record<string, string> = {};

    const vars = dado.variables;
    if (vars && typeof vars === 'object' && !Array.isArray(vars)) {
      for (const [k, v] of Object.entries(vars)) {
        const key = k.trim();
        if (!key) {
          continue;
        }
        let val = this.coerceToDisplayString(v);
        if (/^nome$/i.test(key)) {
          out[key] = val !== '' ? val : MAKING_DEFAULT_CONTACT_NAME;
        } else if (val !== '') {
          out[key] = val;
        }
      }
    }

    const nomeKeys = Object.keys(out).filter((k) => k.toLowerCase() === 'nome');
    const hasNome = nomeKeys.some((k) => (out[k] ?? '').trim() !== '');
    if (!hasNome) {
      const fromRow =
        this.pickRowFieldValue(dado, 'nome') || this.coerceToDisplayString(dado.nome);
      out.nome = fromRow !== '' ? fromRow : MAKING_DEFAULT_CONTACT_NAME;
    } else {
      for (const nk of nomeKeys) {
        if ((out[nk] ?? '').trim() === '') {
          out[nk] = MAKING_DEFAULT_CONTACT_NAME;
        }
      }
    }

    const cpfKeys = ['cpf', 'cpf_cnpj', 'documento'];
    const hasCpf = cpfKeys.some((k) =>
      Object.keys(out).some((ok) => ok.toLowerCase() === k && (out[ok] ?? '') !== ''),
    );
    if (!hasCpf) {
      const cpf =
        this.pickRowFieldValue(dado, 'cpf_cnpj') ||
        this.pickRowFieldValue(dado, 'cpf');
      const c = this.coerceToDisplayString(cpf);
      if (c !== '') {
        out.cpf = c;
      }
    }

    return out;
  }

  buildMakingOfficialBody(params: {
    campaignDescription: string;
    costCenterId: number;
    teamIds: number[];
    sendMetaTemplate: string;
    phoneNumberId: number;
    contacts: MakingOfficialContactPayload[];
  }): MakingOfficialRequestBody {
    return {
      campaign_description: params.campaignDescription,
      cost_center_id: params.costCenterId,
      team_id: [...params.teamIds],
      send_meta_template: params.sendMetaTemplate,
      phone_number_id: params.phoneNumberId,
      contacts: params.contacts,
    };
  }

  private resolvePostUrl(credentials: MakingOfficialCredentials): string {
    const raw =
      credentials.making_api_url ||
      credentials.api_url ||
      credentials.url ||
      MAKING_CREATE_API_OFICIAL_URL;
    return String(raw).trim().replace(/\/$/, '');
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error:
          'Credenciais Making inválidas: token (JWT), cost_center_id, phone_number_id e team_id (array ou número) são obrigatórios.',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    const c = credentials as MakingOfficialCredentials;
    const token = String(c.token ?? c.bearer_token ?? '').trim();
    const costCenterId = Number(c.cost_center_id ?? c.costCenterId);
    const phoneNumberId = Number(c.phone_number_id ?? c.phoneNumberId);
    const teamIds = this.normalizeTeamIds(c.team_id ?? c.teamId);

    const sendMetaTemplate = this.extractSendMetaTemplateFromBatch(data);
    if (!sendMetaTemplate) {
      return {
        success: false,
        error:
          'send_meta_template ausente. Inclua no JSON da mensagem (ex.: send_meta_template ou template_name).',
      };
    }

    const campaignDescription = this.resolveCampaignDescription(data[0]);
    const postUrl = this.resolvePostUrl(c);
    const authHeader = token.toLowerCase().startsWith('bearer ')
      ? token
      : `Bearer ${token}`;

    const contacts: MakingOfficialContactPayload[] = data
      .filter((d) => d.telefone && String(d.telefone).trim() !== '')
      .map((dado, index) => ({
        phone: this.normalizePhoneNumber(dado.telefone),
        id_message: String(
          dado.envio_id ?? dado.id ?? dado.agendamento_id ?? `msg-${index + 1}`,
        ),
        variables: this.mapMakingVariables(dado),
      }));

    if (contacts.length === 0) {
      return {
        success: false,
        error: 'Nenhum contato válido (telefone obrigatório)',
      };
    }

    const payload = this.buildMakingOfficialBody({
      campaignDescription,
      costCenterId,
      teamIds,
      sendMetaTemplate,
      phoneNumberId,
      contacts,
    });

    this.logger.debug(
      `[MAKING AUDIT] POST ${postUrl} | template=${sendMetaTemplate} | contatos=${payload.contacts.length}`,
    );
    for (const cRow of payload.contacts) {
      this.logger.debug(
        `[MAKING AUDIT] Disparando para ${cRow.phone} | Variáveis injetadas: ${JSON.stringify(cRow.variables)}`,
      );
    }

    try {
      const createResponse = await this.executeWithRetry(
        async () =>
          firstValueFrom(
            this.httpService.post(postUrl, payload, {
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: authHeader,
              },
              timeout: 30000,
            }),
          ),
        this.getRetryStrategy(),
        { provider: 'MAKING_OFICIAL' },
      );

      const body = createResponse.data as Record<string, unknown> | undefined;
      const campaignId =
        body?.campaign_id ??
        body?.campaignId ??
        body?.id ??
        (body?.data as Record<string, unknown> | undefined)?.id;

      if (campaignId == null || String(campaignId).trim() === '') {
        return {
          success: true,
          message: 'Requisição enviada à Making; resposta sem id de campanha explícito',
          data: { body: createResponse.data },
        };
      }

      return {
        success: true,
        message: 'Campanha Making (WhatsApp Oficial) criada com sucesso',
        campaignId: String(campaignId),
        data: {
          campaignId,
          body: createResponse.data,
        },
      };
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'MAKING_OFICIAL' });
    }
  }
}
