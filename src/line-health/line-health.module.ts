import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { LineHealthService } from './line-health.service';
import { LineHealthController } from './line-health.controller';
import { CampaignsModule } from '../campaigns/campaigns.module';

@Module({
  imports: [HttpModule, CampaignsModule],
  controllers: [LineHealthController],
  providers: [LineHealthService],
})
export class LineHealthModule {}
