import { Module } from '@nestjs/common';
import { OtimaSftpSyncController } from './otima-sftp-sync.controller';
import { OtimaSftpSyncService } from './otima-sftp-sync.service';

/**
 * Cronjob de ingestão das planilhas .xlsx do SFTP da Ótima → Saúde das Linhas (MSSQL).
 * - Cron: @Cron(EVERY_30_MINUTES) (registrado no service).
 * - Trigger manual: GET /api/v1/health/sync-otima (protegido por X-API-KEY).
 * - Requer variáveis OTIMA_SFTP_* no .env.
 * - Kill-switch do cron: OTIMA_SFTP_CRON_ENABLED=false.
 * - Dry-run do delete: OTIMA_SFTP_DELETE_AFTER_SYNC=false (padrão durante debug).
 */
@Module({
  controllers: [OtimaSftpSyncController],
  providers: [OtimaSftpSyncService],
  exports: [OtimaSftpSyncService],
})
export class OtimaSftpSyncModule {}
