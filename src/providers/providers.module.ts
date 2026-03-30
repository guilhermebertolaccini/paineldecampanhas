import { Module } from '@nestjs/common';
import { CdaModule } from './cda/cda.module';
import { GosacModule } from './gosac/gosac.module';
import { NoahModule } from './noah/noah.module';
import { RcsModule } from './rcs/rcs.module';
import { SalesforceModule } from './salesforce/salesforce.module';
import { RcsOtimaModule } from './rcs-otima/rcs-otima.module';
import { WhatsappOtimaModule } from './whatsapp-otima/whatsapp-otima.module';
import { GosacOficialModule } from './gosac-oficial/gosac-oficial.module';
import { NoahOficialModule } from './noah-oficial/noah-oficial.module';
import { RobbOficialModule } from './robbu-oficial/robbu-oficial.module';
import { TechiaModule } from './techia/techia.module';

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
    NoahOficialModule,
    RobbOficialModule,
    TechiaModule,
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
    NoahOficialModule,
    RobbOficialModule,
    TechiaModule,
  ],
})
export class ProvidersModule { }
