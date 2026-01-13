import { IsString, IsNotEmpty } from 'class-validator';

export class DispatchCampaignDto {
  @IsString()
  @IsNotEmpty()
  agendamento_id: string;
}

