import { Processor } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { TechiaProvider } from '../../providers/techia/techia.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { DigitalFunnelMssqlService } from '../../sql-server/digital-funnel-mssql.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.TECHIA_SEND)
export class TechiaSendProcessor extends BaseProviderProcessor {
  protected providerName = 'TECHIA';

  constructor(
    provider: TechiaProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
    digitalFunnel: DigitalFunnelMssqlService,
  ) {
    super(provider, prisma, webhookService, digitalFunnel, TechiaSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}
