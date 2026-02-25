import { Processor, WorkerHost } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { Logger } from '@nestjs/common';
import { CampaignsService } from '../campaigns/campaigns.service';
import { queueNames } from '../config/bullmq.config';
import { InjectQueue } from '@nestjs/bullmq';
import { Queue } from 'bullmq';

@Processor(queueNames.DISPATCH_CAMPAIGN)
export class DispatchCampaignProcessor extends WorkerHost {
  private readonly logger = new Logger(DispatchCampaignProcessor.name);

  constructor(
    private readonly campaignsService: CampaignsService,
    @InjectQueue(queueNames.CDA_SEND) private readonly cdaQueue: Queue,
    @InjectQueue(queueNames.GOSAC_SEND) private readonly gosacQueue: Queue,
    @InjectQueue(queueNames.NOAH_SEND) private readonly noahQueue: Queue,
    @InjectQueue(queueNames.RCS_SEND) private readonly rcsQueue: Queue,
    @InjectQueue(queueNames.RCS_OTIMA_SEND) private readonly rcsOtimaQueue: Queue,
    @InjectQueue(queueNames.WHATSAPP_OTIMA_SEND) private readonly whatsappOtimaQueue: Queue,
    @InjectQueue(queueNames.SALESFORCE_SEND) private readonly salesforceQueue: Queue,
    @InjectQueue(queueNames.GOSAC_OFICIAL_SEND) private readonly gosacOficialQueue: Queue,
  ) {
    super();
  }

  async process(job: Job<{ agendamento_id: string }>) {
    const { agendamento_id } = job.data;
    this.logger.log(`Processing campaign dispatch: ${agendamento_id}`);

    try {
      // 1. Identificar provider
      const provider = this.campaignsService.identifyProvider(agendamento_id);
      this.logger.log(`Provider identified: ${provider}`);

      // 2. Buscar dados no WordPress
      const data = await this.campaignsService.fetchDataFromWordPress(agendamento_id);
      this.logger.log(`Fetched ${data.length} records from WordPress`);

      // 3. Buscar credenciais
      const envId = data[0]?.idgis_ambiente;
      if (!envId) {
        throw new Error('idgis_ambiente não encontrado nos dados');
      }

      const credentials = await this.campaignsService.fetchCredentials(provider, envId);
      this.logger.log(`Credentials fetched for ${provider}:${envId}`);

      // 4. Buscar configuração de throttling
      const throttlingConfig = await this.campaignsService.fetchThrottlingConfig(agendamento_id);
      this.logger.log(`Throttling config: ${JSON.stringify(throttlingConfig)}`);

      // 5. Criar campanha no banco
      const campaign = await this.campaignsService.createCampaign(
        agendamento_id,
        provider,
        data.length,
      );
      this.logger.log(`Campaign created: ${campaign.id}`);

      // 6. Criar mensagens no banco
      await this.campaignsService.createCampaignMessages(campaign.id, data);
      this.logger.log(`Created ${data.length} campaign messages`);

      // 7. Calcular distribuição e adicionar jobs
      const batches = this.distributeMessages(data, throttlingConfig);
      this.logger.log(`Campaign split into ${batches.length} batches`);

      const providerQueue = this.getProviderQueue(provider);

      let jobsAdded = 0;
      for (const batch of batches) {
        await providerQueue.add(
          `${provider.toLowerCase()}-send`,
          {
            campaignId: campaign.id,
            agendamentoId: agendamento_id,
            data: batch.records,
            credentials,
          },
          {
            attempts: 5,
            delay: batch.delay, // Aplica o atraso calculado
            backoff: {
              type: 'exponential',
              delay: 60000,
            },
          },
        );
        jobsAdded++;
      }

      this.logger.log(`${jobsAdded} Jobs added to ${provider} queue`);

      return {
        campaignId: campaign.id,
        provider,
        totalMessages: data.length,
        batches: batches.length,
        throttling: throttlingConfig.throttling_type
      };
    } catch (error: any) {
      this.logger.error(`Error processing campaign: ${error.message}`, error.stack);
      throw error;
    }
  }

