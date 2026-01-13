import { Processor } from '@nestjs/bullmq';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { WhatsappOtimaProvider } from '../../providers/whatsapp-otima/whatsapp-otima.provider';
import { BaseProviderProcessor } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.WHATSAPP_OTIMA_SEND)
export class WhatsappOtimaSendProcessor extends BaseProviderProcessor {
  protected providerName = 'WHATSAPP_OTIMA';

  constructor(
    provider: WhatsappOtimaProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, WhatsappOtimaSendProcessor.name);
  }
}
