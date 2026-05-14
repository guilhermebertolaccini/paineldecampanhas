/** Delimitador padrão: vírgula (RFC 4180). Excel PT-BR abre corretamente com BOM UTF-8. */
export function stringifyCsvCell(val: unknown): string {
  if (val === null || val === undefined) return '';
  let s = String(val);
  if (typeof val === 'object' && !(val instanceof Date)) {
    try {
      s = JSON.stringify(val);
    } catch {
      s = String(val);
    }
  }
  const needsQuotes = /[",\r\n]/.test(s);
  const escaped = s.replace(/"/g, '""');
  return needsQuotes ? `"${escaped}"` : escaped;
}

export function objectsToCsv(
  rows: Record<string, unknown>[],
  headers?: string[],
): string {
  if (!rows.length) {
    return headers?.join(',') ?? '';
  }
  const cols = headers?.length ? headers : Object.keys(rows[0]);
  const lines = [cols.join(',')];
  for (const row of rows) {
    lines.push(cols.map((c) => stringifyCsvCell(row[c])).join(','));
  }
  return lines.join('\r\n');
}

/** BOM UTF-8 para reconhecimento pelo Excel ao abrir o ficheiro. */
export function buildCsvUtf8Bom(csv: string): string {
  return `\ufeff${csv}`;
}

export function downloadCsvUtf8(filename: string, csvBody: string): void {
  const blob = new Blob([buildCsvUtf8Bom(csvBody)], {
    type: 'text/csv;charset=utf-8',
  });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
