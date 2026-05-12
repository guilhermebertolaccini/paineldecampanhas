import { Logger } from '@nestjs/common';
import type { CampaignData } from './provider.interface';

type VariablesMapPhp = Record<string, { type?: string; value?: unknown } | string>;

function trimStr(v: unknown): string {
  if (v == null) return '';
  return String(v).trim();
}

function parseMensagemJson(mensagem: string): Record<string, unknown> | null {
  if (!mensagem || typeof mensagem !== 'string' || !mensagem.trim().startsWith('{')) {
    return null;
  }
  try {
    const p = JSON.parse(mensagem) as unknown;
    return p && typeof p === 'object' && !Array.isArray(p) ? (p as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

function lineVariablesFromParsed(parsed: Record<string, unknown>): Record<string, unknown> {
  const v = parsed.variables;
  if (v && typeof v === 'object' && !Array.isArray(v)) {
    return v as Record<string, unknown>;
  }
  return {};
}

function pickCi(obj: Record<string, unknown>, key: string): string {
  const k = trimStr(key);
  if (!k) return '';
  const exact = trimStr(obj[k]);
  if (exact !== '') return exact;
  const low = k.toLowerCase();
  const found = Object.keys(obj).find((x) => x.toLowerCase() === low);
  if (found == null) return '';
  return trimStr(obj[found]);
}

/** Normaliza chave estilo "{{1}}" → "1" para fallback de lookup. */
function stripMustacheAlias(name: string): string[] {
  const t = trimStr(name);
  const out = new Set<string>();
  if (t) out.add(t);
  const m = t.match(/^\{\{([^}]+)\}\}$/u);
  if (m?.[1]) {
    const inner = trimStr(m[1]);
    if (inner) out.add(inner);
  }
  return [...out];
}

/**
 * Extrai variables_map estável da campanha (1ª mensagem JSON com mapa não vazio).
 */
export function extractVariablesMapFromBatch(
  batch: CampaignData[],
): { map: VariablesMapPhp; templateSource?: string } | null {
  for (const row of batch) {
    const p = parseMensagemJson(row.mensagem);
    if (!p) continue;
    const vm = p.variables_map;
    if (vm && typeof vm === 'object' && !Array.isArray(vm) && Object.keys(vm).length > 0) {
      return {
        map: vm as VariablesMapPhp,
        templateSource:
          typeof p.template_source === 'string' ? p.template_source : undefined,
      };
    }
  }
  return null;
}

/**
 * Fail-safe P0: se o usuário mapeou variáveis de template (`variables_map`) e o valor
 * final (PHP `mensagem.variables` ou espelho REST) estiver vazio, não envia ao fornecedor.
 */
export function enforceTemplateVariableIntegrity(
  batch: CampaignData[],
  providerName: string,
  logger: Logger,
): void {
  if (!batch?.length) {
    return;
  }

  const extracted = extractVariablesMapFromBatch(batch);
  if (!extracted) {
    return;
  }

  const { map: variablesMap } = extracted;

  const first = batch[0];
  const firstParsed = parseMensagemJson(first.mensagem);
  const firstLineVars = firstParsed ? lineVariablesFromParsed(firstParsed) : {};

  const previewAudit: Record<string, unknown> = {
    provider: providerName,
    telefone_linha_1: first.telefone,
    template_source: firstParsed?.template_source,
    variables_map_keys: Object.keys(variablesMap),
    variables_mensagem_linha_1: firstLineVars,
    variables_rest_linha_1: first.variables ?? {},
  };

  logger.error(
    `[VAR DEBUG] Auditoria 1ª linha do lote (pré-envio): ${JSON.stringify(previewAudit)}`,
  );

  for (const item of batch) {
    const phone = trimStr(item.telefone) || '(sem telefone)';
    const parsed = parseMensagemJson(item.mensagem);
    const lineVars = parsed ? lineVariablesFromParsed(parsed) : {};
    const restVars = (item.variables ?? {}) as Record<string, unknown>;

    const ts = typeof parsed?.template_source === 'string' ? parsed.template_source : '';
    if (ts === 'gosac_oficial' && Array.isArray(parsed?.contact_variables)) {
      const list = parsed.contact_variables as Array<{ value?: unknown; variable?: unknown }>;
      if (list.length > 0) {
        for (let i = 0; i < list.length; i++) {
          const cv = list[i];
          const label = trimStr(cv?.variable) || `pos_${i + 1}`;
          const val = trimStr(cv?.value);
          if (val === '') {
            throw new Error(
              `FALHA DE SEGURANÇA: Variável GOSAC "${label}" está vazia para o número ${phone} (componente ${i + 1})`,
            );
          }
        }
        continue;
      }
    }

    for (const [varName, rawEntry] of Object.entries(variablesMap)) {
      const aliases = stripMustacheAlias(varName);

      let isText = false;
      let staticText = '';
      let columnHint = '';

      if (typeof rawEntry === 'string') {
        columnHint = trimStr(rawEntry);
      } else if (rawEntry && typeof rawEntry === 'object') {
        const t = trimStr((rawEntry as { type?: string }).type).toLowerCase();
        if (t === 'text') {
          isText = true;
          staticText = trimStr((rawEntry as { value?: unknown }).value);
        } else {
          columnHint = trimStr((rawEntry as { value?: unknown }).value);
        }
      }

      if (isText) {
        if (staticText === '') {
          throw new Error(
            `FALHA DE SEGURANÇA: Variável "${varName}" (texto fixo) está vazia para o número ${phone}`,
          );
        }
        continue;
      }

      let resolved = '';
      for (const alias of aliases) {
        resolved = pickCi(lineVars, alias);
        if (resolved !== '') break;
      }
      if (resolved === '' && columnHint) {
        resolved = pickCi(lineVars, columnHint);
      }
      if (resolved === '' && columnHint) {
        resolved = pickCi(restVars, columnHint);
      }
      for (const alias of aliases) {
        if (resolved !== '') break;
        resolved = pickCi(restVars, alias);
      }

      if (resolved === '') {
        throw new Error(
          `FALHA DE SEGURANÇA: Variável "${varName}" está vazia para o número ${phone} | provider=${providerName}`,
        );
      }
    }
  }
}
