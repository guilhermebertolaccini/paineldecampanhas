import { BullRootModuleOptions } from '@nestjs/bullmq';

export const bullmqConfig: BullRootModuleOptions = {
  connection: {
    host: process.env.REDIS_HOST || 'localhost',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD,
  },
};

export const queueNames = {
  DISPATCH_CAMPAIGN: 'dispatch-campaign',
  CDA_SEND: 'cda-send',
  GOSAC_SEND: 'gosac-send',
  GOSAC_START: 'gosac-start',
  NOAH_SEND: 'noah-send',
  RCS_SEND: 'rcs-send',
  RCS_OTIMA_SEND: 'rcs-otima-send',
  WHATSAPP_OTIMA_SEND: 'whatsapp-otima-send',
  SALESFORCE_SEND: 'salesforce-send',
  SALESFORCE_MKC: 'salesforce-mkc',
  GOSAC_OFICIAL_SEND: 'gosac-oficial-send',
  GOSAC_OFICIAL_START: 'gosac-oficial-start',
} as const;

export type QueueName = typeof queueNames[keyof typeof queueNames];

