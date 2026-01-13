import { Processor } from '@nestjs/bullmq';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { RcsOtimaProvider } from '../../providers/rcs-otima/rcs-otima.provider';
import { BaseProviderProcessor } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.RCS_OTIMA_SEND)
export class RcsOtimaSendProcessor extends BaseProviderProcessor {
  protected providerName = 'RCS_OTIMA';

  constructor(
    provider: RcsOtimaProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, RcsOtimaSendProcessor.name);
  }
}
