/**
 * Tipos para a API TECHIA — POST JSON array em
 * `/api/agendamento_discador_array`.
 *
 * A API aceita dezenas de campos opcionais na raiz; aqui fixamos os mínimos
 * acordados + `numeros` obrigatório. Campos extras podem ser anexados no
 * mapeamento (ver {@link TechiaContact}).
 */

export interface TechiaPhone {
  ddd: string;
  telefone: string;
}

/**
 * Um item do array enviado no body do POST.
 * Campos mínimos: campanha_origem, contrato, documento, numeros.
 * O índice `[key: string]` cobre coringas (`coringa1_varchar`, `valor`, etc.).
 */
export interface TechiaContact {
  campanha_origem: string;
  contrato: string;
  documento: string;
  numeros: TechiaPhone[];
  [key: string]: string | number | boolean | TechiaPhone[] | null | undefined;
}

/** Body da requisição: array de contatos. */
export type TechiaDiscadorPayload = TechiaContact[];

/** Credenciais esperadas do WordPress / API Manager (nomes podem variar). */
export interface TechiaProviderCredentials {
  /** ID da campanha no discador TECHIA (ex.: "2006"). */
  campanha_origem?: string;
  campaign_origin_id?: string;
  /** URL do endpoint; default usa {@link TECHIA_DISCADOR_DEFAULT_URL}. */
  api_url?: string;
  /**
   * TODO(TECHIA): Confirmar com o fornecedor o esquema de autenticação.
   * Candidatos: Bearer no `Authorization`, `X-Api-Key`, token em query, Basic.
   */
  bearer_token?: string;
  authorization?: string;
  api_token?: string;
  token?: string;
  /** Tamanho máximo de itens por POST (default 500, máx. sugerido 1000). */
  batch_size?: number;
}

export const TECHIA_DISCADOR_DEFAULT_URL =
  'https://digital.concilig.techiasolutions.com.br/api/agendamento_discador_array';

export const TECHIA_DEFAULT_BATCH_SIZE = 500;
export const TECHIA_MAX_BATCH_SIZE = 1000;
