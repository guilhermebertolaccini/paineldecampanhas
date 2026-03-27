import { Processor } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { GosacOficialProvider } from '../../providers/gosac-oficial/gosac-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProviderProcessor, ProviderSendJobData } from './base-provider.processor';
import { queueNames } from '../../config/bullmq.config';

/**
 * GoSAC Oficial: o POST de criação já coloca a campanha em execução.
 * Não enfileirar PUT /status/started — segunda chamada falha e gera falso erro no BullMQ.
 */
@Processor(queueNames.GOSAC_OFICIAL_SEND)
export class GosacOficialSendProcessor extends BaseProviderProcessor {
    protected providerName = 'GOSAC_OFICIAL';

    constructor(
        provider: GosacOficialProvider,
        prisma: PrismaService,
        webhookService: WebhookService,
    ) {
        super(provider, prisma, webhookService, GosacOficialSendProcessor.name);
    }

    async process(job: Job<ProviderSendJobData>) {
        return super.process(job);
    }
}
