import {
  BadRequestException,
  Injectable,
  Logger,
  ServiceUnavailableException,
} from '@nestjs/common';
import { Cron } from '@nestjs/schedule';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { firstValueFrom } from 'rxjs';
import { CampaignsService } from '../campaigns/campaigns.service';
import { DigitalFunnelMssqlService } from '../sql-server/digital-funnel-mssql.service';
import {
  MssqlService,
  type LineHealthSyncRow,
} from '../sql-server/mssql.service';
import { wordpressConfig } from '../config/wordpress.config';
import {
  GosacHealthAdapter,
  NoahHealthAdapter,
  type StandardLineHealth,
} from './adapters';
import { standardLineHealthToSyncRow } from './standard-line-health.mapper';

interface LineHealthTarget {
  provider: string;
  envId: string;
  nome_linha?: string;
  /** URL opcional GET que retorne 200 = saudável */
  health_url?: string;
}

interface LineHealthEval {
  status_qualidade: string;
  detalhes_retorno: string | null;
}

export type LineHealthForceSyncResult = {
  ok: boolean;
  targets_scanned: number;
  rows_consolidated: number;
  mssql_snapshot_rows_merged: number;
  wordpress_post_attempted: boolean;
  message: string;
};

@Injectable()
export class LineHealthService {
  private readonly logger = new Logger(LineHealthService.name);
  /** Logs visíveis no terminal com prefixo [LineHealthSync] */
  private readonly syncLog = new Logger('LineHealthSync');

  constructor(
    private readonly config: ConfigService,
    private readonly campaigns: CampaignsService,
    private readonly http: HttpService,
    private readonly digitalFunnel: DigitalFunnelMssqlService,
    private readonly mssqlService: MssqlService,
  ) {}

  private parseTargets(): LineHealthTarget[] {
    const raw = this.config.get<string>('LINE_HEALTH_TARGETS', '[]');
    try {
      const parsed = JSON.parse(raw) as unknown;
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed.filter(
        (x): x is LineHealthTarget =>
          x != null &&
          typeof x === 'object' &&
          typeof (x as LineHealthTarget).provider === 'string' &&
          typeof (x as LineHealthTarget).envId === 'string',
      );
    } catch {
      this.logger.warn('LINE_HEALTH_TARGETS inválido (JSON esperado).');
      return [];
    }
  }

  private toDetails(err: unknown): string {
    if (err instanceof Error) {
      return err.message.slice(0, 4000);
    }
    return String(err).slice(0, 4000);
  }

  /** Diariamente às 06:00 (minuto 0, hora 6). */
  @Cron('0 0 6 * * *')
  async runDailyLineHealth(): Promise<void> {
    if (this.config.get<string>('LINE_HEALTH_CRON_ENABLED', 'false') !== 'true') {
      return;
    }
    const targets = this.parseTargets();
    if (targets.length === 0) {
      this.logger.debug('LINE_HEALTH_TARGETS vazio; cron de saúde ignorado.');
      return;
    }
    try {
      await this.runLineHealthSyncPipeline({
        metricsSource: 'nest_line_health_cron',
        mssqlStrict: false,
        logPrefix: 'cron',
      });
    } catch (e) {
      this.logger.error(
        `Cron saúde das linhas falhou: ${e instanceof Error ? e.message : String(e)}`,
        e instanceof Error ? e.stack : undefined,
      );
    }
  }

