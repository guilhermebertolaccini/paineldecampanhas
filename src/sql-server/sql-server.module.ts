import { Global, Module } from '@nestjs/common';
import { SqlServerService } from './sql-server.service';
import { DigitalFunnelMssqlService } from './digital-funnel-mssql.service';

@Global()
@Module({
  providers: [SqlServerService, DigitalFunnelMssqlService],
  exports: [SqlServerService, DigitalFunnelMssqlService],
})
export class SqlServerModule {}