  private distributeMessages(data: any[], config: any): { records: any[], delay: number }[] {
    const type = config?.throttling_type || 'none';
    const batches: { records: any[], delay: number }[] = [];
    const total = data.length;

    if (total === 0) return [];

    if (type === 'none') {
      batches.push({ records: data, delay: 0 });
      return batches;
    }

    if (type === 'linear') {
      const qtdMsgs = parseInt(config?.throttling_config?.qtd_msgs) || 100;
      const intervalMinutes = parseInt(config?.throttling_config?.intervalo_minutos) || 60;
      const intervalMs = intervalMinutes * 60 * 1000;

      for (let i = 0; i < total; i += qtdMsgs) {
        const chunk = data.slice(i, i + qtdMsgs);
        // O primeiro lote vai imediatamente (delay 0), o segundo após intervalMs, etc.
        const delay = (i / qtdMsgs) * intervalMs;
        batches.push({ records: chunk, delay });
      }
      return batches;
    }

    if (type === 'split') {
      const cfg = config?.throttling_config || {};
      const fase1Percent = parseInt(cfg.fase1_percent) || 70;
      const fase1Hours = parseFloat(cfg.fase1_horas) || 2;
      const fase2Hours = parseFloat(cfg.fase2_horas) || 4;

      const splitIndex = Math.floor(total * (fase1Percent / 100));
      const phase1Data = data.slice(0, splitIndex);
      const phase2Data = data.slice(splitIndex);

      // Distribui Fase 1
      const phase1DurationMs = fase1Hours * 3600 * 1000;
      // Divide em 10 chunks ou menos se houver poucos dados
      const chunks1 = Math.min(10, Math.ceil(phase1Data.length / 10)); // Mínimo de 10 registros por chunk
      const chunkSize1 = Math.ceil(phase1Data.length / chunks1);
      const interval1 = chunks1 > 1 ? phase1DurationMs / chunks1 : 0;

      for (let i = 0; i < chunks1; i++) {
        const start = i * chunkSize1;
        const end = start + chunkSize1;
        const chunk = phase1Data.slice(start, end);
        if (chunk.length === 0) break;
        batches.push({ records: chunk, delay: i * interval1 });
      }

      // Distribui Fase 2
      const phase2DurationMs = fase2Hours * 3600 * 1000;
      const chunks2 = Math.min(10, Math.ceil(phase2Data.length / 10));
      const chunkSize2 = Math.ceil(phase2Data.length / chunks2);
      const interval2 = chunks2 > 1 ? phase2DurationMs / chunks2 : 0;
      const phase2StartDelay = phase1DurationMs; // Começa após o fim da Fase 1

      for (let i = 0; i < chunks2; i++) {
        const start = i * chunkSize2;
        const end = start + chunkSize2;
        const chunk = phase2Data.slice(start, end);
        if (chunk.length === 0) break;
        batches.push({ records: chunk, delay: phase2StartDelay + (i * interval2) });
      }

      return batches;
    }

    // Default fallback
    batches.push({ records: data, delay: 0 });
    return batches;
  }

  private getProviderQueue(provider: string): Queue {
    const queueMap: Record<string, Queue> = {
      'CDA': this.cdaQueue,
      'GOSAC': this.gosacQueue,
      'NOAH': this.noahQueue,
      'RCS': this.rcsQueue,
      'RCS_OTIMA': this.rcsOtimaQueue,
      'WHATSAPP_OTIMA': this.whatsappOtimaQueue,
      'SALESFORCE': this.salesforceQueue,
      'GOSAC_OFICIAL': this.gosacOficialQueue,
    };

    const queue = queueMap[provider];
    if (!queue) {
      throw new Error(`Queue não encontrada para provider: ${provider}`);
    }

    return queue;
  }
}

