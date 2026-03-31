import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { PrismaModule } from '../prisma/prisma.module';
import { ValidatorController } from './validator.controller';
import { ValidatorService } from './validator.service';
import { EvolutionApiService } from './evolution-api.service';
import { ValidatorCleanupService } from './validator-cleanup.service';

@Module({
  imports: [
    PrismaModule,
    HttpModule.register({
      timeout: 120_000,
      maxRedirects: 3,
    }),
  ],
  controllers: [ValidatorController],
  providers: [ValidatorService, EvolutionApiService, ValidatorCleanupService],
  exports: [ValidatorService],
})
export class ValidatorModule {}
