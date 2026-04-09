import type { LineHealthSyncRow } from '../sql-server/mssql.service';
import type { StandardLineHealth } from './adapters/standard-line-health.interface';

export type LineHealthTargetLike = {
  provider: string;
  envId: string;
  nome_linha?: string;
};

/**
 * Monta a linha enviada ao snapshot MSSQL / histórico Digital Funnel.
 * `id_linha` composto evita colisão entre carteiras que compartilham o mesmo `id` de conexão no provedor.
 */
export function standardLineHealthToSyncRow(
  target: LineHealthTargetLike,
  standard: StandardLineHealth,
): LineHealthSyncRow {
  const nome =
    standard.nome_linha.trim() ||
    (target.nome_linha ?? '').trim() ||
    `${target.provider}:${target.envId}`;
  const id_linha = `${target.envId}:${standard.id_externo}`;
  return {
    id_linha,
    nome_linha: nome.slice(0, 512),
    provedor: target.provider,
    status_qualidade: standard.status_conexao,
    detalhes_retorno: null,
    standard_line_health: standard,
  };
}
