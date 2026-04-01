import { Processor } from '@nestjs/bullmq';
import { RobbOficialProvider } from '../../providers/robbu-oficial/robbu-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { DigitalFunnelMssqlService } from '../../sql-server/digital-funnel-mssql.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { Job } from 'bullmq';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.ROBBU_OFICIAL_SEND)
export class RobbOficialSendProcessor extends BaseProviderProcessor {
  protected providerName = 'ROBBU_OFICIAL';

  constructor(
    provider: RobbOficialProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
    digitalFunnel: DigitalFunnelMssqlService,
  ) {
    super(provider, prisma, webhookService, digitalFunnel, RobbOficialSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>) {
    return super.process(job);
  }
}
