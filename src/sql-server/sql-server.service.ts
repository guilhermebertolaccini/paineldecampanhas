import { Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import * as sql from 'mssql';

@Injectable()
export class SqlServerService implements OnModuleDestroy {
  private readonly logger = new Logger(SqlServerService.name);
  private pool: sql.ConnectionPool | null = null;
  private connecting: Promise<sql.ConnectionPool | null> | null = null;

  constructor(private readonly config: ConfigService) {}

  isEnabled(): boolean {
    return this.config.get<string>('MSSQL_ENABLED', '') === 'true';
  }

  async getPool(): Promise<sql.ConnectionPool | null> {
    if (!this.isEnabled()) {
      return null;
    }
    if (this.pool?.connected) {
      return this.pool;
    }
    if (this.connecting) {
      return this.connecting;
    }
    this.connecting = this.connectInternal();
    try {
      return await this.connecting;
    } finally {
      this.connecting = null;
    }
  }

  private async connectInternal(): Promise<sql.ConnectionPool | null> {
    const server = this.config.get<string>('MSSQL_HOST', '');
    const database = this.config.get<string>('MSSQL_DATABASE', '');
    const user = this.config.get<string>('MSSQL_USER', '');
    const password = this.config.get<string>('MSSQL_PASSWORD', '');
    if (!server || !database || !user) {
      this.logger.warn('MSSQL_ENABLED=true mas MSSQL_HOST/DATABASE/USER incompletos; pool não será criado.');
      return null;
    }
    const cfg: sql.config = {
      server,
      port: parseInt(this.config.get<string>('MSSQL_PORT', '1433'), 10),
      database,
      user,
      password,
      options: {
        encrypt: this.config.get<string>('MSSQL_ENCRYPT', 'true') !== 'false',
        trustServerCertificate:
          this.config.get<string>('MSSQL_TRUST_SERVER_CERTIFICATE', 'true') !== 'false',
      },
      pool: { max: 10, min: 0, idleTimeoutMillis: 30_000 },
    };
    const pool = new sql.ConnectionPool(cfg);
    try {
      await pool.connect();
      this.logger.log(`MSSQL conectado: ${server}/${database}`);
      this.pool = pool;
      return pool;
    } catch (e) {
      this.logger.error(`Falha ao conectar MSSQL: ${e}`);
      await pool.close().catch(() => undefined);
      return null;
    }
  }

  async onModuleDestroy(): Promise<void> {
    if (this.pool) {
      await this.pool.close().catch(() => undefined);
      this.pool = null;
    }
  }
}
