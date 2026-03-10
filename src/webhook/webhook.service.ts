import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { wordpressConfig } from '../config/wordpress.config';

export interface WebhookStatusPayload {
  agendamento_id: string;
  status: 'enviado' | 'erro_envio' | 'erro_credenciais' | 'erro_validacao' | 'processando' | 'iniciado' | 'erro_inicio' | 'mkc_executado' | 'mkc_erro';
  resposta_api?: string;
  data_disparo?: string;
  total_enviados?: number;
  total_falhas?: number;
  provider: string;
}

const BATCH_SIZE = 50;
const MAX_RETRIES = 3;
const INITIAL_RETRY_DELAY_MS = 1000;
const REQUEST_TIMEOUT_MS = 30000;
const DELAY_BETWEEN_BATCHES_MS = 500;
const MAX_CONCURRENT_REQUESTS = 3;

@Injectable()
export class WebhookService {
  private readonly logger = new Logger(WebhookService.name);
  private activeRequests = 0;
  private readonly requestQueue: Array<() => Promise<void>> = [];

  constructor(private readonly httpService: HttpService) {}

  async sendStatusUpdate(payload: WebhookStatusPayload): Promise<boolean> {
    return this.enqueueRequest(async () => {
      return this.sendSingleWithRetry(payload);
    });
  }

  async sendBulkStatusUpdate(payloads: WebhookStatusPayload[]): Promise<{ succeeded: number; failed: number }> {
    if (!payloads.length) return { succeeded: 0, failed: 0 };

    this.logger.log(`📦 Sending bulk webhook: ${payloads.length} updates in chunks of ${BATCH_SIZE}`);

    let succeeded = 0;
    let failed = 0;

    const chunks = this.chunkArray(payloads, BATCH_SIZE);

    for (let i = 0; i < chunks.length; i++) {
      const chunk = chunks[i];
      this.logger.log(`📦 Processing chunk ${i + 1}/${chunks.length} (${chunk.length} items)`);

      try {
        const ok = await this.enqueueRequest(() => this.sendChunkWithRetry(chunk));
        if (ok) {
          succeeded += chunk.length;
        } else {
          failed += chunk.length;
        }
      } catch {
        failed += chunk.length;
      }

      if (i < chunks.length - 1) {
        await this.sleep(DELAY_BETWEEN_BATCHES_MS);
      }
    }

    this.logger.log(`📦 Bulk webhook complete: ${succeeded} succeeded, ${failed} failed`);
    return { succeeded, failed };
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

  // ---------------------------------------------------------------------------

  private async sendSingleWithRetry(payload: WebhookStatusPayload): Promise<boolean> {
    const url = wordpressConfig.endpoints.webhookStatus();

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      try {
        this.logger.log(
          `Sending webhook (attempt ${attempt}/${MAX_RETRIES}) for ${payload.agendamento_id}`,
        );

        const response = await firstValueFrom(
          this.httpService.post(url, payload, {
            headers: {
              'Content-Type': 'application/json',
              'X-API-KEY': wordpressConfig.apiKey,
            },
            timeout: REQUEST_TIMEOUT_MS,
          }),
        );

        if (response.status === 200) {
          this.logger.log(`✅ Webhook sent successfully for ${payload.agendamento_id}`);
          return true;
        }

        this.logger.warn(`⚠️ Webhook returned status ${response.status}`);
      } catch (error: any) {
        const isRetryable = this.isRetryableError(error);
        this.logger.warn(
          `⚠️ Webhook attempt ${attempt}/${MAX_RETRIES} failed: ${error.message} (retryable: ${isRetryable})`,
        );

        if (!isRetryable || attempt === MAX_RETRIES) {
          this.logger.error(`❌ Webhook failed permanently for ${payload.agendamento_id}: ${error.message}`);
          return false;
        }

        const delay = INITIAL_RETRY_DELAY_MS * Math.pow(2, attempt - 1);
        await this.sleep(delay);
      }
    }

    return false;
  }

  private async sendChunkWithRetry(chunk: WebhookStatusPayload[]): Promise<boolean> {
    const url = wordpressConfig.endpoints.webhookStatus();
    const bulkPayload = { bulk: true, updates: chunk };

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      try {
        this.logger.log(
          `Sending bulk webhook chunk (attempt ${attempt}/${MAX_RETRIES}, ${chunk.length} items)`,
        );

        const response = await firstValueFrom(
          this.httpService.post(url, bulkPayload, {
            headers: {
              'Content-Type': 'application/json',
              'X-API-KEY': wordpressConfig.apiKey,
            },
            timeout: REQUEST_TIMEOUT_MS,
            maxContentLength: 50 * 1024 * 1024,
            maxBodyLength: 50 * 1024 * 1024,
          }),
        );

        if (response.status === 200) {
          this.logger.log(`✅ Bulk webhook chunk sent (${chunk.length} items)`);
          return true;
        }

        this.logger.warn(`⚠️ Bulk webhook returned status ${response.status}`);
      } catch (error: any) {
        const isRetryable = this.isRetryableError(error);
        this.logger.warn(
          `⚠️ Bulk webhook attempt ${attempt}/${MAX_RETRIES} failed: ${error.message}`,
        );

        if (!isRetryable || attempt === MAX_RETRIES) {
          this.logger.error(`❌ Bulk webhook chunk failed permanently (${chunk.length} items)`);
          return false;
        }

        const delay = INITIAL_RETRY_DELAY_MS * Math.pow(2, attempt - 1);
        await this.sleep(delay);
      }
    }
    return false;
  }

  /**
   * Concurrency limiter: ensures at most MAX_CONCURRENT_REQUESTS hit WordPress
   * simultaneously. Excess requests are queued and drained automatically.
   */
  private enqueueRequest<T>(fn: () => Promise<T>): Promise<T> {
    return new Promise<T>((resolve, reject) => {
      const execute = async () => {
        this.activeRequests++;
        try {
          resolve(await fn());
        } catch (err) {
          reject(err);
        } finally {
          this.activeRequests--;
          this.drainQueue();
        }
      };

      if (this.activeRequests < MAX_CONCURRENT_REQUESTS) {
        execute();
      } else {
        this.requestQueue.push(execute);
      }
    });
  }

  private drainQueue(): void {
    while (this.activeRequests < MAX_CONCURRENT_REQUESTS && this.requestQueue.length > 0) {
      const next = this.requestQueue.shift();
      if (next) next();
    }
  }

  private isRetryableError(error: any): boolean {
    if (error.code === 'ECONNRESET' || error.code === 'ETIMEDOUT' || error.code === 'ECONNABORTED') {
      return true;
    }
    const status = error.response?.status;
    if (status === 429 || status === 502 || status === 503 || status === 504 || status === 413) {
      return true;
    }
    return false;
  }

  private chunkArray<T>(arr: T[], size: number): T[][] {
    const chunks: T[][] = [];
    for (let i = 0; i < arr.length; i += size) {
      chunks.push(arr.slice(i, i + size));
    }
    return chunks;
  }

  private sleep(ms: number): Promise<void> {
    return new Promise((r) => setTimeout(r, ms));
  }
}

