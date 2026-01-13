import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { BullModule } from '@nestjs/bullmq';
import { CampaignsController } from './campaigns.controller';
import { CampaignsService } from './campaigns.service';
import { queueNames } from '../config/bullmq.config';

@Module({
  imports: [
    HttpModule,
    BullModule.registerQueue({ name: queueNames.DISPATCH_CAMPAIGN }),
  ],
  controllers: [CampaignsController],
  providers: [CampaignsService],
  exports: [CampaignsService],
})
export class CampaignsModule {}
