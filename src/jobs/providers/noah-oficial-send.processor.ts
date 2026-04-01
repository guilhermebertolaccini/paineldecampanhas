import { Processor } from '@nestjs/bullmq';
import { NoahOficialProvider } from '../../providers/noah-oficial/noah-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { DigitalFunnelMssqlService } from '../../sql-server/digital-funnel-mssql.service';
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
    digitalFunnel: DigitalFunnelMssqlService,
  ) {
    super(provider, prisma, webhookService, digitalFunnel, NoahOficialSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}
