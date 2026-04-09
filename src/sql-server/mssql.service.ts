import { Injectable, Logger, ServiceUnavailableException } from '@nestjs/common';
import * as sql from 'mssql';
import type { StandardLineHealth } from '../line-health/adapters/standard-line-health.interface';
import { SqlServerService } from './sql-server.service';

/** Linha consolidada pelo cron de saúde (antes do push ao snapshot MSSQL). */
export type LineHealthSyncRow = {
  id_linha: string;
  nome_linha: string;
  provedor: string;
  status_qualidade: string;
  detalhes_retorno: string | null;
  /** Registro padronizado (ETL) — embutido em `metricas_json` quando presente. */
  standard_line_health?: StandardLineHealth;
};

export type SyncLineHealthOptions = {
  /** Se true, falha de conexão/MERGE propaga (ex.: force-sync). */
  strict?: boolean;
  /** Valor em metricas_json.source (ex.: nest_line_health_manual). */
  metricsSource?: string;
  /** Logs [LineHealthSync] detalhados no terminal (force-sync). */
  verbose?: boolean;
};

export type SyncWpPendingRawOptions = {
  verbose?: boolean;
};

/**
 * Snapshot operacional no SQL Server — mesmo contrato da ponte PHP (`PC_Wp_Mssql_Bridge`).
 * Usa o pool compartilhado de {@link SqlServerService} (variáveis MSSQL_* no .env).
 */
@Injectable()
export class MssqlService {
  private readonly logger = new Logger(MssqlService.name);
  private readonly lineHealthSyncLog = new Logger('LineHealthSync');
  private readonly wpPendingLog = new Logger('WpPendingSendsSync');

  /** DDL idempotente alinhado ao PHP (PK line_key, updated_at default). */
  private static readonly ENSURE_WP_ENVIOS_RAW_DDL = `
IF OBJECT_ID(N'dbo.PC_WP_ENVIOS_PENDENTES_RAW', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.PC_WP_ENVIOS_PENDENTES_RAW (
        wp_id BIGINT NOT NULL,
        payload_json NVARCHAR(MAX) NOT NULL,
        telefone NVARCHAR(512) NULL,
        status NVARCHAR(256) NULL,
        agendamento_id NVARCHAR(256) NULL,
        fornecedor NVARCHAR(256) NULL,
        idgis_ambiente NVARCHAR(128) NULL,
        synced_at DATETIME2 NOT NULL CONSTRAINT DF_PC_WP_ENVIOS_RAW_SYNC DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_PC_WP_ENVIOS_PENDENTES_RAW PRIMARY KEY (wp_id)
    );
END
`;

  private static readonly ENSURE_SNAPSHOT_DDL = `
IF OBJECT_ID(N'dbo.PC_LINE_HEALTH_SNAPSHOT', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.PC_LINE_HEALTH_SNAPSHOT (
        line_key NVARCHAR(200) NOT NULL,
        nome_linha NVARCHAR(512) NULL,
        provedor NVARCHAR(128) NULL,
        idgis_ambiente NVARCHAR(64) NULL,
        saude_tier NVARCHAR(32) NOT NULL,
        metricas_json NVARCHAR(MAX) NULL,
        updated_at DATETIME2 NOT NULL CONSTRAINT DF_PC_LINE_HEALTH_SNAPSHOT_UPD DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_PC_LINE_HEALTH_SNAPSHOT PRIMARY KEY (line_key)
    );
END
`;

  constructor(private readonly sqlServer: SqlServerService) {}

