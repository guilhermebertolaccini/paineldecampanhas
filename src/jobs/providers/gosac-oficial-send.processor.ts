import { Processor, InjectQueue } from '@nestjs/bullmq';
import { Job, Queue } from 'bullmq';
import { GosacOficialProvider } from '../../providers/gosac-oficial/gosac-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

@Processor(queueNames.GOSAC_OFICIAL_SEND)
export class GosacOficialSendProcessor extends BaseProviderProcessor {
    protected providerName = 'GOSAC_OFICIAL';

    constructor(
        provider: GosacOficialProvider,
        prisma: PrismaService,
        webhookService: WebhookService,
        @InjectQueue(queueNames.GOSAC_OFICIAL_START) private readonly startQueue: Queue,
    ) {
        super(provider, prisma, webhookService, GosacOficialSendProcessor.name);
    }

    async process(job: Job<ProviderSendJobData>) {
        const result = await super.process(job);

        // Se a criação foi bem sucedida, enfileira o início da campanha
        if (result.success && result.data?.campaignId) {
            await this.startQueue.add('start-campaign', {
                campaignId: result.data.campaignId,
                internalCampaignId: job.data.campaignId,
                agendamentoId: job.data.agendamentoId,
                credentials: job.data.credentials,
            }, {
                delay: 5000, // Espera 5 segundos para garantir que a campanha foi processada no lado do GOSAC
                attempts: 3,
                backoff: {
                    type: 'exponential',
                    delay: 10000,
                },
            });

            this.logger.log(`📅 Job de início de campanha enfileirado para ${result.data.campaignId}`);
        }

        return result;
    }
}
