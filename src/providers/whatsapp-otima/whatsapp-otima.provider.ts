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

interface HsmMessage {
  date?: string;
  document?: string;
  extra_fields?: Record<string, any>;
  fallback?: {
    type?: string;
    message?: string;
  };
  /** Contrato API Ótima bulk HSM: campo obrigatório `whatsapp` (não `phone`). */
  whatsapp: string;
  url_callback_mo?: string;
  url_callback_status?: string;
  variables?: Record<string, any>;
}

interface WhatsAppOtimaPayload {
  broker_code: string;
  customer_code: string;
  messages: HsmMessage[];
  template_code: string;
}

@Injectable()
export class WhatsappOtimaProvider extends BaseProvider {
  private readonly API_URL = 'https://services.otima.digital/v1/whatsapp/bulk/message/hsm';

  constructor(httpService: HttpService) {
    super(httpService, 'WhatsappOtimaProvider');
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    this.logger.log(`🌐 [WhatsApp Ótima] Iniciando envio de ${data.length} mensagens`);

    // Valida credenciais
    if (!this.validateCredentials(credentials)) {
      throw new Error('Credenciais inválidas para WhatsApp Ótima');
    }

    const token = credentials.token || credentials.authorization;
    /** Na API Ótima bulk HSM, broker_code = telefone do remetente (campo `code` de GET /v1/whatsapp/credential), não nome da credencial. */
    let broker_code = this.normalizeOtimaWppBrokerCode(credentials.broker_code || '');
    let customer_code = String(credentials.customer_code || '').trim();
    const template_code = credentials.template_code || 'default'; // Pode ser configurado depois

    // LOGICA DE EXTRAÇÃO DE TEMPLATE CORRIGIDA
    let final_template_code = template_code;

    // Extrai metadados GLOBAIS da campanha do JSON da `mensagem` (template_code,
    // broker_code, customer_code, variables_map). Esses 4 são iguais para todas as
    // linhas, então basta olhar a primeira. As `variables` resolvidas (por linha)
    // são lidas DENTRO do `data.map(...)` mais abaixo, item por item.
    let variables_map: Record<string, { type: 'field' | 'text'; value: string }> | null = null;
    if (data.length > 0 && data[0].mensagem && typeof data[0].mensagem === 'string') {
      try {
        if (data[0].mensagem.trim().startsWith('{')) {
          const parsed = JSON.parse(data[0].mensagem);
          if (parsed.template_code) {
            final_template_code = parsed.template_code;
            this.logger.log(`📝 [WhatsApp Ótima] Usando template selecionado na campanha: ${final_template_code}`);
          }
          if (parsed.broker_code) {
            broker_code = this.normalizeOtimaWppBrokerCode(parsed.broker_code);
            this.logger.log(`🏢 [WhatsApp Ótima] broker_code (remetente / code credential) da campanha: ${broker_code}`);
          }
          if (parsed.customer_code) {
            customer_code = String(parsed.customer_code).trim();
            this.logger.log(`👤 [WhatsApp Ótima] customer_code extraído da campanha: ${customer_code}`);
          }
          if (parsed.variables_map && typeof parsed.variables_map === 'object') {
            variables_map = parsed.variables_map;
            this.logger.log(`🗺️ [WhatsApp Ótima] variables_map encontrado: ${JSON.stringify(variables_map)}`);
          }
        }
      } catch (e) {
        this.logger.warn(`⚠️ [WhatsApp Ótima] Falha ao parsear mensagem JSON para extrair template: ${e.message}`);
      }
    }

    if (!broker_code || broker_code.length < 10) {
      throw new Error(
        'broker_code WhatsApp Ótima inválido ou ausente: deve ser o número do remetente retornado em GET /v1/whatsapp/credential (campo `code`). Selecione o remetente na campanha; não use o nome da credencial nem o broker de RCS.',
      );
    }

    // Formata mensagens para o formato da API Ótima
    const messages: HsmMessage[] = data.map((item) => {
      const whatsapp = this.normalizePhoneForOtimaHsm(item.telefone);

      // 1) Lê as `variables` JÁ resolvidas pelo PHP por linha — fonte primária e
      //    confiável (PHP tem acesso direto a TODAS as colunas do CSV/base, não só
      //    aos 6 campos que a REST do WP devolve).
      // 2) `item.variables` (REST do WP) — fallback para fluxos antigos.
      // 3) `item[mapping.value]` (raiz) — último recurso para fluxos legados.
      let lineVariablesFromMessage: Record<string, string> | null = null;
      if (typeof item.mensagem === 'string' && item.mensagem.trim().startsWith('{')) {
        try {
          const parsedLine = JSON.parse(item.mensagem);
          if (parsedLine?.variables && typeof parsedLine.variables === 'object') {
            lineVariablesFromMessage = parsedLine.variables as Record<string, string>;
          }
        } catch {
          // Silencioso: a `mensagem` pode não ser JSON em fluxos legados.
        }
      }

      const itemVariables = (item as { variables?: Record<string, unknown> }).variables ?? {};
      const lookupField = (fieldName: string): string => {
        const fromLine = lineVariablesFromMessage?.[fieldName];
        if (fromLine != null && fromLine !== '') return String(fromLine);
        const fromItemVars = itemVariables[fieldName];
        if (fromItemVars != null && fromItemVars !== '') return String(fromItemVars);
        const fromRoot = (item as Record<string, unknown>)[fieldName];
        if (fromRoot != null && fromRoot !== '') return String(fromRoot);
        return '';
      };

      // Variáveis no formato da documentação Ótima: chaves alinhadas ao template (ex.: {{1}} → parâmetro nomeado como "nome")
      const resolvedVariables: Record<string, string> = {};
      if (variables_map) {
        for (const [varName, mapping] of Object.entries(variables_map)) {
          if (mapping.type === 'field') {
            // Cascata: linha-resolvida (PHP) → item.variables → raiz do item
            resolvedVariables[varName] = lookupField(String(mapping.value ?? ''));
          } else {
            resolvedVariables[varName] = String(mapping.value ?? '');
          }
        }
      } else if (lineVariablesFromMessage) {
        // Sem variables_map mas com variables resolvidas pelo PHP — usa direto.
        for (const [k, v] of Object.entries(lineVariablesFromMessage)) {
          resolvedVariables[k] = String(v ?? '');
        }
      } else {
        resolvedVariables['nome'] = item.nome ?? '';
      }

      this.logger.debug(
        `📋 [WhatsApp Ótima] Variables for ${whatsapp}: ${JSON.stringify(resolvedVariables)}`,
      );

      const rawCpfForBot =
        item.variables?.cpf ??
        item.variables?.CPF ??
        item.variables?.document ??
        item.cpf_cnpj;
      const cpfBotExtras = formatCpfForBot(rawCpfForBot);

      const message: HsmMessage = {
        whatsapp,
        document: item.cpf_cnpj?.replace(/\D/g, ''), // Remove caracteres não numéricos
        extra_fields: {
          nome: item.nome,
          id_carteira: item.id_carteira ?? item.idgis_ambiente,
          idcob_contrato: item.idcob_contrato,
          ...(cpfBotExtras ?? {}),
        },
        variables: resolvedVariables,
      };

      // Adiciona data de agendamento se fornecida
      if (item.data_cadastro) {
        message.date = item.data_cadastro;
      }

      return message;
    });

    const payload: WhatsAppOtimaPayload = {
      broker_code,
      customer_code,
      messages,
      template_code: final_template_code,
    };

    this.logger.log(`📦 [WhatsApp Ótima] Payload preparado com ${messages.length} mensagens`);
    this.logger.log(`🏢 [WhatsApp Ótima] Broker: ${broker_code}, Customer: ${customer_code}, Template: ${final_template_code}`);
    this.logger.debug(`📋 [WhatsApp Ótima] Payload: ${JSON.stringify(payload, null, 2)}`);

    // Executa requisição com retry
    const result = await this.executeWithRetry(
      async () => {
        this.logger.log(`🌐 [WhatsApp Ótima] Enviando requisição para ${this.API_URL}`);
        this.logger.log(`🔑 [WhatsApp Ótima] Token: ${token ? token.substring(0, 10) + '...' : 'N/A'}`);

        const response = await this.httpService.axiosRef.post(this.API_URL, payload, {
          headers: {
            'Content-Type': 'application/json',
            authorization: String(token).trim(),
          },
          timeout: 60000,
        });

        this.logger.log(`✅ [WhatsApp Ótima] Resposta recebida: ${response.status} ${response.statusText}`);
        this.logger.debug(`📄 [WhatsApp Ótima] Response body: ${JSON.stringify(response.data)}`);

        return {
          success: true,
          message: 'Mensagens WhatsApp Ótima enviadas com sucesso',
          data: {
            status: response.status,
            statusText: response.statusText,
            body: response.data,
          },
        };
      },
      this.getRetryStrategy(),
    );

    return result;
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    const token = credentials.token || credentials.authorization;

    if (!token) {
      this.logger.error('❌ [WhatsApp Ótima] Token não fornecido');
      return false;
    }

    this.logger.log('✅ [WhatsApp Ótima] Credenciais válidas');
    return true;
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [1000, 2000, 5000], // 1s, 2s, 5s
    };
  }

  /**
   * Ótima HSM (doc interna): telefone em formato nacional, sem prefixo 55 duplicado.
   */
  /**
   * broker_code no bulk HSM: apenas dígitos do telefone remetente (ex. 5511962188096).
   * Se vier texto tipo nome da credencial, remove letras e fica vazio → validação acima falha.
   */
  private normalizeOtimaWppBrokerCode(raw: string): string {
    if (raw == null || raw === '') {
      return '';
    }
    return String(raw).replace(/\D/g, '');
  }

  private normalizePhoneForOtimaHsm(telefone: string): string {
    if (telefone == null || telefone === '') {
      return '';
    }
    let n = String(telefone)
      .trim()
      .replace(/^\+/, '')
      .replace(/[\s()\-]/g, '');
    if (n.startsWith('55') && n.length > 11) {
      n = n.slice(2);
    }
    return n;
  }
}
