import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { randomUUID } from 'crypto';
import { firstValueFrom } from 'rxjs';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderResponse,
  ProviderCredentials,
  RetryStrategy,
} from '../base/provider.interface';

/**
 * Parâmetro de variável no corpo do template (contrato NOAH / Cloud API).
 */
export type NoahHsmTextParameter = {
  type: 'text';
  text: string;
};

/**
 * Bloco `components` para template HSM — header / body / footer com `parameters` ordenados.
 */
export type NoahHsmTemplateComponent = {
  type: 'header' | 'body' | 'footer';
  parameters: NoahHsmTextParameter[];
};

/** Bloco `button` (quick_reply etc.) — contrato NOAH / Postman. */
export type NoahHsmButtonComponent = {
  type: 'button';
  sub_type: string;
  index: number;
  parameters: Array<Record<string, unknown>>;
};

/**
 * Payload raiz POST `/v1/api/external/:apiId/send-template` (documentação NOAH).
 * Não incluir `contactName` / `name` / `nome` na raiz — a API dispara upsert de contato e pode retornar ERR_DUPLICATED_CONTACT.
 */
export type NoahSendTemplatePayload = {
  number: string;
  channelId: number;
  templateId?: number;
  templateName: string;
  language: string;
  components: Array<NoahHsmTemplateComponent | NoahHsmButtonComponent | Record<string, unknown>>;
  externalKey: string;
};

