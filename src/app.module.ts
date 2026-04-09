import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ScheduleModule } from '@nestjs/schedule';
import { AppController } from './app.controller';
import { AppService } from './app.service';
import { PrismaModule } from './prisma/prisma.module';
import { CampaignsModule } from './campaigns/campaigns.module';
import { ProvidersModule } from './providers/providers.module';
import { JobsModule } from './jobs/jobs.module';
import { ValidatorModule } from './validator/validator.module';
import { SqlServerModule } from './sql-server/sql-server.module';
import { LineHealthModule } from './line-health/line-health.module';
import { WebhooksModule } from './webhooks/webhooks.module';
import { WpSyncModule } from './wp-sync/wp-sync.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      envFilePath: '.env',
    }),
    ScheduleModule.forRoot(),
    PrismaModule,
    SqlServerModule,
    CampaignsModule,
    ProvidersModule,
    JobsModule,
    LineHealthModule,
    WebhooksModule,
    WpSyncModule,
    ValidatorModule,
  ],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}