  /**
   * Ingestão bruta no MSSQL: MERGE por `wp_id` (PK WordPress `envios_pendentes.id`).
   */
  async syncWpPendingSendsRaw(
    batchData: Record<string, unknown>[],
    options?: SyncWpPendingRawOptions,
  ): Promise<number> {
    const verbose = options?.verbose === true;
    const vlog = (m: string) => {
      if (verbose) {
        this.wpPendingLog.log(m);
      }
    };

    if (!this.sqlServer.isEnabled()) {
      this.wpPendingLog.warn('MSSQL desabilitado; syncWpPendingSendsRaw ignorado.');
      return 0;
    }
    if (!batchData.length) {
      return 0;
    }

    const pool = await this.sqlServer.getPool();
    if (!pool) {
      this.wpPendingLog.error('Pool MSSQL indisponível.');
      throw new ServiceUnavailableException('Pool MSSQL indisponível.');
    }

    await pool.request().query(MssqlService.ENSURE_WP_ENVIOS_RAW_DDL);

    const mergeSql = `
MERGE dbo.PC_WP_ENVIOS_PENDENTES_RAW AS T
USING (SELECT @wp_id AS wp_id, @payload_json AS payload_json, @telefone AS telefone,
              @status AS status, @agendamento_id AS agendamento_id, @fornecedor AS fornecedor,
              @idgis_ambiente AS idgis_ambiente) AS S
ON T.wp_id = S.wp_id
WHEN MATCHED THEN
  UPDATE SET
    payload_json = S.payload_json,
    telefone = S.telefone,
    status = S.status,
    agendamento_id = S.agendamento_id,
    fornecedor = S.fornecedor,
    idgis_ambiente = S.idgis_ambiente,
    synced_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
  INSERT (wp_id, payload_json, telefone, status, agendamento_id, fornecedor, idgis_ambiente)
  VALUES (S.wp_id, S.payload_json, S.telefone, S.status, S.agendamento_id, S.fornecedor, S.idgis_ambiente);
`;

    let merged = 0;
    for (const row of batchData) {
      const wpId = MssqlService.coerceWpRowId(row.id);
      if (wpId === null) {
        vlog(`Linha sem id numérico válido; ignorada (keys=${Object.keys(row).join(',')}).`);
        continue;
      }
      let payloadJson: string;
      try {
        payloadJson = JSON.stringify(row);
      } catch {
        payloadJson = '{}';
      }
      const telefone = MssqlService.pickStr(row, 'telefone', 512);
      const status = MssqlService.pickStr(row, 'status', 256);
      const agendamento_id = MssqlService.pickStr(row, 'agendamento_id', 256);
      const fornecedor = MssqlService.pickStr(row, 'fornecedor', 256);
      const idgis_ambiente = MssqlService.pickStr(row, 'idgis_ambiente', 128);

      try {
        const req = pool.request();
        req.input('wp_id', sql.BigInt, wpId);
        req.input('payload_json', sql.NVarChar(sql.MAX), payloadJson);
        req.input('telefone', sql.NVarChar(512), telefone);
        req.input('status', sql.NVarChar(256), status);
        req.input('agendamento_id', sql.NVarChar(256), agendamento_id);
        req.input('fornecedor', sql.NVarChar(256), fornecedor);
        req.input('idgis_ambiente', sql.NVarChar(128), idgis_ambiente);
        await req.query(mergeSql);
        merged += 1;
      } catch (e) {
        this.logWpPendingMssqlError(`MERGE wp_id=${wpId}`, e);
        throw new ServiceUnavailableException(
          `MERGE PC_WP_ENVIOS_PENDENTES_RAW falhou (wp_id=${wpId}): ${e instanceof Error ? e.message : String(e)}`,
        );
      }
    }

    vlog(`MERGE RAW: ${merged}/${batchData.length} linha(s) neste lote.`);
    return merged;
  }

  private static coerceWpRowId(raw: unknown): bigint | null {
    if (raw === null || raw === undefined) {
      return null;
    }
    const n =
      typeof raw === 'bigint'
        ? raw
        : typeof raw === 'number' && Number.isFinite(raw)
          ? BigInt(Math.trunc(raw))
          : typeof raw === 'string' && /^-?\d+$/.test(raw.trim())
            ? BigInt(raw.trim())
            : null;
    if (n === null) {
      return null;
    }
    return n;
  }

  private static pickStr(
    row: Record<string, unknown>,
    key: string,
    max: number,
  ): string | null {
    const v = row[key];
    if (v === null || v === undefined) {
      return null;
    }
    const s = String(v).trim();
    if (!s) {
      return null;
    }
    return s.length <= max ? s : s.slice(0, max);
  }

