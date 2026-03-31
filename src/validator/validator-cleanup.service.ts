import { Injectable, Logger } from '@nestjs/common';
import { Cron, CronExpression } from '@nestjs/schedule';
import { promises as fsPromises } from 'fs';
import { dirname } from 'path';
import { PrismaService } from '../prisma/prisma.service';
import { ValidatorService } from './validator.service';

const RETENTION_DAYS = 15;

@Injectable()
export class ValidatorCleanupService {
  private readonly logger = new Logger(ValidatorCleanupService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly validator: ValidatorService,
  ) {}

  /** Diariamente às 02:00 (fuso do servidor Node). */
  @Cron(CronExpression.EVERY_DAY_AT_2AM)
  async purgeOlderThanRetention(): Promise<void> {
    const cutoff = new Date();
    cutoff.setUTCDate(cutoff.getUTCDate() - RETENTION_DAYS);

    const rows = await this.prisma.validatorHistory.findMany({
      where: { createdAt: { lt: cutoff } },
    });

    if (rows.length === 0) {
      return;
    }

    this.logger.log(`[validator-cleanup] Removendo ${rows.length} registro(s) anteriores a ${cutoff.toISOString()}`);

    for (const row of rows) {
      const jobDir = dirname(row.pathOriginal);
      if (this.validator.pathWithinStorage(jobDir)) {
        await fsPromises.rm(jobDir, { recursive: true, force: true }).catch(() => undefined);
      }
      await this.prisma.validatorHistory.delete({ where: { id: row.id } });
    }
  }
}
