import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { firstValueFrom } from 'rxjs';
import { wordpressConfig } from '../config/wordpress.config';

export type EvolutionInstance = { name: string; status: string };

type EvolutionCredentials = {
  baseUrl: string;
  token: string;
  source: 'env' | 'wp_options' | 'wp-config' | 'none';
};

/** TTL do cache de credenciais vindas do WP, em ms (60s). */
const CREDENTIALS_CACHE_TTL_MS = 60_000;

/**
 * Cliente mínimo da Evolution API (mesmo contrato do validador PHP).
 *
 * Credenciais são resolvidas nesta ordem:
 *   1) Env vars do Nest (EVOLUTION_API_URL / EVOLUTION_API_TOKEN) — source="env"
 *   2) WordPress REST GET /wp-json/pc/v1/evolution/config (X-API-KEY = WORDPRESS_API_KEY)
 *      retornando config salva via ApiManager em wp_options ou constantes em wp-config.php.
 */
@Injectable()
export class EvolutionApiService {
  private readonly logger = new Logger(EvolutionApiService.name);

  private cachedCreds: EvolutionCredentials | null = null;
  private cachedAt = 0;

  constructor(
    private readonly http: HttpService,
    private readonly config: ConfigService,
  ) {}

  private envBaseUrl(): string {
    return (this.config.get<string>('EVOLUTION_API_URL') || '').replace(/\/+$/, '');
  }

  private envToken(): string {
    return (this.config.get<string>('EVOLUTION_API_TOKEN') || '').trim();
  }

  /** Invalida o cache de credenciais (útil em testes / após salvar no WP). */
  invalidateCredentialsCache(): void {
    this.cachedCreds = null;
    this.cachedAt = 0;
  }

  /**
   * Resolve as credenciais. Usa env vars se presentes; caso contrário, busca
   * do WordPress (endpoint pc/v1/evolution/config) com cache curto.
   */
  async resolveCredentials(): Promise<EvolutionCredentials> {
    // 1) ENV wins
    const envBase = this.envBaseUrl();
    const envTok = this.envToken();
    if (envBase !== '' && envTok !== '') {
      return { baseUrl: envBase, token: envTok, source: 'env' };
    }

    // 2) Cache
    const now = Date.now();
    if (this.cachedCreds && now - this.cachedAt < CREDENTIALS_CACHE_TTL_MS) {
      if (this.cachedCreds.baseUrl !== '' && this.cachedCreds.token !== '') {
        return this.cachedCreds;
      }
    }

    // 3) Fallback: WordPress
    const wpBase = wordpressConfig.url.replace(/\/+$/, '');
    const wpKey = wordpressConfig.apiKey;
    if (!wpBase || !wpKey) {
      const empty: EvolutionCredentials = { baseUrl: '', token: '', source: 'none' };
      this.cachedCreds = empty;
      this.cachedAt = now;
      return empty;
    }

    const url = `${wpBase}/wp-json/pc/v1/evolution/config`;
    try {
      const res = await firstValueFrom(
        this.http.get<{
          success?: boolean;
          api_url?: string;
          token?: string;
          source?: string;
          is_configured?: boolean;
        }>(url, {
          headers: { 'X-API-KEY': wpKey, Accept: 'application/json' },
          timeout: 10_000,
          validateStatus: () => true,
        }),
      );
      if (res.status !== 200 || !res.data || res.data.success !== true) {
        this.logger.warn(
          `Evolution config lookup @ WP falhou (status=${res.status}). Caindo para vazio.`,
        );
        const empty: EvolutionCredentials = { baseUrl: '', token: '', source: 'none' };
        this.cachedCreds = empty;
        this.cachedAt = now;
        return empty;
      }
      const baseUrl = (res.data.api_url || '').replace(/\/+$/, '');
      const token = (res.data.token || '').trim();
      const rawSource = (res.data.source || '').toLowerCase();
      const source: EvolutionCredentials['source'] =
        rawSource === 'wp-config'
          ? 'wp-config'
          : rawSource === 'wp_options'
            ? 'wp_options'
            : 'none';

      const creds: EvolutionCredentials = { baseUrl, token, source };
      this.cachedCreds = creds;
      this.cachedAt = now;

      if (creds.baseUrl !== '' && creds.token !== '') {
        this.logger.log(
          `Evolution credentials carregadas via WordPress (source=${creds.source}).`,
        );
      } else {
        this.logger.warn(
          `Evolution credentials ausentes no WordPress (source=${creds.source}). ` +
            `Configure em "API Manager → Evolution API" ou defina EVOLUTION_API_URL/EVOLUTION_API_TOKEN no Nest.`,
        );
      }
      return creds;
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.logger.warn(`Erro ao buscar Evolution config no WP: ${msg}`);
      const empty: EvolutionCredentials = { baseUrl: '', token: '', source: 'none' };
      this.cachedCreds = empty;
      this.cachedAt = now;
      return empty;
    }
  }

