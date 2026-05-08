import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderCredentials,
  ProviderResponse,
  RetryStrategy,
} from '../base/provider.interface';
import { formatCpfForBot } from '../../utils/cpf-formatter.util';

interface RCSTemplateMessage {
  date?: string;
  document?: string;
  extra_fields?: Record<string, any>;
  fallback?: {
    type?: string;
    message?: string;
  };
  phone: string;
  url_callback_mo?: string;
  url_callback_status?: string;
  variables?: Record<string, any>;
}

interface RCSOtimaPayload {
  broker_code: string;
  customer_code: string;
  template_code: string;
  messages: RCSTemplateMessage[];
}

@Injectable()
export class RcsOtimaProvider extends BaseProvider {
  private readonly API_URL = 'https://services.otima.digital/v1/rcs/bulk/message/template';

  constructor(httpService: HttpService) {
    super(httpService, 'RcsOtimaProvider');
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    this.logger.log(`🌐 [RCS Ótima] Iniciando envio de ${data.length} mensagens`);

    // Valida credenciais
    if (!this.validateCredentials(credentials)) {
      throw new Error('Credenciais inválidas para RCS Ótima');
    }

    const token = credentials.token || credentials.authorization;
    let broker_code = credentials.broker_code || '';
    let template_code = credentials.template_code || '';

    // Parse variables_map from campaign JSON (set by the frontend variable mapper)
    let variables_map: Record<string, { type: 'field' | 'text'; value: string }> | null = null;
    if (data.length > 0 && data[0].mensagem && typeof data[0].mensagem === 'string') {
      try {
        if (data[0].mensagem.trim().startsWith('{')) {
          const parsed = JSON.parse(data[0].mensagem);
          if (parsed.broker_code) {
            broker_code = parsed.broker_code;
            this.logger.log(`🏢 [RCS Ótima] broker_code extraído da campanha: ${broker_code}`);
          }
          if (parsed.template_code) {
            template_code = parsed.template_code;
            this.logger.log(`📝 [RCS Ótima] template_code extraído da campanha: ${template_code}`);
          }
          if (parsed.variables_map && typeof parsed.variables_map === 'object') {
            variables_map = parsed.variables_map;
            this.logger.log(`🗺️ [RCS Ótima] variables_map encontrado: ${JSON.stringify(variables_map)}`);
          }
        }
      } catch (e) {
        const msg = e instanceof Error ? e.message : String(e);
        this.logger.warn(`⚠️ [RCS Ótima] Falha ao parsear mensagem JSON: ${msg}`);
      }
    }

    // customer_code = id_carteira de cada contato. API aceita um customer_code por requisição.
    // Agrupa mensagens por id_carteira (customer_code) para fazer uma requisição por carteira.
    const groups = new Map<string, CampaignData[]>();
    for (const item of data) {
      let customer_code = '';
      if (item.mensagem && typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')) {
        try {
          const parsed = JSON.parse(item.mensagem);
          customer_code = String(parsed.customer_code ?? '').trim();
        } catch {
          // ignore
        }
      }
      if (!customer_code && item.id_carteira) {
        customer_code = String(item.id_carteira).trim();
      }
      const key = customer_code || '__default__';
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key)!.push(item);
    }

    /** Mesmo contrato que WhatsApp Ótima: carteira padrão nas credenciais quando a linha não traz id_carteira. */
    const credCustomerFallback = String(
      credentials.customer_code ?? (credentials as { otima_rcs_customer_code?: string }).otima_rcs_customer_code ?? '',
    ).trim();

