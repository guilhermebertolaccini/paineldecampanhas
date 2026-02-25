import { Module } from '@nestjs/common';
import { CdaModule } from './cda/cda.module';
import { GosacModule } from './gosac/gosac.module';
import { NoahModule } from './noah/noah.module';
import { RcsModule } from './rcs/rcs.module';
import { SalesforceModule } from './salesforce/salesforce.module';
import { RcsOtimaModule } from './rcs-otima/rcs-otima.module';
import { WhatsappOtimaModule } from './whatsapp-otima/whatsapp-otima.module';
import { GosacOficialModule } from './gosac-oficial/gosac-oficial.module';

@Module({
  imports: [
    CdaModule,
    GosacModule,
    NoahModule,
    RcsModule,
    SalesforceModule,
    RcsOtimaModule,
    WhatsappOtimaModule,
    GosacOficialModule,
  ],
  exports: [
    CdaModule,
    GosacModule,
    NoahModule,
    RcsModule,
    SalesforceModule,
    RcsOtimaModule,
    WhatsappOtimaModule,
    GosacOficialModule,
  ],
})
export class ProvidersModule { }
