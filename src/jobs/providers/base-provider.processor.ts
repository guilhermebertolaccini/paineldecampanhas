import { Processor, WorkerHost } from '@nestjs/bullmq';
import { Job } from 'bullmq';
import { Logger } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';
import { WebhookService } from '../../webhook/webhook.service';
import { BaseProvider } from '../../providers/base/base.provider';
import { DigitalFunnelMssqlService } from '../../sql-server/digital-funnel-mssql.service';
import { wordpressConfig } from '../../config/wordpress.config';

export interface ProviderSendJobData {
  campaignId: string;
  agendamentoId: string;
  data: any[];
  credentials: any;
}

export interface ProcessResult {
  success: boolean;
  campaignId: string;
  totalMessages: number;
  data?: any;
}

export abstract class BaseProviderProcessor extends WorkerHost {
  protected readonly logger: Logger;
  protected abstract providerName: string;

  /** Garante que errorMessage seja string (Prisma não aceita array). */
  protected toErrorMessage(err: unknown): string {
    if (err == null) return 'Erro desconhecido';
    if (Array.isArray(err)) return err.join('; ');
    return String(err);
  }

  constructor(
    protected readonly provider: BaseProvider,
    protected readonly prisma: PrismaService,
    protected readonly webhookService: WebhookService,
    protected readonly digitalFunnel: DigitalFunnelMssqlService,
    loggerName: string,
  ) {
    super();
    this.logger = new Logger(loggerName);
  }

  async process(job: Job<ProviderSendJobData>): Promise<ProcessResult> {
    const { campaignId, agendamentoId, data, credentials } = job.data;
    
    this.logger.log(`Processing ${this.providerName} send for campaign: ${campaignId} (${agendamentoId})`);
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

      await this.digitalFunnel.updateEnviosStatusTodos(
        agendamentoId,
        this.providerName,
        'PROCESSANDO',
      );

      // Envia para o provider
      const result = await this.provider.send(data, credentials);

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

        await this.digitalFunnel.updateEnviosStatusTodos(
          agendamentoId,
          this.providerName,
          'SUCESSO',
        );

        const wpOkSuccess = await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'enviado',
          resposta_api: result.data?.body ? JSON.stringify(result.data.body) : 'Campanha enviada com sucesso',
          data_disparo: new Date().toISOString(),
          total_enviados: data.length,
          total_falhas: 0,
          provider: this.providerName,
        });
        if (!wpOkSuccess) {
          this.logger.error(
            `[Webhook WP] Falha ao notificar WordPress após SUCESSO do provider — agendamento=${agendamentoId} provider=${this.providerName}. Confira MASTER API KEY (WORDPRESS_API_KEY / ACM_MASTER_API_KEY no Nest igual a acm_master_api_key no WP), URL=${wordpressConfig.url}, e logs do Nest com prefixo [Webhook WP].`,
          );
        }

        return {
          success: true,
          campaignId,
          totalMessages: data.length,
          data: result.data, // Inclui dados retornados pelo provider (ex: automationId do Salesforce)
        };
      } else {
        const errStr = this.toErrorMessage(result.error);
        // Atualiza campanha como FAILED
        await this.prisma.campaign.update({
          where: { id: campaignId },
          data: {
            status: 'FAILED',
            errorMessage: errStr,
            failedMessages: data.length,
          },
        });

        // Atualiza todas as mensagens como FAILED
        await this.prisma.campaignMessage.updateMany({
          where: { campaignId },
          data: {
            status: 'FAILED',
            lastError: errStr,
          },
        });

        this.logger.error(`❌ Campaign ${campaignId} failed: ${errStr}`);

        await this.digitalFunnel.updateEnviosStatusTodos(
          agendamentoId,
          this.providerName,
          'ERRO',
          errStr,
        );
        
        const wpOkFail = await this.webhookService.sendStatusUpdate({
          agendamento_id: agendamentoId,
          status: 'erro_envio',
          resposta_api: errStr,
          data_disparo: new Date().toISOString(),
          total_enviados: 0,
          total_falhas: data.length,
          provider: this.providerName,
        });
        if (!wpOkFail) {
          this.logger.error(
            `[Webhook WP] Falha ao notificar WordPress (erro_envio) — agendamento=${agendamentoId} provider=${this.providerName} url=${wordpressConfig.url}`,
          );
        }

        throw new Error(errStr || `Erro ao enviar campanha ${this.providerName}`);
      }
    } catch (error: any) {
      const errStr = this.toErrorMessage(error?.message ?? error);
      this.logger.error(`Error processing ${this.providerName} send: ${errStr}`, error?.stack);
      
      // Atualiza campanha como FAILED em caso de erro
      try {
        await this.prisma.campaign.update({
          where: { id: campaignId },
          data: {
            status: 'FAILED',
            errorMessage: errStr,
            failedMessages: data.length,
          },
        });
      } catch (updateError) {
        this.logger.error(`Failed to update campaign status: ${updateError}`);
      }

      await this.digitalFunnel.updateEnviosStatusTodos(
        agendamentoId,
        this.providerName,
        'ERRO',
        errStr,
      );
      
      const wpOkCatch = await this.webhookService.sendStatusUpdate({
        agendamento_id: agendamentoId,
        status: 'erro_envio',
        resposta_api: errStr,
        data_disparo: new Date().toISOString(),
        total_enviados: 0,
        total_falhas: data.length,
        provider: this.providerName,
      });
      if (!wpOkCatch) {
        this.logger.error(
          `[Webhook WP] Falha ao notificar WordPress (catch erro_envio) — agendamento=${agendamentoId} provider=${this.providerName} url=${wordpressConfig.url}`,
        );
      }

      throw error;
    }
  }
}

