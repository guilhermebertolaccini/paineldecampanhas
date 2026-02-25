import { Processor, WorkerHost, InjectQueue } from '@nestjs/bullmq';
import { Job, Queue } from 'bullmq';
import { Logger } from '@nestjs/common';
import { GosacOficialProvider } from '../../providers/gosac-oficial/gosac-oficial.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { queueNames } from '../../config/bullmq.config';

interface GosacOficialStartJobData {
    campaignId: string; // ID da campanha no provedor (GOSAC)
    internalCampaignId: string; // ID da campanha no Prisma
    agendamentoId: string;
    credentials: any;
}

@Processor(queueNames.GOSAC_OFICIAL_START)
export class GosacOficialStartProcessor extends WorkerHost {
    private readonly logger = new Logger(GosacOficialStartProcessor.name);

    constructor(
        private readonly provider: GosacOficialProvider,
        private readonly prisma: PrismaService,
        private readonly webhookService: WebhookService,
    ) {
        super();
    }

    async process(job: Job<GosacOficialStartJobData>): Promise<any> {
        const { campaignId, internalCampaignId, agendamentoId, credentials } = job.data;

        this.logger.log(`🚀 Iniciando campanha oficial no GOSAC: ${campaignId}`);

        try {
            const result = await this.provider.startCampaign(campaignId, credentials);

            if (result.success) {
                this.logger.log(`✅ Campanha ${campaignId} iniciada com sucesso`);

                await this.webhookService.sendStatusUpdate({
                    agendamento_id: agendamentoId,
                    status: 'iniciado',
                    resposta_api: JSON.stringify(result.data),
                    data_disparo: new Date().toISOString(),
                    total_enviados: 0,
                    total_falhas: 0,
                    provider: 'GOSAC_OFICIAL',
                });

                return result;
            } else {
                this.logger.error(`❌ Erro ao iniciar campanha ${campaignId}: ${result.error}`);

                await this.webhookService.sendStatusUpdate({
                    agendamento_id: agendamentoId,
                    status: 'erro_inicio',
                    resposta_api: result.error || 'Erro ao iniciar campanha',
                    data_disparo: new Date().toISOString(),
                    total_enviados: 0,
                    total_falhas: 0,
                    provider: 'GOSAC_OFICIAL',
                });

                throw new Error(result.error || 'Erro ao iniciar campanha');
            }
        } catch (error: any) {
            this.logger.error(`❌ Erro no processador de início: ${error.message}`);
            throw error;
        }
    }
}
