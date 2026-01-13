import { CampaignStatus, MessageStatus } from '@prisma/client';

export class CampaignStatusDto {
  campaign_id: string;
  agendamento_id: string;
  status: CampaignStatus;
  provider: string;
  total_messages: number;
  sent_messages: number;
  failed_messages: number;
  progress_percentage: number;
  started_at?: Date;
  completed_at?: Date;
  errors?: Array<{
    phone: string;
    error: string;
    attempts: number;
  }>;
}

export class MessageStatusDto {
  id: string;
  phone: string;
  name?: string;
  status: MessageStatus;
  attempts: number;
  last_error?: string;
  sent_at?: Date;
}

