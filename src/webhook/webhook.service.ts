import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import axios, { type AxiosResponse } from 'axios';
import { wordpressConfig } from '../config/wordpress.config';

export interface WebhookStatusPayload {
  agendamento_id: string;
  status: 'enviado' | 'erro_envio' | 'erro_credenciais' | 'erro_validacao' | 'processando' | 'iniciado' | 'erro_inicio' | 'mkc_executado' | 'mkc_erro';
  resposta_api?: string;
  /** Linha única ou curta para o painel (ex.: Processado 100% 106/106) — combinada ao PHP em `resposta_api`. */
  mensagem_progresso?: string;
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

  /** Detalhe legível para logs (401 Master Key, 404 agendamento, corpo WP REST, etc.). */
  private formatWpWebhookError(error: unknown, url: string): string {
    const e = error as {
      message?: string;
      code?: string;
      response?: { status?: number; statusText?: string; data?: unknown };
    };
    const parts: string[] = [];
    parts.push(`url=${url}`);
    if (e?.message) {
      parts.push(`message=${e.message}`);
    }
    if (e?.code) {
      parts.push(`code=${e.code}`);
    }
    const st = e?.response?.status;
    if (st != null) {
      parts.push(`http=${st} ${e.response?.statusText ?? ''}`.trim());
    }
    const data = e?.response?.data;
    if (data !== undefined) {
      try {
        const serialized = typeof data === 'string' ? data : JSON.stringify(data);
        parts.push(`body=${serialized.slice(0, 2000)}`);
      } catch {
        parts.push('body=<unserializable>');
      }
    }
    return parts.join(' | ');
  }

  /** Log seguro do body (payload não inclui secrets). */
  private formatPayloadForDebug(payload: WebhookStatusPayload): string {
    try {
      return JSON.stringify(payload, null, 2);
    } catch {
      return String(payload);
    }
  }

  private logWpHttpResponse(context: string, response: AxiosResponse<unknown>): void {
    let bodySnippet: string;
    try {
      const raw =
        typeof response.data === 'string'
          ? response.data
          : JSON.stringify(response.data);
      bodySnippet = raw.length > 4000 ? `${raw.slice(0, 4000)}…(trunc)` : raw;
    } catch {
      bodySnippet = '<unserializable>';
    }
    this.logger.log(
      `[Webhook WP] ${context} RES http=${response.status} ${response.statusText ?? ''} | headers[content-type]=${response.headers?.['content-type'] ?? 'n/a'} | body=${bodySnippet}`,
    );
  }

  // ---------------------------------------------------------------------------

  private async sendSingleWithRetry(payload: WebhookStatusPayload): Promise<boolean> {
    const url = wordpressConfig.endpoints.webhookStatus();
    const hasKey = !!wordpressConfig.apiKey?.trim();
    this.logger.warn(
      `[Webhook WP] PREQ url=${url} | WORDPRESS configurada=${!!wordpressConfig.url?.trim()} | X-API-KEY definida nest=${hasKey} (compare com acm_master_api_key no WP)`,
    );

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      try {
        this.logger.log(`[Webhook WP] OUT (single) tentativa ${attempt}/${MAX_RETRIES}`);
        this.logger.log(
          `[Webhook WP] PAYLOAD_OUT agendamento_id=${payload.agendamento_id} provider=${payload.provider} status=${payload.status}`,
        );
        this.logger.log(`[Webhook WP] PAYLOAD_JSON_OUT\n${this.formatPayloadForDebug(payload)}`);

        const response = await firstValueFrom(
          this.httpService.post(url, payload, {
            headers: {
              'Content-Type': 'application/json',
              'X-API-KEY': wordpressConfig.apiKey,
            },
            timeout: REQUEST_TIMEOUT_MS,
          }),
        );

        this.logWpHttpResponse('single', response as AxiosResponse<unknown>);

        if (response.status >= 200 && response.status < 300) {
          const body = response.data as Record<string, unknown> | undefined;
          if (body && body.success === false) {
            this.logger.error(
              `[Webhook WP] RES http=${response.status} mas success=false | ${JSON.stringify(body).slice(0, 1500)}`,
            );
            return false;
          }
          this.logger.log(`[Webhook WP] OK single agendamento_id=${payload.agendamento_id} status_wp_flow=${payload.status}`);
          return true;
        }

        this.logger.error(
          `[Webhook WP] RES inesperado (single) http=${response.status} | ${JSON.stringify(response.data)?.slice(0, 1200)}`,
        );
      } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
          const st = error.response?.status;
          const stTxt = error.response?.statusText;
          let errBody = '';
          try {
            const d = error.response?.data;
            errBody =
              typeof d === 'string' ? d.slice(0, 2500) : JSON.stringify(d)?.slice(0, 2500);
          } catch {
            errBody = '<unserializable>';
          }
          this.logger.error(
            `[Webhook WP] AXIOS_ERR single http=${st ?? 'n/a'} ${stTxt ?? ''} | response_body=${errBody}`,
          );
        }

        const isRetryable = this.isRetryableError(error);
        const detail = this.formatWpWebhookError(error, url);
        this.logger.warn(
          `[Webhook WP] Tentativa ${attempt}/${MAX_RETRIES} falhou (retryable=${isRetryable}): ${detail}`,
        );

        if (!isRetryable || attempt === MAX_RETRIES) {
          this.logger.error(`[Webhook WP] Falha definitiva ao atualizar WordPress | ${detail}`);
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
          `[Webhook WP] OUT (bulk) chunk=${chunk.length} tentativa=${attempt}/${MAX_RETRIES}`,
        );
        const sample = chunk.slice(0, 3).map((c) => ({
          agendamento_id: c.agendamento_id,
          status: c.status,
          provider: c.provider,
        }));
        this.logger.log(`[Webhook WP] PAYLOAD_SAMPLE_OUT ${JSON.stringify(sample)}`);

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

        this.logWpHttpResponse('bulk', response as AxiosResponse<unknown>);

        if (response.status >= 200 && response.status < 300) {
          const body = response.data as Record<string, unknown> | undefined;
          if (body && body.success === false) {
            this.logger.error(
              `[Webhook WP][bulk] http=${response.status} mas success=false | ${JSON.stringify(body).slice(0, 1500)}`,
            );
            return false;
          }
          this.logger.log(`[Webhook WP] OK bulk chunk=${chunk.length}`);
          return true;
        }

        this.logger.error(
          `[Webhook WP][bulk] RES inesperado http=${response.status} | ${JSON.stringify(response.data)?.slice(0, 1200)}`,
        );
      } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
          const st = error.response?.status;
          let errBody = '';
          try {
            const d = error.response?.data;
            errBody =
              typeof d === 'string' ? d.slice(0, 2500) : JSON.stringify(d)?.slice(0, 2500);
          } catch {
            errBody = '<unserializable>';
          }
          this.logger.error(`[Webhook WP] AXIOS_ERR bulk http=${st ?? 'n/a'} | response_body=${errBody}`);
        }

        const isRetryable = this.isRetryableError(error);
        const detail = this.formatWpWebhookError(error, url);
        this.logger.warn(`[Webhook WP][bulk] Tentativa ${attempt}/${MAX_RETRIES}: ${detail}`);

        if (!isRetryable || attempt === MAX_RETRIES) {
          this.logger.error(`[Webhook WP][bulk] Chunk falhou definitivamente (${chunk.length}) | ${detail}`);
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

