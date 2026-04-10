/**
 * Variáveis de CPF exigidas pelo bot receptivo Ótima (`extra_fields`).
 */
export type OtimaCpfBotFields = {
  CPF_PADRAO: string;
  CPF_ESPACO: string;
  CPF_3_PRIM: string;
  CPF_3_ULT: string;
};

function onlyDigits(raw: string): string {
  return raw.replace(/\D/g, '');
}

/**
 * Formata CPF para slots do bot Ótima. Apenas 11 dígitos válidos como tamanho;
 * CNPJ (14 dígitos) ou tamanhos incorretos retornam `null`.
 * Use o spread em `extra_fields` apenas quando o retorno não for `null`.
 */
export function formatCpfForBot(
  rawCpf: string | null | undefined,
): OtimaCpfBotFields | null {
  if (rawCpf === null || rawCpf === undefined) {
    return null;
  }
  try {
    const digits = onlyDigits(String(rawCpf));
    if (digits.length !== 11) {
      return null;
    }
    const d = digits;
    return {
      CPF_PADRAO: `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9, 11)}`,
      CPF_ESPACO: `${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6, 9)} ${d.slice(9, 11)}`,
      CPF_3_PRIM: d.slice(0, 3),
      CPF_3_ULT: d.slice(8, 11),
    };
  } catch {
    return null;
  }
}
