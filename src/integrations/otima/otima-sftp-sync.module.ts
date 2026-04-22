import { Module } from '@nestjs/common';
import { OtimaSftpSyncService } from './otima-sftp-sync.service';

/**
 * Cronjob de ingestão das planilhas .xlsx do SFTP da Ótima → Saúde das Linhas (MSSQL).
 * Requer variáveis OTIMA_SFTP_* no .env. Desabilitável via OTIMA_SFTP_CRON_ENABLED=false.
 */
@Module({
  providers: [OtimaSftpSyncService],
  exports: [OtimaSftpSyncService],
})
export class OtimaSftpSyncModule {}
