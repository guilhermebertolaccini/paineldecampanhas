import type { StandardLineHealth } from './standard-line-health.interface';

function strOrNull(v: unknown): string | null {
  if (v === null || v === undefined) {
    return null;
  }
  const s = String(v).trim();
  return s.length ? s : null;
}

/**
 * Normaliza a lista de canais/linhas retornada pela API NOAH (formato bruto).
 */
export class NoahHealthAdapter {
  private constructor() {}

  static adapt(rawPayload: unknown): StandardLineHealth[] {
    if (!Array.isArray(rawPayload)) {
      return [];
    }
    const out: StandardLineHealth[] = [];
    for (const item of rawPayload) {
      if (item === null || typeof item !== 'object') {
        continue;
      }
      const row = item as Record<string, unknown>;
      const id_externo = String(row.id ?? row.channelId ?? '').trim();
      if (!id_externo) {
        continue;
      }
      out.push({
        provedor: 'NOAH',
        id_externo,
        nome_linha: String(row.name ?? row.verified_name ?? '').trim() || id_externo,
        numero_telefone: strOrNull(
          row.number ?? row.display_phone_number ?? row.phoneNumber,
        ),
        status_conexao: String(row.status ?? ''),
        limite_mensagens: null,
        restricao_conta: null,
        waba_id: strOrNull(row.wabaId),
        waba_phone_id: strOrNull(row.wabaPhoneNumberId),
        dados_brutos: item,
      });
    }
    return out;
  }
}
