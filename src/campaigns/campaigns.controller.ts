import { Controller, Post, Get, Param, Body, UseGuards, HttpCode, HttpStatus, Inject } from '@nestjs/common';
import { InjectQueue } from '@nestjs/bullmq';
import { Queue } from 'bullmq';
import { CampaignsService } from './campaigns.service';
import { DispatchCampaignDto } from './dto/dispatch-campaign.dto';
import { CampaignStatusDto } from './dto/campaign-status.dto';
import { ApiKeyGuard } from '../common/guards/api-key.guard';
import { queueNames } from '../config/bullmq.config';

@Controller('campaigns')
@UseGuards(ApiKeyGuard)
export class CampaignsController {
  constructor(
    private readonly campaignsService: CampaignsService,
    @InjectQueue(queueNames.DISPATCH_CAMPAIGN) private readonly dispatchQueue: Queue,
  ) {}

  @Post('dispatch')
  @HttpCode(HttpStatus.ACCEPTED)
  async dispatch(@Body() dto: DispatchCampaignDto) {
    return this.campaignsService.dispatchCampaign(dto.agendamento_id, this.dispatchQueue);
  }

  @Get(':id/status')
  async getStatus(@Param('id') id: string): Promise<CampaignStatusDto> {
    return this.campaignsService.getCampaignStatus(id);
  }
}

