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

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      envFilePath: '.env',
    }),
    ScheduleModule.forRoot(),
    PrismaModule,
    CampaignsModule,
    ProvidersModule,
    JobsModule,
    ValidatorModule,
  ],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}
