import { Processor } from '@nestjs/bullmq';
import { GosacProvider } from '../../providers/gosac/gosac.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';
import { Job } from 'bullmq';

@Processor(queueNames.GOSAC_SEND)
export class GosacSendProcessor extends BaseProviderProcessor {
  protected providerName = 'GOSAC';

  constructor(
    provider: GosacProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, GosacSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}