    let lastResult: any = null;
    for (const [custKey, groupData] of groups) {
      let customer_code = custKey === '__default__' ? '' : custKey;
      if (!customer_code) {
        customer_code = credCustomerFallback;
      }
      if (!customer_code) {
        this.logger.warn(
          `⚠️ [RCS Ótima] Mensagens sem id_carteira/customer_code e sem customer_code nas credenciais serão ignoradas: ${groupData.length}`,
        );
        continue;
      }

      let firstRowMappedVariablesPreview: Record<string, string> | undefined;
      let firstRowSubstitutionPreview: string | undefined;

      const messages: RCSTemplateMessage[] = groupData.map((item, rowIndex) => {
        const phone = this.normalizePhoneNumber(item.telefone);

        // `variables` já resolvidas por linha no PHP (Campanha por Arquivo / bases)
        // — fonte primária. A REST do WP não replica colunas extras (extra_1, cpf, …)
        // na raiz do objeto; sem isso o lookup antigo `(item as any)[campo]`
        // devolvia undefined e a Ótima recebia strings vazias → template mostrava o
        // placeholder literal (-var1-).
        let lineVariablesFromMessage: Record<string, string> | null = null;
        let originalMessageTemplate = '';
        const itemVariables = (item as { variables?: Record<string, unknown> }).variables ?? {};
        const itemAsRecord = item as unknown as Record<string, unknown>;

        // REST pode enviar `extra_fields` ou `extraFields` (colunas da planilha).
        const fromWpRow = this.normalizeRowExtraFields(
          itemAsRecord.extra_fields ??
            itemAsRecord.extraFields ??
            (item as { extra_fields?: unknown }).extra_fields,
        );
        const rowExtraFields: Record<string, unknown> = {};

        if (typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')) {
          try {
            const parsedLine = JSON.parse(item.mensagem);
            if (parsedLine?.variables && typeof parsedLine.variables === 'object') {
              lineVariablesFromMessage = parsedLine.variables as Record<string, string>;
            }
            if (typeof parsedLine?.original_message === 'string') {
              originalMessageTemplate = parsedLine.original_message;
            }
            // JSON primeiro, depois a linha do WP — dados da REST têm precedência sobre metadados.
            Object.assign(
              rowExtraFields,
              this.normalizeRowExtraFields(parsedLine?.extra_fields),
              fromWpRow,
            );
          } catch {
            Object.assign(rowExtraFields, fromWpRow);
          }
        } else {
          Object.assign(rowExtraFields, fromWpRow);
        }

        /**
         * De/Para coluna → valor: a planilha chega em `extra_fields` / `extraFields`, não na raiz.
         * Ordem: PHP (linha) → extra_fields do contato → merged (JSON msg + WP) → item.variables → raiz (case-insensitive).
         */
        const lookupField = (chaveMapeada: string): string => {
          const key = String(chaveMapeada ?? '').trim();
          if (!key) {
            return '';
          }

          const fromLine = lineVariablesFromMessage?.[key];
          if (fromLine != null && fromLine !== '') {
            return String(fromLine);
          }

          const extraSolo = this.normalizeRowExtraFields(
            itemAsRecord.extra_fields ??
              itemAsRecord.extraFields ??
              (item as { extra_fields?: unknown }).extra_fields,
          );
          const fromExtraOnly = this.pickFromRecordCaseInsensitive(extraSolo, key);
          if (fromExtraOnly !== '') {
            return fromExtraOnly;
          }

          const fromMergedExtras = this.pickFromRecordCaseInsensitive(rowExtraFields, key);
          if (fromMergedExtras !== '') {
            return fromMergedExtras;
          }

          const fromItemVars = itemVariables[key];
          if (fromItemVars != null && fromItemVars !== '') {
            return String(fromItemVars);
          }

          const fromRoot = this.pickFromRecordCaseInsensitive(itemAsRecord, key);
          if (fromRoot !== '') {
            return fromRoot;
          }

          return '';
        };

        const resolvedVariables: Record<string, string> = {};
        if (variables_map) {
          for (const [varName, mapping] of Object.entries(variables_map)) {
            const key = this.rcsTemplateVarKey(varName);
            if (mapping.type === 'field') {
              resolvedVariables[key] = lookupField(String(mapping.value ?? ''));
            } else {
              resolvedVariables[key] = String(mapping.value ?? '');
            }
          }
        } else if (lineVariablesFromMessage) {
          for (const [k, v] of Object.entries(lineVariablesFromMessage)) {
            resolvedVariables[this.rcsTemplateVarKey(k)] = String(v ?? '');
          }
        } else {
          resolvedVariables[this.rcsTemplateVarKey('var1')] = String(item.nome ?? '');
        }

        // Completa placeholders nominais / varN a partir das chaves já presentes em
        // `extra_fields` (planilha) quando o `variables_map` não cobriu ou veio vazio.
        this.backfillVariablesFromExtraFields(resolvedVariables, rowExtraFields);

        // Ótima: alguns templates aceitam chave sem hífens (`nome`) além de `-varN-`.
        this.emitVariableAliases(resolvedVariables, variables_map);

        const idCarteira = item.id_carteira ?? item.idgis_ambiente ?? '';
        const cpfFromItemVars =
          item.variables?.cpf ??
          item.variables?.CPF ??
          item.variables?.document ??
          '';
        const cpfFromExtraFields =
          this.pickFromRecordCaseInsensitive(rowExtraFields, 'CPF_PADRAO') ||
          this.pickFromRecordCaseInsensitive(rowExtraFields, 'cpf') ||
          this.pickFromRecordCaseInsensitive(rowExtraFields, 'CPF') ||
          '';
        const rawCpfForBot = cpfFromItemVars || cpfFromExtraFields || item.cpf_cnpj;
        const cpfBotExtras = formatCpfForBot(rawCpfForBot);

        const serializedExtras = this.serializeExtraFieldsForOtimaPayload(rowExtraFields);

        const nomeParaPayload =
          (item.nome != null && String(item.nome).trim() !== ''
            ? String(item.nome).trim()
            : '') ||
          this.pickFromRecordCaseInsensitive(rowExtraFields, 'nome') ||
          (typeof serializedExtras.nome === 'string' ? serializedExtras.nome : '');

        const message: RCSTemplateMessage = {
          phone,
          document: item.cpf_cnpj?.replace(/\D/g, ''),
          extra_fields: {
            ...serializedExtras,
            nome: nomeParaPayload,
            id_carteira: idCarteira,
            idcob_contrato: item.idcob_contrato,
            ...(cpfBotExtras ?? {}),
          },
          variables: resolvedVariables,
        };

        const substitutionPreview = originalMessageTemplate
          ? this.previewTemplateSubstitutions(
              originalMessageTemplate,
              resolvedVariables,
              rowExtraFields,
            )
          : '';

        if (rowIndex === 0) {
          firstRowMappedVariablesPreview = { ...resolvedVariables };
          firstRowSubstitutionPreview = substitutionPreview || undefined;
        }

        if (item.data_cadastro) message.date = item.data_cadastro;
        return message;
      });

      const payload: RCSOtimaPayload = {
        broker_code,
        customer_code,
        template_code,
        messages,
      };

      this.logger.log(`🔖 [RCS Ótima] template_code: ${template_code}`);
      this.logger.log(`📦 [RCS Ótima] Enviando ${messages.length} mensagens (customer_code=${customer_code})`);
      this.logger.log(`🏢 [RCS Ótima] Broker: ${broker_code}, Customer: ${customer_code}`);
      // Debug obrigatório p/ validar substituição de placeholders (Campanha por Arquivo):
      // inspecionar 1ª linha do lote antes do HTTP (valores reais vs literais -varN-).
      if (messages[0]) {
        this.logger.warn(
          `[RCS Ótima] DEBUG amostra (1º contato deste customer_code): ${JSON.stringify(messages[0])}`,
        );
      }
      const validationPayload: Record<string, unknown> = {
        variables_map_keys: variables_map ? Object.keys(variables_map) : [],
        mapped_variables_ready: firstRowMappedVariablesPreview ?? {},
        substitution_preview_linha_1:
          firstRowSubstitutionPreview ??
          '(sem original_message para preview local — confira apenas `mapped_variables_ready` e payload.)',
      };
      this.logger.warn(
        `[RCS Ótima] Variáveis mapeadas e prontas para envio: ${JSON.stringify(validationPayload)}`,
      );
      this.logger.debug(`📋 [RCS Ótima] Payload: ${JSON.stringify(payload, null, 2)}`);

      lastResult = await this.executeWithRetry(
        async () => {
          this.logger.log(`🌐 [RCS Ótima] Enviando requisição para ${this.API_URL}`);
          this.logger.log(`🔑 [RCS Ótima] Token: ${token ? token.substring(0, 10) + '...' : 'N/A'}`);

          const response = await this.httpService.axiosRef.post(
            this.API_URL,
            payload,
            {
              headers: {
                'Content-Type': 'application/json',
                'authorization': token,
              },
              timeout: 60000, // 60 segundos
            },
          );

          this.logger.log(`✅ [RCS Ótima] Resposta recebida: ${response.status} ${response.statusText}`);
          this.logger.debug(`📄 [RCS Ótima] Response body: ${JSON.stringify(response.data)}`);

          return {
            success: true,
            message: 'Mensagens RCS Ótima enviadas com sucesso',
            data: {
              status: response.status,
              statusText: response.statusText,
              body: response.data,
            },
          };
        },
        this.getRetryStrategy(),
      );

    }

