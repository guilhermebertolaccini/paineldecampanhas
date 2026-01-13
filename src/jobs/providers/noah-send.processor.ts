import { Processor } from '@nestjs/bullmq';
import { NoahProvider } from '../../providers/noah/noah.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';
import { Job } from 'bullmq';

@Processor(queueNames.NOAH_SEND)
export class NoahSendProcessor extends BaseProviderProcessor {
  protected providerName = 'NOAH';

  constructor(
    provider: NoahProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, NoahSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}

