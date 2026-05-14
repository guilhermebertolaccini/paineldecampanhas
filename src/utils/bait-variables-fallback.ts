import type { CampaignData } from '../providers/base/provider.interface';

function parseMsgJson(raw: unknown): Record<string, unknown> | null {
  if (!raw || typeof raw !== 'string' || !raw.trim().startsWith('{')) return null;
  try {
    const o = JSON.parse(raw) as unknown;
    return o && typeof o === 'object' && !Array.isArray(o) ? (o as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

/** Marca aplicada pelo WordPress quando o contacto foi acrescentado como isca (`tracking_data.is_bait`). */
function mensagemIndicatesBait(parsed: Record<string, unknown> | null): boolean {
  const td = parsed?.tracking_data;
  return !!(
    td &&
    typeof td === 'object' &&
    !Array.isArray(td) &&
    ((td as Record<string, unknown>).is_bait === true ||
      String((td as Record<string, unknown>).is_bait) === '1')
  );
}

function shallowCloneVars(v: unknown): Record<string, string> | null {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return null;
  const out: Record<string, string> = {};
  for (const [k, val] of Object.entries(v as Record<string, unknown>)) {
    if (val == null) continue;
    const s = String(val).trim();
    if (s !== '') out[k] = s;
  }
  return Object.keys(out).length ? out : null;
}

/**
 * Fallback P0 — campanhas por ficheiro: iscas não têm linha de CSV com colunas dinâmicas,
 * ficando `mensagem.variables` vazio — o Fail-Safe de variáveis bloquearia o disparo.
 * Replica o bloco JSON de variáveis da primeira linha “real” do lote sobre cada isca marcada pelo WP.
 */
export function applyDonorVariablesToBaitRows(rows: CampaignData[]): CampaignData[] {
  if (!Array.isArray(rows) || rows.length < 2) {
    return rows;
  }

  let donorParsed: Record<string, unknown> | null = null;

  for (const row of rows) {
    const p = parseMsgJson(row.mensagem);
    if (!p || mensagemIndicatesBait(p)) continue;

    const vars = shallowCloneVars(p.variables);
    if (vars && Object.keys(vars).length > 0) {
      donorParsed = p;
      break;
    }

    const cvs = p.contact_variables as unknown[] | undefined;
    if (
      Array.isArray(cvs) &&
      cvs.length > 0 &&
      (cvs as { value?: unknown }[]).some(
        (c) =>
          c &&
          typeof c === 'object' &&
          String((c as { value?: unknown }).value ?? '').trim() !== '',
      )
    ) {
      donorParsed = p;
      break;
    }
  }

  if (!donorParsed) {
    return rows;
  }

  const donorVars = donorParsed.variables;
  const donorContactVars = donorParsed.contact_variables;
  const donorComponents = donorParsed.components;

  return rows.map((row) => {
    const p = parseMsgJson(row.mensagem);
    if (!p || !mensagemIndicatesBait(p)) {
      return row;
    }

    const out: Record<string, unknown> = { ...p };
    /* Copia apenas blocos templating do doador; mantém dados da isca em `mensagem`/REST. */
    if (donorVars && typeof donorVars === 'object' && !Array.isArray(donorVars)) {
      out.variables = JSON.parse(JSON.stringify(donorVars)) as Record<string, unknown>;
    }
    if (Array.isArray(donorContactVars) && donorContactVars.length > 0) {
      out.contact_variables = JSON.parse(JSON.stringify(donorContactVars));
    }
    if (donorComponents != null) {
      out.components = JSON.parse(JSON.stringify(donorComponents));
    }

    const mensagemNext = JSON.stringify(out);

    const fromMsg = shallowCloneVars(out.variables);
    const mergedRest: Record<string, string> = { ...(row.variables ?? {}) };
    if (fromMsg) {
      for (const [k, v] of Object.entries(fromMsg)) {
        mergedRest[k] = v;
      }
    }

    return {
      ...row,
      mensagem: mensagemNext,
      variables: Object.keys(mergedRest).length > 0 ? mergedRest : row.variables,
    };
  });
}
