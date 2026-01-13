import { Injectable, Logger, HttpException, HttpStatus } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { PrismaService } from '../prisma/prisma.service';
import { firstValueFrom } from 'rxjs';
import { CampaignStatus } from '@prisma/client';
import { wordpressConfig } from '../config/wordpress.config';
import { CampaignData } from '../providers/base/provider.interface';
import { CampaignStatusDto } from './dto/campaign-status.dto';

@Injectable()
export class CampaignsService {
  private readonly logger = new Logger(CampaignsService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly httpService: HttpService,
  ) {}

  async createCampaign(agendamentoId: string, provider: string, totalMessages: number) {
    return this.prisma.campaign.create({
      data: {
        agendamentoId,
        provider,
        totalMessages,
        status: CampaignStatus.QUEUED,
      },
    });
  }

  async createCampaignMessages(campaignId: string, data: CampaignData[]) {
    if (data.length === 0) {
      return;
    }

    await this.prisma.campaignMessage.createMany({
      data: data.map((item) => ({
        campaignId,
        phone: item.telefone,
        name: item.nome || null,
        status: 'PENDING',
      })),
    });
  }

  async getCampaignByAgendamentoId(agendamentoId: string) {
    return this.prisma.campaign.findUnique({
      where: { agendamentoId },
      include: {
        messages: true,
      },
    });
  }

  async getCampaignById(campaignId: string) {
    return this.prisma.campaign.findUnique({
      where: { id: campaignId },
      include: {
        messages: true,
      },
    });
  }

  async updateCampaignStatus(
    campaignId: string,
    status: CampaignStatus,
    errorMessage?: string,
  ) {
    const updateData: any = {
      status,
      updatedAt: new Date(),
    };

    if (status === CampaignStatus.PROCESSING && !updateData.startedAt) {
      updateData.startedAt = new Date();
    }

    if (status === CampaignStatus.COMPLETED || status === CampaignStatus.FAILED) {
      updateData.completedAt = new Date();
    }

    if (errorMessage) {
      updateData.errorMessage = errorMessage;
    }

    return this.prisma.campaign.update({
      where: { id: campaignId },
      data: updateData,
    });
  }