    if (!lastResult) {
      throw new Error('Nenhuma mensagem com id_carteira (customer_code) para envio. Verifique se os contatos têm carteira vinculada.');
    }
    return lastResult;
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const token = credentials.token || credentials.authorization;

    if (!token) {
      this.logger.error('❌ [RCS Ótima] Token não fornecido');
      return false;
    }

    this.logger.log('✅ [RCS Ótima] Credenciais válidas');
    return true;
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000], // 1s, 2s, 5s
    };
  }

  /**
   * A API RCS Ótima espera chaves do objeto `variables` no formato `-nome-` (ex.: `-var1-`).
   * O painel pode mapear `var1` ou já enviar `-var1-`; normalizamos de forma idempotente.
   */
  private rcsTemplateVarKey(varName: string): string {
    const t = String(varName).trim();
    if (t.length >= 3 && t.startsWith('-') && t.endsWith('-')) {
      return t;
    }
    return `-${t}-`;
  }

  private escapeRegExp(s: string): string {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  private normalizeRowExtraFields(raw: unknown): Record<string, unknown> {
    if (raw == null) {
      return {};
    }
    if (typeof raw === 'string') {
      try {
        const parsed = JSON.parse(raw);
        return this.normalizeRowExtraFields(parsed);
      } catch {
        return {};
      }
    }
    if (typeof raw === 'object' && !Array.isArray(raw)) {
      return { ...(raw as Record<string, unknown>) };
    }
    return {};
  }

  private pickFromRecordCaseInsensitive(
    extra: Record<string, unknown>,
    fieldName: string,
  ): string {
    if (!fieldName) {
      return '';
    }
    const direct = extra[fieldName];
    if (direct !== null && direct !== undefined && String(direct).trim() !== '') {
      return String(direct);
    }
    const want = fieldName.toLowerCase();
    for (const [k, val] of Object.entries(extra)) {
      if (k.toLowerCase() === want && val !== null && val !== undefined && String(val).trim() !== '') {
        return String(val);
      }
    }
    return '';
  }

  private serializeExtraFieldsForOtimaPayload(
    row: Record<string, unknown>,
  ): Record<string, string | number> {
    const out: Record<string, string | number> = {};
    for (const [k, val] of Object.entries(row)) {
      if (val === null || val === undefined) {
        continue;
      }
      if (typeof val === 'number' && Number.isFinite(val)) {
        out[k] = val;
      } else {
        out[k] = String(val);
      }
    }
    return out;
  }

  /**
   * Preenche chaves `-coluna-` a partir do que já veio na planilha (`extra_fields`)
   * quando não houve valor vindo do `variables_map` / PHP.
   */
  private backfillVariablesFromExtraFields(
    resolvedVariables: Record<string, string>,
    extra: Record<string, unknown>,
  ): void {
    for (const [col, raw] of Object.entries(extra)) {
      if (raw === null || raw === undefined || String(raw).trim() === '') {
        continue;
      }
      const keyHyphen = this.rcsTemplateVarKey(col);
      const val = String(raw);
      const cur = resolvedVariables[keyHyphen]?.trim?.() ?? '';
      if (!cur) {
        resolvedVariables[keyHyphen] = val;
      }
    }
  }

  /**
   * Replica cada `-token-` como `token` (doc interna Ótima às vezes usa chave sem hífene).
   * Não sobrescreve chaves já preenchidas.
   */
  private emitVariableAliases(
    resolvedVariables: Record<string, string>,
    variablesMap: Record<string, { type: 'field' | 'text'; value: string }> | null,
  ): void {
    const toAdd: Record<string, string> = {};
    for (const [k, val] of Object.entries(resolvedVariables)) {
      if (!val) {
        continue;
      }
      if (!(k.startsWith('-') && k.endsWith('-')) || k.length < 3) {
        continue;
      }
      const bare = k.slice(1, -1);
      if (bare && resolvedVariables[bare] === undefined) {
        toAdd[bare] = val;
      }
    }

    // Nomes declarados no mapper (keys do map) mesmo quando o template usa apenas varN.
    if (variablesMap) {
      for (const varName of Object.keys(variablesMap)) {
        const hk = this.rcsTemplateVarKey(varName);
        const val = resolvedVariables[hk];
        if (!val || varName === hk || varName.startsWith('-')) {
          continue;
        }
        const rawName = varName.startsWith('-') && varName.endsWith('-')
          ? varName.slice(1, -1)
          : varName;
        if (rawName && resolvedVariables[rawName] === undefined) {
          toAdd[rawName] = val;
        }
      }
    }

    Object.assign(resolvedVariables, toAdd);
  }

  /** Texto apenas para logs (a API Ótima continua usando `variables` conforme contrato bulk). */
  private previewTemplateSubstitutions(
    template: string,
    resolvedVariables: Record<string, string>,
    extraColumns: Record<string, unknown>,
  ): string {
    let preview = template;
    for (const [k, val] of Object.entries(resolvedVariables)) {
      if (!val) {
        continue;
      }
      preview = preview.replace(new RegExp(this.escapeRegExp(k), 'g'), val);
      if (k.startsWith('-') && k.endsWith('-')) {
        const bare = k.slice(1, -1);
        if (bare) {
          preview = preview.replace(new RegExp(this.escapeRegExp(`-${bare}-`), 'gi'), val);
        }
      }
    }
    for (const [col, raw] of Object.entries(extraColumns)) {
      if (raw === null || raw === undefined) {
        continue;
      }
      const s = String(raw);
      if (!s.trim()) {
        continue;
      }
      preview = preview.replace(
        new RegExp(this.escapeRegExp(`-${col}-`), 'gi'),
        s,
      );
    }
    return preview;
  }
}
