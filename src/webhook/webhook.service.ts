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

const BULK_WEBHOOK_CHUNK_SIZE = 250;

/** Mantém payloads HTTP pequenos: respostas brutas dos providers podem ultrapassar limites PHP. */
const MAX_RESPOSTA_API_CHARS = 12_000;
const MAX_MENSAGEM_PROGRESSO_CHARS = 2_048;

const MAX_RETRIES = 3;
const INITIAL_RETRY_DELAY_MS = 1000;
const SINGLE_REQUEST_TIMEOUT_MS = 30000;
const BULK_CHUNK_REQUEST_TIMEOUT_MS = 120_000;
const DELAY_BETWEEN_BULK_BATCHES_MS = 600;

/**
 * Um único cliente HTTP por vez contra o WP evita rajadas paralelas vindas de vários workers BullMQ.
 * Pedidos únicos continuam passando por enqueueRequest().
 */
const MAX_CONCURRENT_SINGLE_REQUESTS = 1;

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

    const chunks = this.chunkArray(payloads, BULK_WEBHOOK_CHUNK_SIZE);
    const totalBatches = chunks.length;

    this.logger.log(
      `[Webhook WP] modo bulk: ${payloads.length} atualização(ões) em ${totalBatches} lote(s) sequencial(is) — máximo ${BULK_WEBHOOK_CHUNK_SIZE} atualizações por POST`,
    );

    let succeeded = 0;
    let failed = 0;

    for (let batchIndex = 0; batchIndex < chunks.length; batchIndex++) {
      const chunk = chunks[batchIndex];

      try {
        this.logger.log(
          `[Webhook WP] Enviando lote ${batchIndex + 1} de ${totalBatches} (${chunk.length} atualizações neste POST)`,
        );
        /** Lotes bulk são sempre sequenciais (sem dequeue paralelo): reduz timeouts no WordPress/PHP. */
        const ok = await this.sendChunkWithRetry(chunk, batchIndex + 1, totalBatches);
        if (ok) {
          succeeded += chunk.length;
        } else {
          failed += chunk.length;
        }
      } catch {
        failed += chunk.length;
      }

      if (batchIndex < chunks.length - 1) {
        await this.sleep(DELAY_BETWEEN_BULK_BATCHES_MS);
      }
    }

    this.logger.log(
      `[Webhook WP] modo bulk concluído: ${succeeded} bem-sucedida(s), ${failed} falhada(s)`,
    );
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

  /** Evita POST único gigante (timeouts PHP/memória WP) ao truncar corpos de provedor volumosos. */
  private clampPayloadForTransport(p: WebhookStatusPayload): WebhookStatusPayload {
    let { resposta_api, mensagem_progresso } = p;
    if (resposta_api != null && resposta_api.length > MAX_RESPOSTA_API_CHARS) {
      const suffix = '\n...[trunc Nest: resposta_api]';
      resposta_api =
        resposta_api.slice(0, Math.max(0, MAX_RESPOSTA_API_CHARS - suffix.length)) + suffix;
    }
    if (mensagem_progresso != null && mensagem_progresso.length > MAX_MENSAGEM_PROGRESSO_CHARS) {
      mensagem_progresso = mensagem_progresso.slice(0, MAX_MENSAGEM_PROGRESSO_CHARS) + '…';
    }
    if (resposta_api === p.resposta_api && mensagem_progresso === p.mensagem_progresso) {
      return p;
    }
    return { ...p, resposta_api, mensagem_progresso };
  }

  private async sendSingleWithRetry(payload: WebhookStatusPayload): Promise<boolean> {
    const url = wordpressConfig.endpoints.webhookStatus();
    const hasKey = !!wordpressConfig.apiKey?.trim();
    const safe = this.clampPayloadForTransport(payload);
    this.logger.warn(
      `[Webhook WP] PREQ url=${url} | WORDPRESS configurada=${!!wordpressConfig.url?.trim()} | X-API-KEY definida nest=${hasKey} (compare com acm_master_api_key no WP)`,
    );

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      try {
        this.logger.log(`[Webhook WP] OUT (single) tentativa ${attempt}/${MAX_RETRIES}`);
        this.logger.log(
          `[Webhook WP] PAYLOAD_OUT agendamento_id=${safe.agendamento_id} provider=${safe.provider} status=${safe.status}`,
        );
        this.logger.log(`[Webhook WP] PAYLOAD_JSON_OUT\n${this.formatPayloadForDebug(safe)}`);

        const response = await firstValueFrom(
          this.httpService.post(url, safe, {
            headers: {
              'Content-Type': 'application/json',
              'X-API-KEY': wordpressConfig.apiKey,
            },
            timeout: SINGLE_REQUEST_TIMEOUT_MS,
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
          this.logger.log(`[Webhook WP] OK single agendamento_id=${safe.agendamento_id} status_wp_flow=${safe.status}`);
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

  private async sendChunkWithRetry(
    chunk: WebhookStatusPayload[],
    batchOrdinal: number,
    totalBatches: number,
  ): Promise<boolean> {
    const url = wordpressConfig.endpoints.webhookStatus();
    const updates = chunk.map((p) => this.clampPayloadForTransport(p));
    const bulkPayload = { bulk: true, updates };

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      try {
        this.logger.log(
          `[Webhook WP] OUT (bulk) lote ${batchOrdinal}/${totalBatches}, ${updates.length} item(ns), tentativa ${attempt}/${MAX_RETRIES}`,
        );
        const sample = updates.slice(0, 3).map((c) => ({
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
            timeout: BULK_CHUNK_REQUEST_TIMEOUT_MS,
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
          this.logger.log(
            `[Webhook WP] OK bulk lote ${batchOrdinal}/${totalBatches}: ${updates.length} atualização(ões) aplicada(s) neste POST`,
          );
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
          this.logger.error(
            `[Webhook WP][bulk] lote ${batchOrdinal}/${totalBatches} falhou definitivamente (${updates.length}) | ${detail}`,
          );
          return false;
        }

        const delay = INITIAL_RETRY_DELAY_MS * Math.pow(2, attempt - 1);
        await this.sleep(delay);
      }
    }
    return false;
  }

  /**
   * Fila apenas para chamadas single: garante que no máximo MAX_CONCURRENT_SINGLE_REQUESTS pedidos ao WP corram em paralelo.
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

      if (this.activeRequests < MAX_CONCURRENT_SINGLE_REQUESTS) {
        execute();
      } else {
        this.requestQueue.push(execute);
      }
    });
  }

  private drainQueue(): void {
    while (this.activeRequests < MAX_CONCURRENT_SINGLE_REQUESTS && this.requestQueue.length > 0) {
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