  /**
   * Mesmo pipeline do cron (sem checar LINE_HEALTH_CRON_ENABLED).
   * Usado por GET /api/v1/health/force-sync.
   */
  async forceSyncLineHealth(): Promise<LineHealthForceSyncResult> {
    const targets = this.parseTargets();
    if (targets.length === 0) {
      throw new BadRequestException(
        'LINE_HEALTH_TARGETS vazio ou JSON inválido. Defina alvos no .env para executar a varredura.',
      );
    }
    this.syncLog.log('Iniciando varredura manual de saúde das linhas...');
    try {
      const mssqlRows = await this.runLineHealthSyncPipeline({
        metricsSource: 'nest_line_health_manual',
        mssqlStrict: true,
        logPrefix: 'manual',
      });
      this.syncLog.log('Sincronização MSSQL concluída com sucesso!');
      return {
        ok: true,
        targets_scanned: targets.length,
        rows_consolidated: mssqlRows.rowsConsolidated,
        mssql_snapshot_rows_merged: mssqlRows.mergedCount,
        wordpress_post_attempted: mssqlRows.wordpressPostAttempted,
        message:
          'Varredura concluída; snapshot PC_LINE_HEALTH_SNAPSHOT atualizado (ver logs [LineHealthSync]).',
      };
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.syncLog.error(`Falha na sincronização manual: ${msg}`);
      if (e instanceof ServiceUnavailableException || e instanceof BadRequestException) {
        throw e;
      }
      throw new ServiceUnavailableException(
        `Sincronização de saúde falhou: ${msg}`,
      );
    }
  }

  private async runLineHealthSyncPipeline(opts: {
    metricsSource: string;
    mssqlStrict: boolean;
    logPrefix: string;
  }): Promise<{
    mergedCount: number;
    rowsConsolidated: number;
    wordpressPostAttempted: boolean;
  }> {
    const targets = this.parseTargets();
    if (targets.length === 0) {
      return { mergedCount: 0, rowsConsolidated: 0, wordpressPostAttempted: false };
    }

    this.syncLog.log(
      `Varredura (${opts.logPrefix}): ${targets.length} alvo(s) em LINE_HEALTH_TARGETS.`,
    );
    const consolidated: LineHealthSyncRow[] = [];

    for (const t of targets) {
      const nome = t.nome_linha ?? `${t.provider}:${t.envId}`;
      try {
        const creds = (await this.campaigns.fetchCredentials(
          t.provider,
          t.envId,
        )) as Record<string, unknown>;
        const bundled = await this.resolveStandardLinesWithDetails(t, creds);
        for (const { standard, detalhes_retorno } of bundled) {
          const row = standardLineHealthToSyncRow(t, standard);
          row.detalhes_retorno = detalhes_retorno;
          await this.digitalFunnel.insertSaudeLinhaHistorico(row);
          consolidated.push(row);
        }
      } catch (e) {
        this.logger.warn(`Saúde linha ${nome}: ${e}`);
        const stdErr = this.standardFromErroAlvo(t, nome, this.toDetails(e));
        const row = standardLineHealthToSyncRow(t, stdErr);
        row.detalhes_retorno = this.toDetails(e);
        await this.digitalFunnel.insertSaudeLinhaHistorico(row);
        consolidated.push(row);
      }
    }

    let wordpressPostAttempted = false;
    const postUrl = this.config.get<string>('LINE_HEALTH_WORDPRESS_SYNC_URL', '').trim();
    if (postUrl) {
      wordpressPostAttempted = true;
      try {
        await firstValueFrom(
          this.http.post(
            postUrl,
            {
              source: opts.metricsSource,
              captured_at: new Date().toISOString(),
              rows: consolidated,
            },
            {
              headers: {
                'Content-Type': 'application/json',
                ...(wordpressConfig.apiKey
                  ? { 'X-API-KEY': wordpressConfig.apiKey }
                  : {}),
              },
              timeout: 45_000,
              validateStatus: () => true,
            },
          ),
        );
      } catch (e) {
        this.logger.warn(
          `LINE_HEALTH POST WordPress (opcional) falhou — prosseguindo para MSSQL: ${e}`,
        );
      }
    }

    const mergedCount = await this.mssqlService.syncLineHealth(consolidated, {
      strict: opts.mssqlStrict,
      metricsSource: opts.metricsSource,
      verbose: opts.logPrefix === 'manual',
    });

    return {
      mergedCount,
      rowsConsolidated: consolidated.length,
      wordpressPostAttempted,
    };
  }

