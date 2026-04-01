import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { LineHealthService } from './line-health.service';
import { CampaignsModule } from '../campaigns/campaigns.module';
@Module({
  imports: [HttpModule, CampaignsModule],
  providers: [LineHealthService],
})
export class LineHealthModule {}
