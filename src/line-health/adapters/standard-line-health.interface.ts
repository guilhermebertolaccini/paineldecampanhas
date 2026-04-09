/**
 * Contrato canônico da camada ETL de Saúde das Linhas (antes do MERGE no MSSQL).
 */
export interface StandardLineHealth {
  provedor: string;
  id_externo: string;
  nome_linha: string;
  numero_telefone: string | null;
  status_conexao: string;
  limite_mensagens: string | null;
  restricao_conta: string | null;
  waba_id: string | null;
  waba_phone_id: string | null;
  /** Payload original do provedor (auditoria / reprocessamento). */
  dados_brutos: unknown;
}
