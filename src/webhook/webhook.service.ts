import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { wordpressConfig } from '../config/wordpress.config';

export interface WebhookStatusPayload {
  agendamento_id: string;
  status: 'enviado' | 'erro_envio' | 'erro_credenciais' | 'erro_validacao' | 'processando' | 'mkc_executado' | 'mkc_erro';
  resposta_api?: string;
  data_disparo?: string;
  total_enviados?: number;
  total_falhas?: number;
  provider: string;
}

@Injectable()
export class WebhookService {
  private readonly logger = new Logger(WebhookService.name);

  constructor(private readonly httpService: HttpService) {}

  async sendStatusUpdate(payload: WebhookStatusPayload): Promise<boolean> {
    try {
      const url = wordpressConfig.endpoints.webhookStatus();
      this.logger.log(`Sending webhook to WordPress: ${url}`);
      this.logger.log(`Payload: ${JSON.stringify(payload)}`);

      const response = await firstValueFrom(
        this.httpService.post(
          url,
          payload,
          {
            headers: {
              'Content-Type': 'application/json',
              'X-API-KEY': wordpressConfig.apiKey,
            },
            timeout: 10000,
          },
        ),
      );

      if (response.status === 200) {
        this.logger.log(`✅ Webhook sent successfully for ${payload.agendamento_id}`);
        return true;
      }

      this.logger.warn(`⚠️ Webhook returned status ${response.status}`);
      return false;
    } catch (error: any) {
      this.logger.error(
        `❌ Failed to send webhook: ${error.message}`,
        error.stack,
      );
      // Não lança erro para não quebrar o fluxo principal
      return false;
    }
  }

  mapCampaignStatusToWordPress(status: string): WebhookStatusPayload['status'] {
    const statusMap: Record<string, WebhookStatusPayload['status']> = {
      COMPLETED: 'enviado',
      FAILED: 'erro_envio',
      PROCESSING: 'processando',
      QUEUED: 'processando',
      PENDING: 'processando',
      CANCELLED: 'erro_envio',
    };

    return statusMap[status] || 'erro_envio';
  }
}

