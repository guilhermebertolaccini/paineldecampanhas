import { IsString, IsNotEmpty, IsOptional, IsObject } from 'class-validator';
import { Type } from 'class-transformer';

export class DispatchCampaignDto {
  @IsString()
  @IsNotEmpty()
  agendamento_id: string;

  // Credenciais estáticas enviadas pelo WordPress (opcionais, conforme o fornecedor)

  @IsOptional()
  @IsObject()
  salesforce_credentials?: Record<string, any>;

  @IsOptional()
  @IsObject()
  mkc_credentials?: Record<string, any>;

  @IsOptional()
  @IsObject()
  cda_credentials?: Record<string, any>;

  @IsOptional()
  @IsObject()
  rcs_credentials?: Record<string, any>;

  @IsOptional()
  @IsObject()
  otima_wpp_credentials?: Record<string, any>;

  @IsOptional()
  @IsObject()
  otima_rcs_credentials?: Record<string, any>;

  // Template da Ótima (opcional)
  @IsOptional()
  @IsString()
  template_code?: string;

  @IsOptional()
  @IsString()
  template_source?: string;

  // Provider customizado (opcional)
  @IsOptional()
  @IsObject()
  custom_provider_data?: Record<string, any>;

  @IsOptional()
  @IsString()
  custom_provider_key?: string;

  @IsOptional()
  @IsObject()
  custom_provider_credentials?: Record<string, any>;
}
