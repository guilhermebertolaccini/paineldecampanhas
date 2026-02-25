import { Module } from '@nestjs/common';
import { BullModule } from '@nestjs/bullmq';
import { bullmqConfig, queueNames } from '../config/bullmq.config';
import { DispatchCampaignProcessor } from './dispatch-campaign.processor';
import { CdaSendProcessor } from './providers/cda-send.processor';
import { GosacSendProcessor } from './providers/gosac-send.processor';
import { NoahSendProcessor } from './providers/noah-send.processor';
import { RcsSendProcessor } from './providers/rcs-send.processor';
import { RcsOtimaSendProcessor } from './providers/rcs-otima-send.processor';
import { WhatsappOtimaSendProcessor } from './providers/whatsapp-otima-send.processor';
import { SalesforceSendProcessor } from './providers/salesforce-send.processor';
import { SalesforceMkcProcessor } from './providers/salesforce-mkc.processor';
import { GosacOficialSendProcessor } from './providers/gosac-oficial-send.processor';
import { GosacOficialStartProcessor } from './providers/gosac-oficial-start.processor';
import { CampaignsModule } from '../campaigns/campaigns.module';
import { ProvidersModule } from '../providers/providers.module';
import { WebhookModule } from '../webhook/webhook.module';
import { HttpModule } from '@nestjs/axios';
import { PrismaModule } from '../prisma/prisma.module';

@Module({
  imports: [
    BullModule.forRoot(bullmqConfig),
    BullModule.registerQueue(
      { name: queueNames.DISPATCH_CAMPAIGN },
      { name: queueNames.CDA_SEND },
      { name: queueNames.GOSAC_SEND },
      { name: queueNames.GOSAC_START },
      { name: queueNames.NOAH_SEND },
      { name: queueNames.RCS_SEND },
      { name: queueNames.RCS_OTIMA_SEND },
      { name: queueNames.WHATSAPP_OTIMA_SEND },
      { name: queueNames.SALESFORCE_SEND },
      { name: queueNames.SALESFORCE_MKC },
      { name: queueNames.GOSAC_OFICIAL_SEND },
      { name: queueNames.GOSAC_OFICIAL_START },
    ),
    CampaignsModule,
    ProvidersModule,
    WebhookModule,
    PrismaModule,
    HttpModule,
  ],
  providers: [
    DispatchCampaignProcessor,
    CdaSendProcessor,
    GosacSendProcessor,
    NoahSendProcessor,
    RcsSendProcessor,
    RcsOtimaSendProcessor,
    WhatsappOtimaSendProcessor,
    SalesforceSendProcessor,
    SalesforceMkcProcessor,
    GosacOficialSendProcessor,
    GosacOficialStartProcessor,
  ],
  exports: [BullModule],
})
export class JobsModule { }