/**
 * NOAH Oficial API Provider
 *
 * Rotas (base salva no WP já com apiId):
 * - POST {baseUrl}/send-template — HSM / template aprovado
 * - POST {baseUrl} — texto livre (sem /send-template)
 *
 * Auth: documentação oficial — `Authorization: Bearer {token}`.
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
   * Base armazenada: `https://.../v1/api/external/{apiId}` (sem barra final).
   * URL final HSM: base + `/send-template` (evita duplicar se já vier sufixado).
   */
  private resolveNoahSendTemplateUrl(storedBaseUrl: string): string {
    const trimmed = storedBaseUrl.trim().replace(/\/+$/, '');
    const suffix = '/send-template';
    const lower = trimmed.toLowerCase();
    if (lower.endsWith(suffix)) {
      return trimmed;
    }
    return `${trimmed}${suffix}`;
  }

  /**
   * Corpo estrito para `/send-template`: somente campos operacionais.
   * Qualquer outra chave na raiz (ex.: contactName) pode acionar upsert na NOAH → ERR_DUPLICATED_CONTACT.
   */
  private buildStrictNoahSendTemplateBody(params: {
    number: string;
    channelId: number;
    templateName: string;
    language: string;
    components: NoahSendTemplatePayload['components'];
    externalKey: string;
    templateId?: number;
  }): Record<string, unknown> {
    const body: Record<string, unknown> = {
      number: params.number,
      channelId: params.channelId,
      templateName: params.templateName,
      language: params.language,
      components: params.components,
      externalKey: params.externalKey,
    };
    if (
      params.templateId != null &&
      !Number.isNaN(params.templateId) &&
      params.templateId > 0
    ) {
      body.templateId = params.templateId;
    }
    return JSON.parse(JSON.stringify(body)) as Record<string, unknown>;
  }

  /**
   * Valor bruto em `variables` (quando o WP envia JSON por linha) — chaves comuns de contrato/idcob.
   */
  private pickNoahVariableField(
    variables: Record<string, string> | undefined,
    keys: string[],
  ): string {
    if (!variables || typeof variables !== 'object') return '';
    for (const k of keys) {
      const direct = variables[k];
      if (direct != null && String(direct).trim() !== '') {
        return String(direct).trim();
      }
      const lower = k.toLowerCase();
      for (const [vk, vv] of Object.entries(variables)) {
        if (vk.toLowerCase() === lower && vv != null && String(vv).trim() !== '') {
          return String(vv).trim();
        }
      }
    }
    return '';
  }

  /**
   * `externalKey` na raiz do JSON NOAH — relatórios/webhooks.
   * Prioridade: variables (idcob_contrato / idcob / contrato) → raiz idcob_contrato → id / envio_id → agendamento_id → UUID.
   */
  private buildNoahExternalKey(item: CampaignData, index: number): string {
    const vars = item.variables;
    const fromVars = this.pickNoahVariableField(vars, [
      'idcob_contrato',
      'idcob',
      'contrato',
    ]);
    if (fromVars) return fromVars;

    if (
      item.idcob_contrato != null &&
      String(item.idcob_contrato).trim() !== ''
    ) {
      return String(item.idcob_contrato).trim();
    }

    if (item.id != null && String(item.id).trim() !== '') {
      return String(item.id).trim();
    }
    if (item.envio_id != null && String(item.envio_id).trim() !== '') {
      return String(item.envio_id).trim();
    }

    if (
      item.agendamento_id != null &&
      String(item.agendamento_id).trim() !== ''
    ) {
      return String(item.agendamento_id).trim();
    }

    return `noah_${index}_${randomUUID()}`;
  }

  /**
   * `variables_map` pode vir objeto ou string JSON (REST / filas).
   */
  private parseNoahVariablesMap(raw: unknown): Record<string, unknown> | null {
    if (raw == null) return null;
    if (typeof raw === 'string') {
      const s = raw.trim();
      if (!s || s === 'null') return null;
      try {
        const o = JSON.parse(s) as unknown;
        return o && typeof o === 'object' && !Array.isArray(o)
          ? (o as Record<string, unknown>)
          : null;
      } catch {
        return null;
      }
    }
    if (typeof raw === 'object' && !Array.isArray(raw)) {
      return raw as Record<string, unknown>;
    }
    return null;
  }

  /** Linha de disparo + `variables` do WP (fallback de colunas). CSV: `variables` pode vir string JSON. */
  private mergeNoahCampaignRow(item: CampaignData): Record<string, unknown> {
    const row = { ...(item as unknown as Record<string, unknown>) };
    const varsUnknown = item.variables as unknown;
    if (typeof varsUnknown === 'string') {
      const inner = this.parseNoahVariablesMap(varsUnknown);
      if (inner) {
        for (const [k, v] of Object.entries(inner)) {
          const cur = row[k];
          if (cur == null || String(cur).trim() === '') {
            row[k] = v;
          }
        }
      }
    } else if (varsUnknown && typeof varsUnknown === 'object' && !Array.isArray(varsUnknown)) {
      for (const [k, v] of Object.entries(varsUnknown as Record<string, unknown>)) {
        const cur = row[k];
        if (cur == null || String(cur).trim() === '') {
          row[k] = v;
        }
      }
    }
    return row;
  }

  private normalizeNoahTemplateComponents(
    components: unknown,
  ): NoahHsmTemplateComponent[] {
    if (!Array.isArray(components)) return [];
    const out: NoahHsmTemplateComponent[] = [];

    for (const c of components) {
      if (!c || typeof c !== 'object') continue;
      const comp = c as Record<string, unknown>;
      const typ = String(comp.type ?? comp.Type ?? '').toLowerCase();
      if (typ !== 'body' && typ !== 'header' && typ !== 'footer') continue;

      const rawParams = comp.parameters;
      if (!Array.isArray(rawParams)) continue;

      const parameters: NoahHsmTextParameter[] = [];
      for (const p of rawParams) {
        if (!p || typeof p !== 'object') continue;
        const pr = p as Record<string, unknown>;
        const pType = String(pr.type ?? 'text').toLowerCase();
        const rawText = pr.text != null ? String(pr.text) : '';
        const text = rawText.trim() === '' ? ' ' : rawText;
        if (pType === 'text') {
          parameters.push({ type: 'text', text });
        }
      }

      if (parameters.length > 0) {
        out.push({
          type: typ as 'header' | 'body' | 'footer',
          parameters,
        });
      }
    }

    return out;
  }

  /**
   * CSV / fila: `variables_map` pode vir string JSON dentro de `item.variables` ou do próprio `variables` stringificado.
   */
  private resolveNoahVariablesMapMerged(
    parsed: Record<string, unknown>,
    item: CampaignData,
  ): Record<string, unknown> | null {
    const merge = (
      base: Record<string, unknown> | null,
      layer: Record<string, unknown> | null,
    ): Record<string, unknown> | null => {
      if (!layer || Object.keys(layer).length === 0) return base;
      if (!base || Object.keys(base).length === 0) return { ...layer };
      return { ...base, ...layer };
    };

    let acc = this.parseNoahVariablesMap(parsed.variables_map);

    const varsUnknown = item.variables as unknown;
    if (typeof varsUnknown === 'string') {
      const inner = this.parseNoahVariablesMap(varsUnknown);
      if (inner) {
        acc = merge(acc, this.parseNoahVariablesMap(inner.variables_map));
      }
    } else if (
      varsUnknown &&
      typeof varsUnknown === 'object' &&
      !Array.isArray(varsUnknown)
    ) {
      const vr = varsUnknown as Record<string, unknown>;
      acc = merge(acc, this.parseNoahVariablesMap(vr.variables_map));
    }

    return acc && Object.keys(acc).length > 0 ? acc : null;
  }

  /** Nunca enviar header/body/footer com `parameters` vazio (400 localizable_params). */
  private filterNonEmptyNoahHsmComponents(
    list: NoahHsmTemplateComponent[],
  ): NoahHsmTemplateComponent[] {
    return list.filter(
      (c) =>
        Array.isArray(c.parameters) &&
        c.parameters.length > 0 &&
        c.parameters.every((p) => p && typeof p === 'object'),
    );
  }

  /**
   * Maior índice em placeholders estilo Meta (`{{1}}`, `{2}`) — define quantos parâmetros
   * localizáveis o segmento precisa (1..N contíguos).
   */
  private maxPlaceholderIndexInString(s: unknown): number {
    if (typeof s !== 'string' || !s.trim()) return 0;
    const re = /\{\{\s*(\d+)\s*\}\}|\{(\d+)\}/g;
    let m: RegExpExecArray | null;
    let max = 0;
    while ((m = re.exec(s)) !== null) {
      const n = parseInt(m[1] || m[2], 10);
      if (!Number.isNaN(n) && n > max) max = n;
    }
    return max;
  }

  /**
   * Quantos parâmetros o template exige por segmento: blocos crus (incl. image/document no header)
   * + texto com `{{n}}` em textHeader/textBody/textFooter (raiz ou templateData).
   */
  private inferExpectedNoahHsmParamCounts(
    parsed: Record<string, unknown>,
  ): { header: number; body: number; footer: number } {
    const counts = { header: 0, body: 0, footer: 0 };

    const scanComponentArray = (arr: unknown) => {
      if (!Array.isArray(arr)) return;
      for (const c of arr) {
        if (!c || typeof c !== 'object') continue;
        const o = c as Record<string, unknown>;
        const typ = String(o.type ?? '').toLowerCase();
        if (typ !== 'header' && typ !== 'body' && typ !== 'footer') continue;
        const params = o.parameters;
        const n = Array.isArray(params) ? params.length : 0;
        if (n > counts[typ as 'header' | 'body' | 'footer']) {
          counts[typ as 'header' | 'body' | 'footer'] = n;
        }
      }
    };

    scanComponentArray(parsed.components);
    for (const key of ['templateData', 'template_data', 'raw_data'] as const) {
      const v = parsed[key];
      if (v && typeof v === 'object' && !Array.isArray(v)) {
        scanComponentArray((v as Record<string, unknown>).components);
      }
    }

    const bumpText = (text: unknown, seg: 'header' | 'body' | 'footer') => {
      const m = this.maxPlaceholderIndexInString(text);
      if (m > counts[seg]) counts[seg] = m;
    };

    bumpText(parsed.textHeader ?? parsed.text_header, 'header');
    bumpText(parsed.textBody ?? parsed.text_body, 'body');
    bumpText(parsed.textFooter ?? parsed.text_footer, 'footer');

    for (const key of ['templateData', 'template_data'] as const) {
      const v = parsed[key];
      if (!v || typeof v !== 'object' || Array.isArray(v)) continue;
      const td = v as Record<string, unknown>;
      bumpText(td.textHeader ?? td.text_header, 'header');
      bumpText(td.textBody ?? td.text_body, 'body');
      bumpText(td.textFooter ?? td.text_footer, 'footer');
    }

    return counts;
  }

  /**
   * Garante header/body/footer com o número correto de `parameters` (texto), preenchendo com
   * valores já normalizados, depois `builtFromMap`, senão espaço — evita 400 localizable_params.
   */
  private mergeNoahHsmComponentsToExpectedSlotCounts(
    current: NoahHsmTemplateComponent[],
    expected: { header: number; body: number; footer: number },
    builtFromMap: NoahHsmTemplateComponent[] | null,
  ): NoahHsmTemplateComponent[] {
    const pick = (list: NoahHsmTemplateComponent[], seg: string) =>
      list.find((c) => c.type === seg);

    const segments: Array<'header' | 'body' | 'footer'> = [
      'header',
      'body',
      'footer',
    ];
    const out: NoahHsmTemplateComponent[] = [];

    for (const seg of segments) {
      const exp = expected[seg];
      const cur = pick(current, seg);
      const built = pick(builtFromMap ?? [], seg);
      const curLen = cur?.parameters?.length ?? 0;
      const builtLen = built?.parameters?.length ?? 0;
      const need = Math.max(exp, curLen, builtLen);
      if (need <= 0) continue;

      const parameters: NoahHsmTextParameter[] = [];
      for (let i = 0; i < need; i++) {
        const ct = cur?.parameters[i]?.text;
        const bt = built?.parameters[i]?.text;
        let text = '';
        if (ct != null && String(ct).trim() !== '') text = String(ct).trim();
        else if (bt != null && String(bt).trim() !== '') text = String(bt).trim();
        else text = ' ';
        if (text === '') text = ' ';
        parameters.push({ type: 'text', text });
      }
      out.push({ type: seg, parameters });
    }

    return out;
  }

  /**
   * Se `parsed.components` veio vazio, usa HSM em templateData / raw_data (persistência WP por arquivo).
   */
  private resolveNoahHsmRawBlocksFromParsed(
    parsed: Record<string, unknown>,
    primaryHsm: unknown[],
  ): unknown[] {
    if (Array.isArray(primaryHsm) && primaryHsm.length > 0) {
      let n = 0;
      for (const c of primaryHsm) {
        if (!c || typeof c !== 'object') continue;
        const params = (c as Record<string, unknown>).parameters;
        if (Array.isArray(params)) n += params.length;
      }
      if (n > 0) return [...primaryHsm];
    }

    for (const key of ['templateData', 'template_data', 'raw_data'] as const) {
      const v = parsed[key];
      if (!v || typeof v !== 'object' || Array.isArray(v)) continue;
      const nested = (v as Record<string, unknown>).components;
      const part = this.partitionNoahComponentsArray(nested);
      if (part.hsm.length > 0) return [...part.hsm];
    }

    return Array.isArray(primaryHsm) ? [...primaryHsm] : [];
  }

  /**
   * Separa blocos HSM (header/body/footer) de `button` no array persistido no JSON da mensagem.
   */
  private partitionNoahComponentsArray(
    arr: unknown,
  ): { hsm: unknown[]; buttons: unknown[] } {
    const hsm: unknown[] = [];
    const buttons: unknown[] = [];
    if (!Array.isArray(arr)) return { hsm, buttons };
    for (const c of arr) {
      if (!c || typeof c !== 'object') continue;
      const typ = String((c as Record<string, unknown>).type ?? '').toLowerCase();
      if (typ === 'button') buttons.push(c);
      else hsm.push(c);
    }
    return { hsm, buttons };
  }

  /**
   * Garante `parameters` não vazio em botões (quick_reply → payload mínimo).
   */
  private normalizeNoahButtonBlocks(
    rawButtons: unknown[],
    parsed: Record<string, unknown>,
  ): NoahHsmButtonComponent[] {
    const out: NoahHsmButtonComponent[] = [];

    const pushFromObject = (c: Record<string, unknown>) => {
      const typ = String(c.type ?? '').toLowerCase();
      if (typ !== 'button') return;

      let params = Array.isArray(c.parameters)
        ? (c.parameters as Record<string, unknown>[])
        : [];
      params = params.filter((p) => p && typeof p === 'object');

      const subRaw =
        (c.sub_type as string) ?? (c.subType as string) ?? 'quick_reply';
      const sub = String(subRaw).toLowerCase().replace(/-/g, '_');

      if (params.length === 0) {
        if (sub === 'quick_reply' || sub === 'quickreply') {
          const payloadText =
            c.payload != null
              ? String(c.payload)
              : c.text != null
                ? String(c.text)
                : c.title != null
                  ? String(c.title)
                  : ' ';
          params = [
            {
              type: 'payload',
              payload: payloadText.trim() === '' ? ' ' : payloadText.trim(),
            },
          ];
        } else {
          params = [{ type: 'text', text: ' ' }];
        }
      }

      let idx = Number(c.index);
      if (Number.isNaN(idx)) idx = out.length;

      out.push({
        type: 'button',
        sub_type: String(subRaw),
        index: idx,
        parameters: params,
      });
    };

    for (const c of rawButtons) {
      if (c && typeof c === 'object') pushFromObject(c as Record<string, unknown>);
    }

    const attachFromTemplateData = (td: unknown) => {
      if (!td || typeof td !== 'object') return;
      const comps = (td as Record<string, unknown>).components;
      if (!Array.isArray(comps)) return;
      for (const c of comps) {
        if (c && typeof c === 'object') {
          const o = c as Record<string, unknown>;
          if (String(o.type ?? '').toLowerCase() === 'button') {
            pushFromObject(o);
          }
        }
      }
    };

    if (out.length === 0) {
      attachFromTemplateData(parsed.templateData);
      attachFromTemplateData(parsed.template_data);
      attachFromTemplateData(parsed.rawTemplate);
      attachFromTemplateData(parsed.raw_template);

      const bwrap = parsed.buttons;
      if (bwrap && typeof bwrap === 'object') {
        const inner = (bwrap as Record<string, unknown>).buttons;
        if (Array.isArray(inner)) {
          let i = 0;
          for (const b of inner) {
            if (!b || typeof b !== 'object') continue;
            const bt = b as Record<string, unknown>;
            const text = String(bt.text ?? bt.title ?? bt.label ?? ' ').trim() || ' ';
            pushFromObject({
              type: 'button',
              sub_type: 'quick_reply',
              index: i++,
              parameters: [{ type: 'payload', payload: text }],
            });
          }
        }
      }
    }

    return out;
  }

  /**
   * Envia mensagens para a API NOAH Oficial (uma requisição por destinatário).
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
    const rawTok = String(credentials.token ?? '').trim();
    const authHeader = this.buildNoahAuthorizationHeader(rawTok);

    let successCount = 0;
    let lastError: string | null = null;

    for (let i = 0; i < data.length; i++) {
      const item = data[i];
      const number = this.normalizePhoneNumber(item.telefone);
      const name = item.nome || '';
      const externalKey = this.buildNoahExternalKey(item, i);

      try {
        const isTemplate = this.detectTemplateMessage(item.mensagem);
        if (isTemplate) {
          await this.sendTemplateMessage(
            baseUrl,
            authHeader,
            number,
            item,
            externalKey,
          );
        } else {
          await this.sendTextMessage(
            baseUrl,
            authHeader,
            number,
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
        const st = err.response?.status;
        const body = err.response?.data;
        const errTok =
          body && typeof body === 'object' && body.error != null
            ? String(body.error)
            : '';
        if (
          st === 403 &&
          (errTok === 'ERR_SESSION_NOT_AUTH_TOKEN' ||
            JSON.stringify(body ?? '').includes('ERR_SESSION_NOT_AUTH_TOKEN'))
        ) {
          const apiId = this.extractNoahApiIdFromBaseUrl(baseUrl);
          this.logger.warn(
            `[NOAH] Erro 403: URL Inválida. Verifique se o apiId (${apiId}) configurado no API Manager pertence a este Token.`,
          );
        }
        this.logger.warn(
          `Falha ao enviar para ${number} (${i + 1}/${data.length}): ${lastError}`,
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

  /**
   * NOAH: `Authorization: Bearer {token}`. Remove prefixos duplicados (`Bearer` / `INTEGRATION`) vindos do banco.
   */
  private buildNoahAuthorizationHeader(token: string): string {
    if (!token) return '';
    let raw = String(token).trim();
    if (!raw) return '';
    for (let depth = 0; depth < 6; depth++) {
      const stripped = raw.replace(/^(Bearer|INTEGRATION)\s+/i, '').trim();
      if (stripped === raw) break;
      raw = stripped;
    }
    if (!raw) return '';
    return `Bearer ${raw}`;
  }

  /** Segmento `apiId` em URLs `.../v1/api/external/{apiId}/...`. */
  private extractNoahApiIdFromBaseUrl(baseUrl: string): string {
    const m = /\/external\/([^/?#]+)/i.exec(baseUrl);
    return m ? m[1] : 'desconhecido';
  }

  /**
   * `channelId` do send-template: JSON da mensagem → `broker_code` da REST → `variables`.
   */
  private resolveNoahChannelId(
    parsed: Record<string, unknown>,
    item: CampaignData,
  ): number {
    const tryNum = (v: unknown): number => {
      if (v === undefined || v === null || v === '') return 0;
      const n = Number(v);
      return !Number.isNaN(n) && n > 0 ? n : 0;
    };

    let n = tryNum(parsed.channelId ?? parsed.channel_id);
    if (n > 0) return n;

    n = tryNum(item.broker_code);
    if (n > 0) return n;

    const fromVars = this.pickNoahVariableField(item.variables, [
      'noah_channel_id',
      'channel_id',
      'broker_code',
    ]);
    n = tryNum(fromVars);
    return n > 0 ? n : 0;
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
    item: CampaignData,
    externalKey: string,
  ): Promise<void> {
    let parsed: Record<string, unknown> = {};
    try {
      const raw =
        typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')
          ? JSON.parse(item.mensagem)
          : {};
      parsed = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    } catch {
      throw new Error('Mensagem template inválida (JSON malformado)');
    }

    const channelIdNum = this.resolveNoahChannelId(parsed, item);
    const templateIdRaw = parsed.templateId ?? parsed.template_id;
    const templateName =
      (parsed.templateName ?? parsed.template_name ?? parsed.template_code) as
        | string
        | undefined;
    const language = (parsed.language as string) ?? 'pt_BR';

    const part = this.partitionNoahComponentsArray(parsed.components);
    let componentsRaw: unknown[] = this.resolveNoahHsmRawBlocksFromParsed(
      parsed,
      part.hsm,
    );

    const variablesMapMerged = this.resolveNoahVariablesMapMerged(parsed, item);

    const countAllNoahParams = (blocks: unknown): number => {
      if (!Array.isArray(blocks)) return 0;
      let n = 0;
      for (const c of blocks) {
        if (!c || typeof c !== 'object') continue;
        const params = (c as Record<string, unknown>).parameters;
        if (Array.isArray(params)) n += params.length;
      }
      return n;
    };

    let builtFromMap: NoahHsmTemplateComponent[] | null = null;
    if (variablesMapMerged && Object.keys(variablesMapMerged).length > 0) {
      builtFromMap = this.buildNoahComponentsFromVariablesMap(
        variablesMapMerged,
        item,
      );
    }

    if (countAllNoahParams(componentsRaw) === 0 && builtFromMap && builtFromMap.length > 0) {
      componentsRaw = builtFromMap;
    }

    let components = this.filterNonEmptyNoahHsmComponents(
      this.normalizeNoahTemplateComponents(componentsRaw),
    );

    if (
      components.length === 0 ||
      components.every((c) => !c.parameters || c.parameters.length === 0)
    ) {
      if (builtFromMap && builtFromMap.length > 0) {
        components = this.filterNonEmptyNoahHsmComponents(
          this.normalizeNoahTemplateComponents(builtFromMap),
        );
      }
    }

    const expectedSlots = this.inferExpectedNoahHsmParamCounts(parsed);
    const anyExpected =
      expectedSlots.header > 0 ||
      expectedSlots.body > 0 ||
      expectedSlots.footer > 0;
    const shouldReconcileSlots =
      anyExpected ||
      components.length > 0 ||
      (builtFromMap != null && builtFromMap.length > 0);

    if (shouldReconcileSlots) {
      components = this.mergeNoahHsmComponentsToExpectedSlotCounts(
        components,
        expectedSlots,
        builtFromMap,
      );
    }

    const buttonSourceArrays: unknown[] = [part.buttons];
    for (const key of ['templateData', 'template_data', 'raw_data'] as const) {
      const v = parsed[key];
      if (v && typeof v === 'object' && !Array.isArray(v)) {
        const nested = (v as Record<string, unknown>).components;
        const pb = this.partitionNoahComponentsArray(nested);
        if (pb.buttons.length > 0) buttonSourceArrays.push(pb.buttons);
      }
    }
    const mergedButtonRaw: unknown[] = [];
    for (const arr of buttonSourceArrays) {
      if (Array.isArray(arr)) mergedButtonRaw.push(...arr);
    }
    const buttonBlocks = this.normalizeNoahButtonBlocks(mergedButtonRaw, parsed);

    if (!channelIdNum || !templateName || String(templateName).trim() === '') {
      throw new Error(
        'Template NOAH requer channelId (remetente) e templateName — verifique o JSON da mensagem e o broker_code na fila.',
      );
    }

    const url = this.resolveNoahSendTemplateUrl(baseUrl);

    const tid =
      templateIdRaw !== undefined && templateIdRaw !== null && templateIdRaw !== ''
        ? Number(templateIdRaw)
        : NaN;
    const templateIdOpt =
      !Number.isNaN(tid) && tid > 0 ? tid : undefined;

    const requestBody = this.buildStrictNoahSendTemplateBody({
      number,
      channelId: channelIdNum,
      templateName: String(templateName).trim(),
      language: String(language),
      components: [...components, ...buttonBlocks],
      externalKey,
      templateId: templateIdOpt,
    });

    this.logger.debug(
      `NOAH send-template → ${url} | channelId=${channelIdNum} | externalKey=${externalKey} | number=${number}`,
    );

    await this.executeWithRetry(
      async () => {
        const result = await firstValueFrom(
          this.httpService.post<unknown>(url, requestBody, {
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

  /**
   * Interpreta chaves `header_1`, `body_2`, `footer_1` ou legado `1` (= body).
   * Preenche buracos 1..max com espaço para não quebrar localizable_params da API NOAH.
   */
  private parseNoahVariableSlotKey(varName: string):
    | { segment: 'header' | 'body' | 'footer'; index: number }
    | null {
    const s = varName.trim();
    const hm = /^(header)[_-](\d+)$/i.exec(s);
    if (hm) return { segment: 'header', index: parseInt(hm[2], 10) };
    const bm = /^(body)[_-](\d+)$/i.exec(s);
    if (bm) return { segment: 'body', index: parseInt(bm[2], 10) };
    const fm = /^(footer)[_-](\d+)$/i.exec(s);
    if (fm) return { segment: 'footer', index: parseInt(fm[2], 10) };
    if (/^\d+$/.test(s)) {
      const idx = parseInt(s, 10);
      if (idx > 0) return { segment: 'body', index: idx };
    }
    return null;
  }

  private resolveNoahVariablesMapEntryToText(
    mapping: unknown,
    row: Record<string, unknown>,
    cell: (col: string) => string,
  ): string {
    if (
      mapping &&
      typeof mapping === 'object' &&
      !Array.isArray(mapping) &&
      'type' in mapping &&
      'value' in mapping
    ) {
      const m = mapping as { type: string; value: string };
      if (m.type === 'field') {
        return cell(String(m.value ?? ''));
      }
      return String(m.value ?? '').trim();
    }
    if (typeof mapping === 'string' && mapping !== '') {
      return cell(mapping);
    }
    return '';
  }

  /**
   * Converte `variables_map` em blocos `header` + `body` + `footer` para send-template.
   */
  private buildNoahComponentsFromVariablesMap(
    variablesMap: Record<string, unknown>,
    item: CampaignData,
  ): NoahHsmTemplateComponent[] {
    const keys = Object.keys(variablesMap);
    if (keys.length === 0) return [];

    const row = this.mergeNoahCampaignRow(item);
    const cell = (col: string): string => {
      if (!col) return '';
      const v =
        row[col] ??
        row[col.toUpperCase()] ??
        row[col.toLowerCase()];
      return v != null ? String(v).trim() : '';
    };

    const slots: Record<
      'header' | 'body' | 'footer',
      Map<number, string>
    > = {
      header: new Map(),
      body: new Map(),
      footer: new Map(),
    };

    const extras: string[] = [];

    for (const varName of keys) {
      const mapping = variablesMap[varName];
      let textRaw = this.resolveNoahVariablesMapEntryToText(
        mapping,
        row,
        cell,
      );
      const rowDirect =
        row[varName] ??
        row[varName.toLowerCase()] ??
        row[varName.toUpperCase()];
      if (rowDirect != null && String(rowDirect).trim() !== '') {
        textRaw = String(rowDirect).trim();
      }
      const safe = textRaw === '' ? ' ' : textRaw;

      const parsed = this.parseNoahVariableSlotKey(varName);
      if (parsed) {
        slots[parsed.segment].set(parsed.index, safe);
        continue;
      }

      extras.push(safe);
    }

    if (extras.length > 0) {
      let next = 1;
      if (slots.body.size > 0) {
        next = Math.max(...slots.body.keys()) + 1;
      }
      for (const t of extras) {
        while (slots.body.has(next)) next++;
        slots.body.set(next, t);
      }
    }

    const buildBlock = (
      segment: 'header' | 'body' | 'footer',
    ): NoahHsmTemplateComponent | null => {
      const m = slots[segment];
      if (m.size === 0) return null;
      const maxIdx = Math.max(...m.keys());
      const parameters: NoahHsmTextParameter[] = [];
      for (let i = 1; i <= maxIdx; i++) {
        const raw = m.get(i);
        const t =
          raw == null || String(raw).trim() === ''
            ? ' '
            : String(raw).trim();
        parameters.push({ type: 'text', text: t });
      }
      if (parameters.length === 0) return null;
      return { type: segment, parameters };
    };

    const ordered: NoahHsmTemplateComponent[] = [];
    const h = buildBlock('header');
    if (h) ordered.push(h);
    const b = buildBlock('body');
    if (b) ordered.push(b);
    const f = buildBlock('footer');
    if (f) ordered.push(f);
    return ordered;
  }
}