  /**
   * Tenta ETL via API do provedor (NOAH / GOSAC); se não houver dados, cai no probe HTTP legado.
   * Retorno sempre passa pelos adapters quando a API responde com corpo utilizável.
   */
  private async resolveStandardLinesWithDetails(
    t: LineHealthTarget,
    creds: Record<string, unknown>,
  ): Promise<Array<{ standard: StandardLineHealth; detalhes_retorno: string | null }>> {
    const p = t.provider.toUpperCase();
    const isNoah = p.includes('NOAH') && !p.includes('GOSAC');
    const isGosac = p.includes('GOSAC') && !p.includes('NOAH');

    if (isNoah) {
      try {
        const raw = await this.fetchNoahChannelList(creds);
        const standards = NoahHealthAdapter.adapt(raw);
        if (standards.length > 0) {
          return standards.map((standard) => ({
            standard,
            detalhes_retorno: null,
          }));
        }
      } catch (e) {
        this.logger.debug(
          `NOAH adapt (${t.envId}): ${e instanceof Error ? e.message : String(e)}`,
        );
      }
    }

    if (isGosac) {
      try {
        const raw = await this.fetchGosacConnectionsJson(t, creds);
        const standards = GosacHealthAdapter.adapt(raw);
        if (standards.length > 0) {
          return standards.map((standard) => ({
            standard,
            detalhes_retorno: null,
          }));
        }
      } catch (e) {
        this.logger.debug(
          `GOSAC adapt (${t.envId}): ${e instanceof Error ? e.message : String(e)}`,
        );
      }
    }

    const evalResult = await this.evaluateProvider(t, creds);
    return [
      {
        standard: this.standardFromProbe(t, evalResult),
        detalhes_retorno: evalResult.detalhes_retorno,
      },
    ];
  }

  private standardFromProbe(
    t: LineHealthTarget,
    ev: LineHealthEval,
  ): StandardLineHealth {
    const p = t.provider.toUpperCase();
    const canon = p.includes('NOAH')
      ? 'NOAH'
      : p.includes('GOSAC')
        ? 'GOSAC'
        : t.provider;
    const nome = t.nome_linha ?? `${t.provider}:${t.envId}`;
    return {
      provedor: canon,
      id_externo: t.envId,
      nome_linha: nome,
      numero_telefone: null,
      status_conexao: ev.status_qualidade,
      limite_mensagens: null,
      restricao_conta: null,
      waba_id: null,
      waba_phone_id: null,
      dados_brutos: {
        fonte: 'probe_http',
        detalhes_retorno: ev.detalhes_retorno,
      },
    };
  }

  private standardFromErroAlvo(
    t: LineHealthTarget,
    nome_linha: string,
    detalhe: string,
  ): StandardLineHealth {
    const p = t.provider.toUpperCase();
    const canon = p.includes('NOAH')
      ? 'NOAH'
      : p.includes('GOSAC')
        ? 'GOSAC'
        : t.provider;
    return {
      provedor: canon,
      id_externo: t.envId,
      nome_linha: nome_linha,
      numero_telefone: null,
      status_conexao: 'ERRO_CREDENCIAL_OU_API',
      limite_mensagens: null,
      restricao_conta: null,
      waba_id: null,
      waba_phone_id: null,
      dados_brutos: { fonte: 'erro_credencial', detalhe },
    };
  }

  private buildNoahIntegrationAuth(token: string): string {
    let raw = String(token).trim();
    if (!raw) {
      return '';
    }
    raw = raw.replace(/^(Bearer|INTEGRATION)\s+/gi, '').trim();
    return raw ? `INTEGRATION ${raw}` : '';
  }

  private buildGosacBearer(token: string): string {
    const t = String(token).trim();
    if (!t) {
      return '';
    }
    return t.toLowerCase().startsWith('bearer ') ? t : `Bearer ${t}`;
  }

