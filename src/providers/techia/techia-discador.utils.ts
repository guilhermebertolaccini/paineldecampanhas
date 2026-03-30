import type { TechiaPhone } from './techia.interface';

/**
 * Normaliza dígitos e remove prefixo 55 quando presente.
 */
export function techiaStripToNationalDigits(telefone: string): string {
  let d = (telefone || '').replace(/\D/g, '');
  if (d.startsWith('55') && d.length > 4) {
    d = d.slice(2);
  }
  return d;
}

/**
 * Converte telefone brasileiro normalizado (ex.: 5514999999999 ou 14999999999)
 * no par { ddd, telefone } exigido pela TECHIA.
 *
 * Regra: após opcional `55`, espera 10 ou 11 dígitos (DDD 2 + número 8 ou 9).
 */
export function splitBrazilPhoneForTechia(telefone: string): TechiaPhone | null {
  const d = techiaStripToNationalDigits(telefone);
  if (d.length < 10 || d.length > 11) {
    return null;
  }
  return {
    ddd: d.slice(0, 2),
    telefone: d.slice(2),
  };
}

export function chunkArray<T>(items: T[], size: number): T[][] {
  if (size <= 0) {
    return [items];
  }
  const out: T[][] = [];
  for (let i = 0; i < items.length; i += size) {
    out.push(items.slice(i, i + size));
  }
  return out;
}
