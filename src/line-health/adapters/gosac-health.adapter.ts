import type { StandardLineHealth } from './standard-line-health.interface';

function strOrNull(v: unknown): string | null {
  if (v === null || v === undefined) {
    return null;
  }
  if (typeof v === 'object') {
    try {
      return JSON.stringify(v);
    } catch {
      return String(v);
    }
  }
  const s = String(v).trim();
  return s.length ? s : null;
}

function collectConnections(root: Record<string, unknown>): unknown[] {
  if (Array.isArray(root.connections)) {
    return root.connections;
  }
  const data = root.data;
  if (!Array.isArray(data)) {
    return [];
  }
  const flat: unknown[] = [];
  for (const envItem of data) {
    if (
      envItem !== null &&
      typeof envItem === 'object' &&
      Array.isArray((envItem as Record<string, unknown>).connections)
    ) {
      flat.push(...(envItem as Record<string, unknown>).connections as unknown[]);
    }
  }
  return flat;
}

/**
 * Normaliza o JSON bruto do endpoint GOSAC Oficial (`connections` na raiz ou em `data[].connections`).
 */
export class GosacHealthAdapter {
  private constructor() {}

  static adapt(rawPayload: unknown): StandardLineHealth[] {
    if (rawPayload === null || typeof rawPayload !== 'object') {
      return [];
    }
    const connections = collectConnections(rawPayload as Record<string, unknown>);
    const out: StandardLineHealth[] = [];
    for (const c of connections) {
      if (c === null || typeof c !== 'object') {
        continue;
      }
      const conn = c as Record<string, unknown>;
      const id_externo = String(conn.id ?? '').trim();
      if (!id_externo) {
        continue;
      }
      const restriction = conn.accountRestriction;
      const status_conexao =
        restriction === null || restriction === undefined
          ? 'CONNECTED'
          : 'RESTRICTED';
      out.push({
        provedor: 'GOSAC',
        id_externo,
        nome_linha: String(conn.name ?? '').trim() || id_externo,
        numero_telefone: null,
        status_conexao,
        limite_mensagens: strOrNull(conn.messagingLimit),
        restricao_conta: strOrNull(restriction),
        waba_id: null,
        waba_phone_id: null,
        dados_brutos: c,
      });
    }
    return out;
  }
}
