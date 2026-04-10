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
        this.logger.warn(`⚠️ [RCS Ótima] Falha ao parsear mensagem JSON: ${e.message}`);
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

    let lastResult: any = null;
    for (const [custKey, groupData] of groups) {
      const customer_code = custKey === '__default__' ? '' : custKey;
      if (!customer_code) {
        this.logger.warn(`⚠️ [RCS Ótima] Mensagens sem id_carteira/customer_code serão ignoradas: ${groupData.length}`);
        continue;
      }

      const messages: RCSTemplateMessage[] = groupData.map((item) => {
        const phone = this.normalizePhoneNumber(item.telefone);
        const resolvedVariables: Record<string, string> = {};
        if (variables_map) {
          for (const [varName, mapping] of Object.entries(variables_map)) {
            const hyphenatedVarName = `-${varName}-`;
            if (mapping.type === 'field') {
              resolvedVariables[hyphenatedVarName] = (item as any)[mapping.value] ?? '';
            } else {
              resolvedVariables[hyphenatedVarName] = mapping.value ?? '';
            }
          }
        } else {
          resolvedVariables['-var1-'] = item.nome ?? '';
        }

        const idCarteira = item.id_carteira ?? item.idgis_ambiente ?? '';
        const rawCpfForBot =
          item.variables?.cpf ??
          item.variables?.CPF ??
          item.variables?.document ??
          item.cpf_cnpj;
        const cpfBotExtras = formatCpfForBot(rawCpfForBot);

        const message: RCSTemplateMessage = {
          phone,
          document: item.cpf_cnpj?.replace(/\D/g, ''),
          extra_fields: {
            nome: item.nome,
            id_carteira: idCarteira,
            idcob_contrato: item.idcob_contrato,
            ...(cpfBotExtras ?? {}),
          },
          variables: resolvedVariables,
        };
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
}
