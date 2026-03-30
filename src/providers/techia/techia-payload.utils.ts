import type { CampaignData } from '../base/provider.interface';

export interface TechiaRowVariables {
  campanha_origem?: string;
  contrato?: string;
  documento?: string;
  valor?: string;
  atraso?: string;
  COD_DEPARA?: string;
  nome?: string;
  [key: string]: string | undefined;
}

/**
 * Lê `variables` do JSON gravado pelo WordPress (campanha por arquivo TECHIA).
 */
export function parseTechiaVariablesFromMensagem(
  mensagem: string | undefined,
): TechiaRowVariables {
  if (!mensagem || typeof mensagem !== 'string') {
    return {};
  }
  const trimmed = mensagem.trim();
  if (!trimmed.startsWith('{')) {
    return {};
  }
  try {
    const j = JSON.parse(trimmed) as Record<string, unknown>;
    if (j.template_source !== 'techia_discador') {
      return {};
    }
    const v = j.variables;
    if (!v || typeof v !== 'object' || Array.isArray(v)) {
      return {};
    }
    const out: TechiaRowVariables = {};
    for (const [k, val] of Object.entries(v as Record<string, unknown>)) {
      if (val == null) {
        out[k] = '';
      } else {
        out[k] = String(val);
      }
    }
    return out;
  } catch {
    return {};
  }
}

export function pickTechiaScalar(
  vars: TechiaRowVariables,
  key: string,
  rowFallback: string,
): string {
  const v = vars[key];
  if (v !== undefined && v !== null && String(v).trim() !== '') {
    return String(v).trim();
  }
  return String(rowFallback ?? '').trim();
}