  async isConfigured(): Promise<boolean> {
    const c = await this.resolveCredentials();
    return c.baseUrl !== '' && c.token !== '';
  }

  normalizePhoneBr(raw: string): string {
    const d = String(raw).replace(/\D+/g, '');
    if (!d) return '';
    if (d.length >= 10 && d.length <= 11 && d[0] !== '0') {
      return `55${d}`;
    }
    return d;
  }

  private normalizeInstancesList(data: unknown): unknown[] {
    if (!data || typeof data !== 'object') return [];
    const o = data as Record<string, unknown>;
    if (Array.isArray(o.response)) {
      const inner = o.response as unknown[];
      if (inner[0] !== undefined) return inner;
      const r0 = inner as unknown;
      if (r0 && typeof r0 === 'object') {
        const ri = r0 as Record<string, unknown>;
        if (ri.instance && typeof ri.instance === 'object') return [r0];
        if (ri.instanceName || ri.name) return [r0];
      }
      return Object.values(inner).filter((v) => v && typeof v === 'object');
    }
    if (o.data && Array.isArray(o.data) && o.data[0] !== undefined) return o.data as unknown[];
    if (o.instances && Array.isArray(o.instances)) return Object.values(o.instances);
    if (Array.isArray(data) && (data as unknown[])[0] !== undefined) return data as unknown[];
    if (o.name || o.instanceName) return [data];
    return [];
  }

  async fetchConnectedInstances(): Promise<EvolutionInstance[]> {
    const { baseUrl: base, token } = await this.resolveCredentials();
    if (!base || !token) return [];

    const url = `${base}/instance/fetchInstances`;
    try {
      const res = await firstValueFrom(
        this.http.get<unknown>(url, {
          headers: { apikey: token, Accept: 'application/json' },
          timeout: 45_000,
        }),
      );
      const list = this.normalizeInstancesList(res.data);
      const out: EvolutionInstance[] = [];
      for (const row of list) {
        if (!row || typeof row !== 'object') continue;
        let inst = row as Record<string, unknown>;
        if (inst.instance && typeof inst.instance === 'object') {
          inst = inst.instance as Record<string, unknown>;
        }
        const name =
          (typeof inst.instanceName === 'string' && inst.instanceName) ||
          (typeof inst.name === 'string' && inst.name) ||
          '';
        if (!name) continue;
        let status = '';
        for (const sk of ['status', 'connectionStatus', 'state'] as const) {
          const v = inst[sk];
          if (v !== undefined && v !== null && String(v) !== '') {
            status = String(v).toLowerCase();
            break;
          }
        }
        if (status !== 'open') continue;
        out.push({ name, status });
      }
      return out;
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.logger.warn(`fetchInstances falhou: ${msg}`);
      return [];
    }
  }

  /**
   * @returns map dígitos E.164 -> exists
   */
  async postWhatsappNumbers(
    instanceName: string,
    numbers: string[],
  ): Promise<Map<string, boolean>> {
    const { baseUrl: base, token } = await this.resolveCredentials();
    const url = `${base}/chat/whatsappNumbers/${encodeURIComponent(instanceName)}`;
    const map = new Map<string, boolean>();
    try {
      const res = await firstValueFrom(
        this.http.post<unknown>(
          url,
          { numbers: [...numbers] },
          {
            headers: {
              apikey: token,
              'Content-Type': 'application/json',
              Accept: 'application/json',
            },
            timeout: 60_000,
          },
        ),
      );
      let decoded: unknown = res.data;
      if (decoded && typeof decoded === 'object' && 'response' in (decoded as object)) {
        decoded = (decoded as { response: unknown }).response;
      }
      if (!Array.isArray(decoded)) {
        return map;
      }
      for (const item of decoded) {
        if (!item || typeof item !== 'object') continue;
        const it = item as { number?: string; exists?: boolean };
        const num = String(it.number ?? '').replace(/\D+/g, '');
        if (!num) continue;
        map.set(num, Boolean(it.exists));
      }
      return map;
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.logger.warn(`whatsappNumbers inst=${instanceName} falhou: ${msg}`);
      throw e;
    }
  }
}
