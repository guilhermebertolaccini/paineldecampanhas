import { Processor, InjectQueue } from '@nestjs/bullmq';
import { SalesforceProvider } from '../../providers/salesforce/salesforce.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData, ProcessResult } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';
import { Job, Queue } from 'bullmq';

@Processor(queueNames.SALESFORCE_SEND)
export class SalesforceSendProcessor extends BaseProviderProcessor {
  protected providerName = 'SALESFORCE';

  constructor(
    provider: SalesforceProvider,
    prisma: PrismaService,
    webhookService: WebhookService,
    @InjectQueue(queueNames.SALESFORCE_MKC) private readonly mkcQueue: Queue,
  ) {
    super(provider, prisma, webhookService, SalesforceSendProcessor.name);
  }

  async process(job: Job<ProviderSendJobData>): Promise<ProcessResult> {
    const result = await super.process(job);
    
    // Se o envio foi bem-sucedido e retornou automationId, agenda o Marketing Cloud
    // O automationId vem em result.data.automationId (retornado pelo provider)
    if (result.success && result.data?.automationId) {
      const automationId = result.data.automationId;
      const delay = 20 * 60 * 1000; // 20 minutos em milissegundos
      
      this.logger.log(`📅 Agendando Marketing Cloud para ${automationId} em 20 minutos...`);
      
      await this.mkcQueue.add(
        'trigger-automation',
        {
          campaignId: job.data.campaignId,
          agendamentoId: job.data.agendamentoId,
          automationId,
          credentials: job.data.credentials,
        },
        {
          delay, // 20 minutos
          attempts: 3,
          backoff: {
            type: 'exponential',
            delay: 60000, // 1 minuto inicial
          },
        },
      );
      
      this.logger.log(`✅ Marketing Cloud agendado para executar em 20 minutos`);
    }
    
    return result;
  }
}