  async fetchDataFromWordPress(agendamentoId: string): Promise<CampaignData[]> {
    try {
      const url = wordpressConfig.endpoints.campaignData(agendamentoId);
      this.logger.log(`Fetching campaign data from WordPress: ${url}`);

      const response = await firstValueFrom(
        this.httpService.get<CampaignData[]>(url, {
          headers: {
            'X-API-KEY': wordpressConfig.apiKey,
            'Content-Type': 'application/json',
          },
          timeout: 30000,
        }),
      );

      if (!response.data || response.data.length === 0) {
        throw new HttpException(
          'Nenhum dado encontrado para este agendamento',
          HttpStatus.NOT_FOUND,
        );
      }

      this.logger.log(`Fetched ${response.data.length} records from WordPress`);
      return response.data;
    } catch (error: any) {
      this.logger.error(
        `Error fetching data from WordPress: ${error.message}`,
        error.stack,
      );
      throw new HttpException(
        `Erro ao buscar dados no WordPress: ${error.message}`,
        error.response?.status || HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
  }

  async fetchCredentials(provider: string, envId: string): Promise<any> {
    try {
      const url = wordpressConfig.endpoints.credentials(provider.toLowerCase(), envId);
      this.logger.log(`Fetching credentials from WordPress: ${url}`);

      const response = await firstValueFrom(
        this.httpService.get(url, {
          headers: {
            'X-API-KEY': wordpressConfig.apiKey,
            'Content-Type': 'application/json',
          },
          timeout: 10000,
        }),
      );

      if (!response.data) {
        throw new HttpException(
          'Credenciais não encontradas',
          HttpStatus.NOT_FOUND,
        );
      }

      // Mapeia credenciais para o formato esperado por cada provider
      const mappedCredentials = this.mapCredentials(provider, response.data);
      
      // Log das credenciais mapeadas (mascaradas)
      const maskedCreds = this.maskCredentials(mappedCredentials);
      this.logger.log(`Credentials fetched and mapped for ${provider}:${envId}`);
      this.logger.debug(`Mapped credentials (masked): ${JSON.stringify(maskedCreds)}`);
      
      return mappedCredentials;
    } catch (error: any) {
      // Tenta extrair mensagem de erro do WordPress
      let errorMessage = error.message;
      if (error.response?.data) {
        const wpError = error.response.data;
        if (wpError.message) {
          errorMessage = wpError.message;
        } else if (wpError.code && wpError.message) {
          errorMessage = `${wpError.code}: ${wpError.message}`;
        } else if (typeof wpError === 'string') {
          errorMessage = wpError;
        }
      }

      this.logger.error(
        `Error fetching credentials: ${errorMessage}`,
        error.stack,
      );
      
      // Log detalhado para debug
      if (error.response) {
        this.logger.error(
          `WordPress response status: ${error.response.status}`,
        );
        this.logger.error(
          `WordPress response data: ${JSON.stringify(error.response.data)}`,
        );
      }

      throw new HttpException(
        `Erro ao buscar credenciais: ${errorMessage}`,
        error.response?.status || HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
  }

  identifyProvider(agendamentoId: string): string {
    const prefix = agendamentoId.charAt(0).toUpperCase();

    const providerMap: Record<string, string> = {
      'C': 'CDA',
      'G': 'GOSAC',
      'N': 'NOAH',
      'R': 'RCS',
      'O': 'RCS_OTIMA',
      'W': 'WHATSAPP_OTIMA',
      'S': 'SALESFORCE',
    };

    const provider = providerMap[prefix];
    if (!provider) {
      throw new HttpException(
        `Provider não identificado para agendamento: ${agendamentoId}`,
        HttpStatus.BAD_REQUEST,
      );
    }

    return provider;
  }

  async dispatchCampaign(agendamentoId: string, dispatchQueue?: any) {
    this.logger.log(`Dispatching campaign: ${agendamentoId}`);

    // Verificar se já existe
    const existing = await this.getCampaignByAgendamentoId(agendamentoId);
    if (existing) {
      this.logger.warn(`Campaign already exists: ${existing.id}`);
      return {
        success: true,
        campaign_id: existing.id,
        message: 'Campanha já existe na fila',
        status: existing.status,
      };
    }

    // Adicionar job na fila (será injetado via JobsModule)
    if (dispatchQueue) {
      await dispatchQueue.add('dispatch', { agendamento_id: agendamentoId });
    }

    return {
      success: true,
      message: 'Campanha adicionada à fila',
      agendamento_id: agendamentoId,
      estimated_time: '2-5 minutos',
    };
  }

  async getCampaignStatus(campaignId: string): Promise<CampaignStatusDto> {
    const campaign = await this.getCampaignById(campaignId);
    
    if (!campaign) {
      throw new HttpException('Campanha não encontrada', HttpStatus.NOT_FOUND);
    }

    const progressPercentage = campaign.totalMessages > 0
      ? Math.round((campaign.sentMessages / campaign.totalMessages) * 100)
      : 0;

    const errors = campaign.messages
      .filter(m => m.status === 'FAILED')
      .map(m => ({
        phone: m.phone,
        error: m.lastError || 'Erro desconhecido',
        attempts: m.attempts,
      }));

    return {
      campaign_id: campaign.id,
      agendamento_id: campaign.agendamentoId,
      status: campaign.status,
      provider: campaign.provider,
      total_messages: campaign.totalMessages,
      sent_messages: campaign.sentMessages,
      failed_messages: campaign.failedMessages,
      progress_percentage: progressPercentage,
      started_at: campaign.startedAt || undefined,
      completed_at: campaign.completedAt || undefined,
      errors: errors.length > 0 ? errors : undefined,
    };
  }

  /**
   * Mapeia credenciais do WordPress para o formato esperado por cada provider
   */
  private mapCredentials(provider: string, credentials: any): any {
    const upperProvider = provider.toUpperCase();

    switch (upperProvider) {
      case 'CDA':
        // WordPress retorna api_url, mas provider espera url
        return {
          ...credentials,
          url: credentials.api_url || credentials.url,
        };

      case 'RCS':
        // WordPress retorna base_url, provider já está preparado para usar
        return credentials;

      case 'RCS_OTIMA':
        // RCS Ótima usa token para autenticação
        return {
          ...credentials,
          authorization: credentials.token || credentials.authorization,
        };

      case 'WHATSAPP_OTIMA':
        // WhatsApp Ótima usa token, broker_code e customer_code
        return {
          ...credentials,
          authorization: credentials.token || credentials.authorization,
          broker_code: credentials.broker_code || '',
          customer_code: credentials.customer_code || '',
        };

      case 'GOSAC':
      case 'NOAH':
        // Já recebem url e token corretamente
        return credentials;

      case 'SALESFORCE':
        // Credenciais estáticas já vêm no formato correto
        return credentials;

      default:
        // Para outros providers, retorna como está
        return credentials;
    }
  }

  /**
   * Mascara credenciais sensíveis para logs
   */
  private maskCredentials(credentials: any): any {
    const masked = { ...credentials };
    
    // Campos sensíveis para mascarar
    const sensitiveFields = [
      'chave_api',
      'api_key',
      'token',
      'client_secret',
      'password',
      'mkc_client_secret',
    ];
    
    for (const field of sensitiveFields) {
      if (masked[field] && typeof masked[field] === 'string') {
        const value = masked[field];
        if (value.length > 8) {
          masked[field] = `${value.substring(0, 4)}...${value.substring(value.length - 4)}`;
        } else {
          masked[field] = '***';
        }
      }
    }
    
    return masked;
  }
}

