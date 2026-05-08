import {
  Controller,
  Post,
  Get,
  Param,
  Body,
  UseGuards,
  HttpCode,
  HttpStatus,
  Inject,
  StreamableFile,
} from '@nestjs/common';
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

  /** Exporta mailing (campaign_messages) como CSV. `:id` = UUID da campanha no Postgres ou `agendamento_id` do WordPress. */
  @Get(':id/export-csv')
  async exportMailingCsv(@Param('id') id: string): Promise<StreamableFile> {
    const stream = await this.campaignsService.createMailingCsvStream(id);
    const safeSeg = id.replace(/[^a-zA-Z0-9._-]+/g, '_').slice(0, 96);
    const filename = `mailing-${safeSeg || 'campanha'}.csv`;
    return new StreamableFile(stream, {
      type: 'text/csv; charset=utf-8',
      disposition: `attachment; filename="${filename}"`,
    });
  }

  @Get(':id/status')
  async getStatus(@Param('id') id: string): Promise<CampaignStatusDto> {
    return this.campaignsService.getCampaignStatus(id);
  }
}

