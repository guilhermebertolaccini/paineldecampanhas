import { Processor } from '@nestjs/bullmq';
import { NoahOficialProvider } from '../../providers/noah-oficial/noah-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { Job } from 'bullmq';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.NOAH_OFICIAL_SEND)
export class NoahOficialSendProcessor extends BaseProviderProcessor {
  protected providerName = 'NOAH_OFICIAL';

  constructor(
    provider: NoahOficialProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
  ) {
    super(provider, prisma, webhookService, NoahOficialSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}
