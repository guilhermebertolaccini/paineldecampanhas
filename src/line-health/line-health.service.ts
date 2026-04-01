import { Injectable, Logger } from '@nestjs/common';
import { Cron } from '@nestjs/schedule';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { firstValueFrom } from 'rxjs';
import { CampaignsService } from '../campaigns/campaigns.service';
import { DigitalFunnelMssqlService } from '../sql-server/digital-funnel-mssql.service';

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

@Injectable()
export class LineHealthService {
  private readonly logger = new Logger(LineHealthService.name);

  constructor(
    private readonly config: ConfigService,
    private readonly campaigns: CampaignsService,
    private readonly http: HttpService,
    private readonly digitalFunnel: DigitalFunnelMssqlService,
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
    if (this.config.get<string>('LINE_HEALTH_CRON_ENABLED', 'true') !== 'true') {
      return;
    }
    const targets = this.parseTargets();
    if (targets.length === 0) {
      this.logger.debug('LINE_HEALTH_TARGETS vazio; cron de saúde ignorado.');
      return;
    }
    this.logger.log(`Checagem de saúde: ${targets.length} alvo(s).`);
    for (const t of targets) {
      const nome = t.nome_linha ?? `${t.provider}:${t.envId}`;
      try {
        const creds = (await this.campaigns.fetchCredentials(
          t.provider,
          t.envId,
        )) as Record<string, unknown>;
        const evalResult = await this.evaluateProvider(t, creds);
        await this.digitalFunnel.insertSaudeLinhaHistorico({
          id_linha: t.envId,
          nome_linha: nome,
          provedor: t.provider,
          status_qualidade: evalResult.status_qualidade,
          detalhes_retorno: evalResult.detalhes_retorno,
        });
      } catch (e) {
        this.logger.warn(`Saúde linha ${nome}: ${e}`);
        await this.digitalFunnel.insertSaudeLinhaHistorico({
          id_linha: t.envId,
          nome_linha: nome,
          provedor: t.provider,
          status_qualidade: 'ERRO_CREDENCIAL_OU_API',
          detalhes_retorno: this.toDetails(e),
        });
      }
    }
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
