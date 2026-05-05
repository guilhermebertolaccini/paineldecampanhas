export interface CampaignData {
  telefone: string;
  nome: string;
  idgis_ambiente: string;
  id_carteira?: string;
  carteira_nome?: string; // nome da carteira - para GOSAC lookup por nome quando há múltiplas carteiras com mesmo id_carteira
  idcob_contrato: string;
  cpf_cnpj: string;
  mensagem: string;
  /** NOAH Oficial / Ótima: channelId ou broker salvo na fila (REST). */
  broker_code?: string;
  /** Preenchido pelo WordPress em fluxos como TECHIA (JSON com `variables` por linha). */
  variables?: Record<string, string>;
  /**
   * Colunas extras da planilha/base (REST) — usado pela Ótima RCS/WPP para correlacionar
   * `variables_map` (ex.: CPF_PADRAO) quando não vêm na raiz do objeto nem em `variables`.
   */
  extra_fields?: Record<string, string | number | unknown>;
  /** Alias camelCase em alguns payloads REST. */
  extraFields?: Record<string, string | number | unknown>;
  data_cadastro?: string;
  midia_campanha?: string;
  /** Nome amigável da campanha (painel) — repassado ao GOSAC Oficial em `name` quando preenchido. */
  nome_campanha?: string;
  /** ID da linha no WordPress (`envios_pendentes` etc.) — usado em `externalKey` NOAH quando disponível */
  id?: string | number;
  envio_id?: string | number;
  /** ID do lote no WP — fallback de rastreio NOAH quando não houver contrato */
  agendamento_id?: string | number;
}

export interface ProviderResponse {
  success: boolean;
  message?: string;
  campaignId?: string;
  error?: string;
  data?: any;
}

export interface ProviderCredentials {
  [key: string]: any;
}

export interface IProvider {
  send(data: CampaignData[], credentials: ProviderCredentials): Promise<ProviderResponse>;
  validateCredentials(credentials: ProviderCredentials): boolean;
  getRetryStrategy(): RetryStrategy;
}

export interface RetryStrategy {
  maxRetries: number;
  delays: number[];
}

export enum ErrorType {
  NETWORK_ERROR = 'NETWORK_ERROR',
  API_ERROR_4XX = 'API_ERROR_4XX',
  API_ERROR_5XX = 'API_ERROR_5XX',
  VALIDATION_ERROR = 'VALIDATION_ERROR',
  TIMEOUT = 'TIMEOUT',
}

