import { Module } from '@nestjs/common';
import { SalesforceTrackingWebhookController } from './salesforce-tracking-webhook.controller';

@Module({
  controllers: [SalesforceTrackingWebhookController],
})
export class WebhooksModule {}
