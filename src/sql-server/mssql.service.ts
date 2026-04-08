import { Injectable, Logger } from '@nestjs/common';
import * as sql from 'mssql';
import { SqlServerService } from './sql-server.service';

/** Linha consolidada pelo cron de saúde (antes do push ao snapshot MSSQL). */
export type LineHealthSyncRow = {
  id_linha: string;
  nome_linha: string;
  provedor: string;
  status_qualidade: string;
  detalhes_retorno: string | null;
};

/**
 * Snapshot operacional no SQL Server — mesmo contrato da ponte PHP (`PC_Wp_Mssql_Bridge`).
 * Usa o pool compartilhado de {@link SqlServerService} (variáveis MSSQL_* no .env).
 */
@Injectable()
export class MssqlService {
  private readonly logger = new Logger(MssqlService.name);

  private static readonly SNAPSHOT_TABLE = 'PC_LINE_HEALTH_SNAPSHOT';

  /** DDL idempotente alinhado ao PHP (PK line_key, updated_at default). */
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
   * Garante `dbo.PC_LINE_HEALTH_SNAPSHOT` e faz MERGE por linha (upsert + updated_at).
   * `metricas_json` inclui status do probe, detalhes e `captured_at` ISO.
   */
  async syncLineHealth(healthData: LineHealthSyncRow[]): Promise<void> {
    if (!this.sqlServer.isEnabled()) {
      this.logger.debug('MSSQL desabilitado (MSSQL_ENABLED≠true); syncLineHealth ignorado.');
      return;
    }
    if (!healthData.length) {
      return;
    }

    const pool = await this.sqlServer.getPool();
    if (!pool) {
      this.logger.warn('Pool MSSQL indisponível; syncLineHealth abortado.');
      return;
    }

    await pool.request().query(MssqlService.ENSURE_SNAPSHOT_DDL);

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

    for (const row of healthData) {
      const idLinha = String(row.id_linha ?? '').trim();
      const provedor = String(row.provedor ?? '').trim();
      if (!idLinha || !provedor) {
        continue;
      }
      const lineKey = MssqlService.buildLineKey(provedor, idLinha);
      const nomeLinha = String(row.nome_linha ?? '').trim().slice(0, 512);
      const tier = MssqlService.tierFromProbeStatus(String(row.status_qualidade ?? ''));
      const metrics = {
        source: 'nest_line_health_cron',
        captured_at: capturedAt,
        status_qualidade: row.status_qualidade,
        detalhes_retorno: row.detalhes_retorno,
      };
      let metricsJson: string;
      try {
        metricsJson = JSON.stringify(metrics);
      } catch {
        metricsJson = '{}';
      }

      const req = pool.request();
      req.input('line_key', sql.NVarChar(200), lineKey);
      req.input('nome_linha', sql.NVarChar(512), nomeLinha || null);
      req.input('provedor', sql.NVarChar(128), provedor.slice(0, 128));
      req.input('idgis_ambiente', sql.NVarChar(64), idLinha.slice(0, 64));
      req.input('saude_tier', sql.NVarChar(32), tier.slice(0, 32));
      req.input('metricas_json', sql.NVarChar(sql.MAX), metricsJson);
      await req.query(mergeSql);
    }
  }

  private static buildLineKey(provedor: string, idLinha: string): string {
    const raw = `${provedor}|${idLinha}`;
    return raw.length <= 200 ? raw : raw.slice(0, 200);
  }

  /** Aproxima tiers do painel (GREEN / YELLOW / RED) a partir do código do probe HTTP. */
  private static tierFromProbeStatus(status: string): string {
    const s = status.trim();
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