  private async fetchNoahChannelList(
    creds: Record<string, unknown>,
  ): Promise<unknown[]> {
    const base = String(creds.url ?? '').replace(/\/$/, '');
    const auth = this.buildNoahIntegrationAuth(String(creds.token ?? ''));
    if (!base || !auth) {
      throw new Error('NOAH: url ou token ausente nas credenciais');
    }

    const channelIds = creds.channel_ids;
    if (Array.isArray(channelIds) && channelIds.length > 0) {
      return channelIds.map((cid) => ({
        id: cid,
        channelId: cid,
      }));
    }

    const url = `${base}/channels`;
    const res = await firstValueFrom(
      this.http.get<unknown>(url, {
        headers: {
          Authorization: auth,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        timeout: 45_000,
        validateStatus: () => true,
      }),
    );
    if (res.status !== 200) {
      throw new Error(`NOAH GET /channels → HTTP ${res.status}`);
    }
    const body = res.data;
    if (Array.isArray(body)) {
      return body;
    }
    if (body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)) {
      return (body as { data: unknown[] }).data;
    }
    return [];
  }

  private async fetchGosacConnectionsJson(
    t: LineHealthTarget,
    creds: Record<string, unknown>,
  ): Promise<unknown> {
    const base = String(creds.url ?? '').replace(/\/$/, '');
    const bearer = this.buildGosacBearer(String(creds.token ?? ''));
    if (!base || !bearer) {
      throw new Error('GOSAC: url ou token ausente nas credenciais');
    }

    let idgis = String(t.envId).trim();
    let idRuler = '';
    if (idgis.includes('|')) {
      const parts = idgis.split('|', 2);
      idgis = parts[0].trim();
      idRuler = (parts[1] ?? '').trim();
    }

    const params = new URLSearchParams();
    params.set('idgis', idgis);
    params.set('idAmbient', idgis);
    if (idRuler) {
      params.set('ruler', idRuler);
      params.set('idRuler', idRuler);
    }

    const url = `${base}/connections/official?${params.toString()}`;
    const res = await firstValueFrom(
      this.http.get<unknown>(url, {
        headers: {
          Authorization: bearer,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        timeout: 45_000,
        validateStatus: () => true,
      }),
    );
    if (res.status !== 200) {
      throw new Error(`GOSAC GET /connections/official → HTTP ${res.status}`);
    }
    return res.data;
  }

  private async evaluateProvider(
    t: LineHealthTarget,
    creds: Record<string, unknown>,
  ): Promise<LineHealthEval> {
    if (t.health_url) {
      try {
        const res = await firstValueFrom(
          this.http.get(t.health_url, { timeout: 15_000, validateStatus: () => true }),
        );
        const ok = res.status >= 200 && res.status < 300;
        return {
          status_qualidade: ok ? 'OK_HTTP' : `HTTP_${res.status}`,
          detalhes_retorno: `GET ${t.health_url} → ${res.status}`,
        };
      } catch (e) {
        return {
          status_qualidade: 'FALHA_HTTP',
          detalhes_retorno: this.toDetails(e),
        };
      }
    }
    const url =
      (typeof creds.health_check_url === 'string' && creds.health_check_url) ||
      (typeof creds.base_url === 'string' && creds.base_url);
    if (url && typeof url === 'string') {
      try {
        const res = await firstValueFrom(
          this.http.get(url, { timeout: 15_000, validateStatus: () => true }),
        );
        const ok = res.status >= 200 && res.status < 400;
        return {
          status_qualidade: ok ? 'OK_ENDPOINT_BASE' : `HTTP_${res.status}`,
          detalhes_retorno: `GET ${url} → ${res.status}`,
        };
      } catch (e) {
        return {
          status_qualidade: 'FALHA_ENDPOINT_BASE',
          detalhes_retorno: this.toDetails(e),
        };
      }
    }
    return {
      status_qualidade: 'SEM_PROBE_CONFIGURADO',
      detalhes_retorno: null,
    };
  }
}
