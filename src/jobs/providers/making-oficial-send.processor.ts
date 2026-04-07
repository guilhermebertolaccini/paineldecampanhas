import { Processor } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { MakingOficialProvider } from '../../providers/making-oficial/making-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { DigitalFunnelMssqlService } from '../../sql-server/digital-funnel-mssql.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.MAKING_OFICIAL_SEND)
export class MakingOficialSendProcessor extends BaseProviderProcessor {
  protected providerName = 'MAKING_OFICIAL';

  constructor(
    provider: MakingOficialProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
    digitalFunnel: DigitalFunnelMssqlService,
  ) {
    super(provider, prisma, webhookService, digitalFunnel, MakingOficialSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}
