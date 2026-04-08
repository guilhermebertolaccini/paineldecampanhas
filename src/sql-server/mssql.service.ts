import { Injectable, Logger, ServiceUnavailableException } from '@nestjs/common';
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

export type SyncLineHealthOptions = {
  /** Se true, falha de conexão/MERGE propaga (ex.: force-sync). */
  strict?: boolean;
  /** Valor em metricas_json.source (ex.: nest_line_health_manual). */
  metricsSource?: string;
  /** Logs [LineHealthSync] detalhados no terminal (force-sync). */
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
      const metrics = {
        source: metricsSource,
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
