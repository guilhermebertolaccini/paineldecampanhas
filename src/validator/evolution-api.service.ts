import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { firstValueFrom } from 'rxjs';

export type EvolutionInstance = { name: string; status: string };

/**
 * Cliente mínimo da Evolution API (mesmo contrato do validador PHP).
 */
@Injectable()
export class EvolutionApiService {
  private readonly logger = new Logger(EvolutionApiService.name);

  constructor(
    private readonly http: HttpService,
    private readonly config: ConfigService,
  ) {}

  private baseUrl(): string {
    return (this.config.get<string>('EVOLUTION_API_URL') || '').replace(/\/+$/, '');
  }

  private token(): string {
    return (this.config.get<string>('EVOLUTION_API_TOKEN') || '').trim();
  }

  isConfigured(): boolean {
    return this.baseUrl() !== '' && this.token() !== '';
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
    const base = this.baseUrl();
    const token = this.token();
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
    const base = this.baseUrl();
    const token = this.token();
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
