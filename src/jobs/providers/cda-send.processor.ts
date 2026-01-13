import { Processor, WorkerHost } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { Logger } from '@nestjs/common';
import { CdaProvider } from '../../providers/cda/cda.provider';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { queueNames } from '../../config/bullmq.config';

interface CdaSendJobData {
  campaignId: string;
  agendamentoId: string;
  data: any[];
  credentials: any;
}

@Processor(queueNames.CDA_SEND)
export class CdaSendProcessor extends WorkerHost {
  private readonly logger = new Logger(CdaSendProcessor.name);

  constructor(
    private readonly cdaProvider: CdaProvider,
    private readonly prisma: PrismaService,
    private readonly webhookService: WebhookService,
  ) {
    super();
  }

  async process(job: Job<CdaSendJobData>) {
    const { campaignId, agendamentoId, data, credentials } = job.data;
    
    this.logger.log(`Processing CDA send for campaign: ${campaignId} (${agendamentoId})`);
    this.logger.log(`Total messages: ${data.length}`);

    try {
      // Verifica se as mensagens existem, se não, cria
      const existingMessages = await this.prisma.campaignMessage.count({
        where: { campaignId },
      });

      if (existingMessages === 0) {
        this.logger.log(`Creating ${data.length} campaign messages...`);
        await this.prisma.campaignMessage.createMany({
          data: data.map((item) => ({
            campaignId,
            phone: item.telefone,
            name: item.nome || null,
            status: 'PENDING',
          })),
        });
      }

      // Atualiza status da campanha para PROCESSING
      await this.prisma.campaign.update({
        where: { id: campaignId },
        data: {
          status: 'PROCESSING',
          startedAt: new Date(),
        },
      });

      // Envia para o provider CDA
      const result = await this.cdaProvider.send(data, credentials);

      if (result.success) {
        // Atualiza campanha como COMPLETED
        await this.prisma.campaign.update({
          where: { id: campaignId },
          data: {
            status: 'COMPLETED',
            completedAt: new Date(),
            sentMessages: data.length,
          },
        });

        // Atualiza todas as mensagens como SENT
        await this.prisma.campaignMessage.updateMany({
          where: { campaignId },
          data: {
            status: 'SENT',
            sentAt: new Date(),
          },
        });

        this.logger.log(`✅ Campaign ${campaignId} completed successfully`);
        
        // Envia webhook para WordPress
        await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'enviado',
          resposta_api: result.data?.body ? JSON.stringify(result.data.body) : 'Campanha enviada com sucesso',
          data_disparo: new Date().toISOString(),
          total_enviados: data.length,
          total_falhas: 0,
          provider: 'CDA',
        });
        
        return {
          success: true,
          campaignId,
          totalMessages: data.length,
        };
      } else {
        // Atualiza campanha como FAILED
        await this.prisma.campaign.update({
          where: { id: campaignId },
          data: {
            status: 'FAILED',
            errorMessage: result.error || 'Erro desconhecido',
            failedMessages: data.length,
          },
        });

        // Atualiza todas as mensagens como FAILED
        await this.prisma.campaignMessage.updateMany({
          where: { campaignId },
          data: {
            status: 'FAILED',
            lastError: result.error || 'Erro desconhecido',
          },
        });

        this.logger.error(`❌ Campaign ${campaignId} failed: ${result.error}`);
        
        // Envia webhook de erro para WordPress
        await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'erro_envio',
          resposta_api: result.error || 'Erro desconhecido',
          data_disparo: new Date().toISOString(),
          total_enviados: 0,
          total_falhas: data.length,
          provider: 'CDA',
        });
        
        // Retorna erro sem lançar para evitar duplicação no catch
        return {
          success: false,
          campaignId,
          error: result.error || 'Erro ao enviar campanha CDA',
        };
      }
    } catch (error: any) {
      this.logger.error(`Error processing CDA send: ${error.message}`, error.stack);
      
      // Atualiza campanha como FAILED em caso de erro
      try {
        await this.prisma.campaign.update({
          where: { id: campaignId },
          data: {
            status: 'FAILED',
            errorMessage: error.message,
            failedMessages: data.length,
          },
        });
      } catch (updateError) {
        this.logger.error(`Failed to update campaign status: ${updateError}`);
      }
      
      // Envia webhook de erro para WordPress
      await this.webhookService.sendStatusUpdate({
        agendamento_id: agendamentoId,
        status: 'erro_envio',
        resposta_api: error.message,
        data_disparo: new Date().toISOString(),
        total_enviados: 0,
        total_falhas: data.length,
        provider: 'CDA',
      });
      
      throw error;
    }
  }
}

