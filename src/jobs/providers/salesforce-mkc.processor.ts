import { Processor, WorkerHost } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { Logger } from '@nestjs/common';
import { SalesforceProvider } from '../../providers/salesforce/salesforce.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { queueNames } from '../../config/bullmq.config';

interface SalesforceMkcJobData {
  campaignId: string;
  agendamentoId: string;
  automationId: string;
  credentials: any;
}

@Processor(queueNames.SALESFORCE_MKC)
export class SalesforceMkcProcessor extends WorkerHost {
  private readonly logger = new Logger(SalesforceMkcProcessor.name);

  constructor(
    private readonly provider: SalesforceProvider,
    private readonly prisma: PrismaService,
    private readonly webhookService: WebhookService,
  ) {
    super();
  }

  async process(job: Job<SalesforceMkcJobData>): Promise<any> {
    const { campaignId, agendamentoId, automationId, credentials } = job.data;
    
    this.logger.log(`🚀 Executando Marketing Cloud para automação: ${automationId}`);
    this.logger.log(`Campaign ID: ${campaignId}, Agendamento: ${agendamentoId}`);

    try {
      // Chama o método triggerMarketingCloud do provider
      const result = await this.provider.triggerMarketingCloud(automationId, credentials);

      if (result.success) {
        this.logger.log(`✅ Marketing Cloud executado com sucesso para ${automationId}`);
        
        // Envia webhook para WordPress informando que o MKC foi executado
        await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'mkc_executado',
          resposta_api: result.message || 'Marketing Cloud executado com sucesso',
          data_disparo: new Date().toISOString(),
          total_enviados: 0,
          total_falhas: 0,
          provider: 'SALESFORCE_MKC',
        });

        return {
          success: true,
          automationId,
          message: result.message,
        };
      } else {
        this.logger.error(`❌ Erro ao executar Marketing Cloud: ${result.error}`);
        
        // Envia webhook de erro
        await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'mkc_erro',
          resposta_api: result.error || 'Erro ao executar Marketing Cloud',
          data_disparo: new Date().toISOString(),
          total_enviados: 0,
          total_falhas: 1,
          provider: 'SALESFORCE_MKC',
        });

        throw new Error(result.error || 'Erro ao executar Marketing Cloud');
      }
    } catch (error: any) {
      this.logger.error(`❌ Erro ao processar Marketing Cloud: ${error.message}`, error.stack);
      
      // Envia webhook de erro
      await this.webhookService.sendStatusUpdate({
        agendamento_id: agendamentoId,
        status: 'mkc_erro',
        resposta_api: error.message,
        data_disparo: new Date().toISOString(),
        total_enviados: 0,
        total_falhas: 1,
        provider: 'SALESFORCE_MKC',
      });

      throw error;
    }
  }
}

