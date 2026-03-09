import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderCredentials,
  ProviderResponse,
  RetryStrategy,
} from '../base/provider.interface';

interface HsmMessage {
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
    let broker_code = credentials.broker_code || '';
    let customer_code = credentials.customer_code || '';
    const template_code = credentials.template_code || 'default'; // Pode ser configurado depois

    // LOGICA DE EXTRAÇÃO DE TEMPLATE CORRIGIDA
    let final_template_code = template_code;

    // Tenta extrair template_code, broker_code, customer_code e variables_map dos dados
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
            broker_code = parsed.broker_code;
            this.logger.log(`🏢 [WhatsApp Ótima] broker_code extraído da campanha: ${broker_code}`);
          }
          if (parsed.customer_code) {
            customer_code = parsed.customer_code;
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

    // Formata mensagens para o formato da API Ótima
    const messages: HsmMessage[] = data.map((item) => {
      const phone = this.normalizePhoneNumber(item.telefone);

      // Resolve variables dynamically from the variables_map
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
        // Legacy fallback: map nome -> -var1-
        resolvedVariables['-var1-'] = item.nome ?? '';
      }

      this.logger.debug(`📋 [WhatsApp Ótima] Variables for ${phone}: ${JSON.stringify(resolvedVariables)}`);

      const message: HsmMessage = {
        phone: phone,
        document: item.cpf_cnpj?.replace(/\D/g, ''), // Remove caracteres não numéricos
        extra_fields: {
          nome: item.nome,
          id_carteira: item.idgis_ambiente,
          idcob_contrato: item.idcob_contrato,
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
}
