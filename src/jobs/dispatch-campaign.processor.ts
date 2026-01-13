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

      // 4. Criar campanha no banco
      const campaign = await this.campaignsService.createCampaign(
        agendamento_id,
        provider,
        data.length,
      );
      this.logger.log(`Campaign created: ${campaign.id}`);

      // 5. Criar mensagens no banco
      await this.campaignsService.createCampaignMessages(campaign.id, data);
      this.logger.log(`Created ${data.length} campaign messages`);

      // 5. Adicionar job específico do provider
      const providerQueue = this.getProviderQueue(provider);
      await providerQueue.add(
        `${provider.toLowerCase()}-send`,
        {
          campaignId: campaign.id,
          agendamentoId: agendamento_id,
          data,
          credentials,
        },
        {
          attempts: 5,
          backoff: {
            type: 'exponential',
            delay: 60000, // 1 minuto inicial
          },
        },
      );

      this.logger.log(`Job added to ${provider} queue`);

      return {
        campaignId: campaign.id,
        provider,
        totalMessages: data.length,
      };
    } catch (error: any) {
      this.logger.error(`Error processing campaign: ${error.message}`, error.stack);
      throw error;
    }
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
    };

    const queue = queueMap[provider];
    if (!queue) {
      throw new Error(`Queue não encontrada para provider: ${provider}`);
    }

    return queue;
  }
}

