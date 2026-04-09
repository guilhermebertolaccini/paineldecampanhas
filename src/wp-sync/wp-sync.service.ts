import {
  BadRequestException,
  Injectable,
  Logger,
  ServiceUnavailableException,
} from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { firstValueFrom } from 'rxjs';
import { wordpressConfig } from '../config/wordpress.config';
import { MssqlService } from '../sql-server/mssql.service';

export type WpEnviosPendentesPage = {
  success: boolean;
  pagination: {
    limit: number;
    offset: number;
    total_records: number;
  };
  data: Record<string, unknown>[];
};

export type WpPendingSendsSyncResult = {
  ok: boolean;
  batches: number;
  rows_received: number;
  rows_merged: number;
  total_records_wp: number;
  page_size: number;
  message: string;
};

@Injectable()
export class WpSyncService {
  private readonly log = new Logger('WpPendingSendsSync');

  constructor(
    private readonly http: HttpService,
    private readonly mssqlService: MssqlService,
    private readonly config: ConfigService,
  ) {}

  private resolvePageSize(): number {
    const raw = parseInt(
      this.config.get<string>('WP_SYNC_PAGE_SIZE', '1000'),
      10,
    );
    if (!Number.isFinite(raw) || raw < 1) {
      return 1000;
    }
    return Math.min(2000, raw);
  }

  /**
   * Uma página do endpoint REST do WordPress.
   */
  async fetchPendingSendsBatch(
    limit: number,
    offset: number,
  ): Promise<WpEnviosPendentesPage> {
    const key = wordpressConfig.apiKey;
    if (!key) {
      throw new BadRequestException(
        'WORDPRESS_API_KEY / ACM_MASTER_API_KEY não configurada no Nest.',
      );
    }
    const url = wordpressConfig.endpoints.enviosPendentesEtl();
    const res = await firstValueFrom(
      this.http.get<WpEnviosPendentesPage>(url, {
        params: { limit, offset },
        headers: {
          'X-API-KEY': key,
          Accept: 'application/json',
        },
        timeout: 180_000,
        validateStatus: () => true,
      }),
    );
    if (res.status !== 200) {
      const raw = res.data as unknown;
      const snippet =
        typeof raw === 'string'
          ? raw.slice(0, 500)
          : JSON.stringify(raw ?? '').slice(0, 500);
      throw new ServiceUnavailableException(
        `WordPress retornou HTTP ${res.status}: ${snippet}`,
      );
    }
    const body = res.data;
    if (!body || typeof body !== 'object') {
      throw new ServiceUnavailableException(
        'Resposta do WordPress inválida (corpo vazio).',
      );
    }
    if (body.success !== true) {
      throw new ServiceUnavailableException(
        'WordPress retornou success !== true para envios_pendentes.',
      );
    }
    const pagination = body.pagination ?? {
      limit,
      offset,
      total_records: 0,
    };
    const data = Array.isArray(body.data) ? body.data : [];
    return {
      success: true,
      pagination: {
        limit: Number(pagination.limit) || limit,
        offset: Number(pagination.offset) ?? offset,
        total_records: Number(pagination.total_records) || 0,
      },
      data,
    };
  }

  /**
   * Percorre todas as páginas (offset += limit) até `data` vazio ou offset > total_records.
   */
  async runFullPendingSendsSync(): Promise<WpPendingSendsSyncResult> {
    const limit = this.resolvePageSize();
    this.log.log(
      `Iniciando ingestão... (page_size=${limit}, destino MSSQL dbo.PC_WP_ENVIOS_PENDENTES_RAW)`,
    );

    let offset = 0;
    let batch = 0;
    let rowsReceived = 0;
    let rowsMerged = 0;
    let totalRecordsWp = 0;

    while (true) {
      const page = await this.fetchPendingSendsBatch(limit, offset);
      totalRecordsWp = page.pagination.total_records;

      if (!page.data.length) {
        this.log.log(
          `Página vazia (offset=${offset}, total_records_wp=${totalRecordsWp}); encerrando laço.`,
        );
        break;
      }

      batch += 1;
      const n = page.data.length;
      rowsReceived += n;

      const merged = await this.mssqlService.syncWpPendingSendsRaw(page.data, {
        verbose: true,
      });
      rowsMerged += merged;

      this.log.log(
        `Lote ${batch} processado (${n} recebido(s), ${merged} MERGE, offset ${offset}→${offset + limit}, total WP=${totalRecordsWp}).`,
      );

      offset += limit;

      if (offset > totalRecordsWp) {
        this.log.log(
          `offset ${offset} ultrapassou total_records ${totalRecordsWp}; encerrando.`,
        );
        break;
      }
    }

    this.log.log(
      `Sincronização concluída: ${batch} lote(s), ${rowsReceived} linha(s) recebida(s), ${rowsMerged} MERGE no MSSQL, total_records_wp=${totalRecordsWp}.`,
    );

    return {
      ok: true,
      batches: batch,
      rows_received: rowsReceived,
      rows_merged: rowsMerged,
      total_records_wp: totalRecordsWp,
      page_size: limit,
      message:
        'Ingestão batch concluída (ver logs [WpPendingSendsSync] para detalhes).',
    };
  }
}
