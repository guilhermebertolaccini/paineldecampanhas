import { Global, Module } from '@nestjs/common';
import { SqlServerService } from './sql-server.service';
import { DigitalFunnelMssqlService } from './digital-funnel-mssql.service';
import { MssqlService } from './mssql.service';

@Global()
@Module({
  providers: [SqlServerService, DigitalFunnelMssqlService, MssqlService],
  exports: [SqlServerService, DigitalFunnelMssqlService, MssqlService],
})
export class SqlServerModule {}
