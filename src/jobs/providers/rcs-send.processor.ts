import { Processor } from '@nestjs/bullmq';
import { RcsProvider } from '../../providers/rcs/rcs.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';
import { Job } from 'bullmq';

@Processor(queueNames.RCS_SEND)
export class RcsSendProcessor extends BaseProviderProcessor {
  protected providerName = 'RCS';

  constructor(
    provider: RcsProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, RcsSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}

