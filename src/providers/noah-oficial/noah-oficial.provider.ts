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
 * Bloco `components` para template HSM — `type: "body"` + `parameters` ordenados ({{1}}, {{2}}, …).
 */
export type NoahHsmBodyComponent = {
  type: 'body';
  parameters: NoahHsmTextParameter[];
};

/**
 * Payload raiz POST `/v1/api/external/:apiId/send-template` (documentação NOAH).
 */
export type NoahSendTemplatePayload = {
  number: string;
  channelId: number;
  templateId?: number;
  templateName: string;
  language: string;
  components: NoahHsmBodyComponent[];
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
   * Garante estrutura exata: `[{ "type": "body", "parameters": [{ "type": "text", "text": "..." }] }]`.
   * Normaliza `BODY` → `body` e descarta entradas inválidas.
   */
  private normalizeNoahTemplateComponents(
    components: unknown,
  ): NoahHsmBodyComponent[] {
    if (!Array.isArray(components)) return [];
    const out: NoahHsmBodyComponent[] = [];

    for (const c of components) {
      if (!c || typeof c !== 'object') continue;
      const comp = c as Record<string, unknown>;
      const typ = String(comp.type ?? comp.Type ?? '').toLowerCase();
      if (typ !== 'body') continue;

      const rawParams = comp.parameters;
      if (!Array.isArray(rawParams)) continue;

      const parameters: NoahHsmTextParameter[] = [];
      for (const p of rawParams) {
        if (!p || typeof p !== 'object') continue;
        const pr = p as Record<string, unknown>;
        const pType = String(pr.type ?? 'text').toLowerCase();
        const text = pr.text != null ? String(pr.text) : '';
        if (pType === 'text') {
          parameters.push({ type: 'text', text });
        }
      }

      if (parameters.length > 0) {
        out.push({ type: 'body', parameters });
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

    let componentsRaw = Array.isArray(parsed.components)
      ? parsed.components
      : [];

    if (
      (!componentsRaw || componentsRaw.length === 0) &&
      parsed.variables_map &&
      typeof parsed.variables_map === 'object' &&
      !Array.isArray(parsed.variables_map)
    ) {
      const built = this.buildNoahComponentsFromVariablesMap(
        parsed.variables_map as Record<string, unknown>,
        item,
      );
      if (built.length > 0) {
        componentsRaw = built;
      }
    }

    const components = this.normalizeNoahTemplateComponents(componentsRaw);

    if (!channelIdNum || !templateName || String(templateName).trim() === '') {
      throw new Error(
        'Template NOAH requer channelId (remetente) e templateName — verifique o JSON da mensagem e o broker_code na fila.',
      );
    }

    const url = this.resolveNoahSendTemplateUrl(baseUrl);

    const payload: NoahSendTemplatePayload = {
      number,
      channelId: channelIdNum,
      templateName: String(templateName).trim(),
      language: String(language),
      components,
      externalKey,
    };

    const tid =
      templateIdRaw !== undefined && templateIdRaw !== null && templateIdRaw !== ''
        ? Number(templateIdRaw)
        : NaN;
    if (!Number.isNaN(tid) && tid > 0) {
      payload.templateId = tid;
    }

    this.logger.debug(
      `NOAH send-template → ${url} | channelId=${channelIdNum} | externalKey=${externalKey} | number=${number}`,
    );

    await this.executeWithRetry(
      async () => {
        const result = await firstValueFrom(
          this.httpService.post<unknown>(url, payload, {
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
   * Converte `variables_map` do painel (`{ type, value }` por chave `1`, `2`, …) em um único bloco `body.parameters` na ordem {{1}}, {{2}}.
   */
  private buildNoahComponentsFromVariablesMap(
    variablesMap: Record<string, unknown>,
    item: CampaignData,
  ): NoahHsmBodyComponent[] {
    const keys = Object.keys(variablesMap);
    if (keys.length === 0) return [];

    const allNumeric = keys.every((k) => /^\d+$/.test(k));
    const ordered = allNumeric
      ? [...keys].sort((a, b) => parseInt(a, 10) - parseInt(b, 10))
      : keys;

    const bodyParams: NoahHsmTextParameter[] = [];
    const row = item as unknown as Record<string, unknown>;

    for (const varName of ordered) {
      const mapping = variablesMap[varName];
      let text = '';
      if (
        mapping &&
        typeof mapping === 'object' &&
        !Array.isArray(mapping) &&
        'type' in mapping &&
        'value' in mapping
      ) {
        const m = mapping as { type: string; value: string };
        if (m.type === 'field') {
          const col = String(m.value ?? '');
          text = String(row[col] ?? row[col.toUpperCase()] ?? '');
        } else {
          text = String(m.value ?? '');
        }
      } else if (typeof mapping === 'string' && mapping !== '') {
        text = String(row[mapping] ?? row[mapping.toUpperCase()] ?? '');
      }
      bodyParams.push({ type: 'text', text });
    }

    if (bodyParams.length === 0) return [];
    return [{ type: 'body', parameters: bodyParams }];
  }
}