  private logWpPendingMssqlError(phase: string, err: unknown): void {
    if (err && typeof err === 'object' && 'originalError' in err) {
      this.wpPendingLog.error(
        `[MSSQL driver] ${phase}: ${JSON.stringify(err, Object.getOwnPropertyNames(err as object))}`,
      );
      return;
    }
    if (err instanceof Error) {
      this.wpPendingLog.error(`[MSSQL driver] ${phase}: ${err.message}`, err.stack);
      return;
    }
    this.wpPendingLog.error(`[MSSQL driver] ${phase}: ${String(err)}`);
  }

  /**
   * Garante `dbo.PC_LINE_HEALTH_SNAPSHOT` e faz MERGE por linha (upsert + updated_at).
   * @returns Quantidade de linhas efetivamente enviadas ao MERGE (com id_linha e provedor válidos).
   */
  async syncLineHealth(
    healthData: LineHealthSyncRow[],
    options?: SyncLineHealthOptions,
  ): Promise<number> {
    const strict = options?.strict === true;
    const verbose = options?.verbose === true;
    const metricsSource =
      options?.metricsSource ?? 'nest_line_health_cron';

    const vlog = (m: string) => {
      if (verbose) {
        this.lineHealthSyncLog.log(m);
      }
    };

    if (!this.sqlServer.isEnabled()) {
      const msg =
        'MSSQL desabilitado (MSSQL_ENABLED≠true); syncLineHealth não executado.';
      this.lineHealthSyncLog.warn(msg);
      if (strict) {
        throw new ServiceUnavailableException(msg);
      }
      return 0;
    }
    if (!healthData.length) {
      vlog('Nenhum registro consolidado para enviar ao snapshot.');
      return 0;
    }

    vlog('Conectando ao MSSQL (.26) / pool compartilhado...');

    let pool: sql.ConnectionPool | null;
    try {
      pool = await this.sqlServer.getPool();
    } catch (e) {
      this.logMssqlDriverError('getPool', e);
      if (strict) {
        throw new ServiceUnavailableException(
          `Falha ao obter pool MSSQL: ${e instanceof Error ? e.message : String(e)}`,
        );
      }
      return 0;
    }

    if (!pool) {
      const msg =
        'Pool MSSQL indisponível (credenciais/host ou driver); syncLineHealth abortado.';
      this.lineHealthSyncLog.error(msg);
      if (strict) {
        throw new ServiceUnavailableException(msg);
      }
      return 0;
    }

    const toMerge = healthData.filter(
      (row) =>
        String(row.id_linha ?? '').trim() !== '' &&
        String(row.provedor ?? '').trim() !== '',
    );

    vlog(
      `Injetando ${toMerge.length} registro(s) na tabela PC_LINE_HEALTH_SNAPSHOT (MERGE por line_key)...`,
    );

    try {
      await pool.request().query(MssqlService.ENSURE_SNAPSHOT_DDL);
    } catch (e) {
      this.logMssqlDriverError('ENSURE_SNAPSHOT_DDL', e);
      if (strict) {
        throw new ServiceUnavailableException(
          `DDL PC_LINE_HEALTH_SNAPSHOT falhou: ${e instanceof Error ? e.message : String(e)}`,
        );
      }
      return 0;
    }

    const mergeSql = `
MERGE dbo.PC_LINE_HEALTH_SNAPSHOT AS T
USING (SELECT @line_key AS line_key, @nome_linha AS nome_linha, @provedor AS provedor,
              @idgis_ambiente AS idgis_ambiente, @saude_tier AS saude_tier, @metricas_json AS metricas_json) AS S
ON T.line_key = S.line_key
WHEN MATCHED THEN
  UPDATE SET
    nome_linha = S.nome_linha,
    provedor = S.provedor,
    idgis_ambiente = S.idgis_ambiente,
    saude_tier = S.saude_tier,
    metricas_json = S.metricas_json,
    updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
  INSERT (line_key, nome_linha, provedor, idgis_ambiente, saude_tier, metricas_json)
  VALUES (S.line_key, S.nome_linha, S.provedor, S.idgis_ambiente, S.saude_tier, S.metricas_json);
`;

    const capturedAt = new Date().toISOString();
    let merged = 0;

    for (const row of toMerge) {
      const idLinha = String(row.id_linha ?? '').trim();
      const provedor = String(row.provedor ?? '').trim();
      const lineKey = MssqlService.buildLineKey(provedor, idLinha);
      const nomeLinha = String(row.nome_linha ?? '').trim().slice(0, 512);
      const tier = MssqlService.tierFromProbeStatus(String(row.status_qualidade ?? ''));
      const metrics: Record<string, unknown> = {
        source: metricsSource,
        captured_at: capturedAt,
        status_qualidade: row.status_qualidade,
        detalhes_retorno: row.detalhes_retorno,
      };
      if (row.standard_line_health !== undefined) {
        metrics.standard_line_health = row.standard_line_health;
      }
      let metricsJson: string;
      try {
        metricsJson = JSON.stringify(metrics);
      } catch {
        metricsJson = '{}';
      }

      try {
        const req = pool.request();
        req.input('line_key', sql.NVarChar(200), lineKey);
        req.input('nome_linha', sql.NVarChar(512), nomeLinha || null);
        req.input('provedor', sql.NVarChar(128), provedor.slice(0, 128));
        req.input('idgis_ambiente', sql.NVarChar(64), idLinha.slice(0, 64));
        req.input('saude_tier', sql.NVarChar(32), tier.slice(0, 32));
        req.input('metricas_json', sql.NVarChar(sql.MAX), metricsJson);
        await req.query(mergeSql);
        merged += 1;
      } catch (e) {
        this.logMssqlDriverError(`MERGE line_key=${lineKey}`, e);
        if (strict) {
          throw new ServiceUnavailableException(
            `MERGE PC_LINE_HEALTH_SNAPSHOT falhou (${lineKey}): ${e instanceof Error ? e.message : String(e)}`,
          );
        }
      }
    }

    vlog(
      `MERGE concluído: ${merged}/${toMerge.length} linha(s) processada(s).`,
    );
    return merged;
  }

