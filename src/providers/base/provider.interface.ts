export interface CampaignData {
  telefone: string;
  nome: string;
  idgis_ambiente: string;
  idcob_contrato: string;
  cpf_cnpj: string;
  mensagem: string;
  data_cadastro?: string;
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