  private logMssqlDriverError(phase: string, err: unknown): void {
    if (err && typeof err === 'object' && 'originalError' in err) {
      this.lineHealthSyncLog.error(
        `[MSSQL driver] ${phase}: ${JSON.stringify(err, Object.getOwnPropertyNames(err as object))}`,
      );
      return;
    }
    if (err instanceof Error) {
      this.lineHealthSyncLog.error(
        `[MSSQL driver] ${phase}: ${err.message}`,
        err.stack,
      );
      return;
    }
    this.lineHealthSyncLog.error(`[MSSQL driver] ${phase}: ${String(err)}`);
  }

  private static buildLineKey(provedor: string, idLinha: string): string {
    const raw = `${provedor}|${idLinha}`;
    return raw.length <= 200 ? raw : raw.slice(0, 200);
  }

  /** Aproxima tiers do painel (GREEN / YELLOW / RED) a partir do código do probe HTTP. */
  private static tierFromProbeStatus(status: string): string {
    const s = status.trim();
    if (s === 'CONNECTED') {
      return 'GREEN';
    }
    if (s === 'RESTRICTED') {
      return 'YELLOW';
    }
    if (s.startsWith('OK_')) {
      return 'GREEN';
    }
    if (s === 'SEM_PROBE_CONFIGURADO') {
      return 'YELLOW';
    }
    if (s.startsWith('HTTP_')) {
      const code = parseInt(s.slice(5), 10);
      if (!Number.isNaN(code) && code >= 500) {
        return 'RED';
      }
      if (!Number.isNaN(code) && code >= 400) {
        return 'YELLOW';
      }
      if (!Number.isNaN(code) && code >= 200 && code < 300) {
        return 'GREEN';
      }
    }
    if (
      s.startsWith('FALHA_') ||
      s === 'ERRO_CREDENCIAL_OU_API' ||
      s.includes('FALHA')
    ) {
      return 'RED';
    }
    return 'YELLOW';
  }
}
