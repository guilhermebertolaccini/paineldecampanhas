import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import * as sql from 'mssql';
import { SqlServerService } from './sql-server.service';

export type EnvioMssqlStatus = 'AGUARDANDO' | 'PROCESSANDO' | 'SUCESSO' | 'ERRO';

/** Campos opcionais vindos do payload WordPress / fila de dispatch. */
export type EnvioPendenteRowInput = {
  telefone?: string | null;
  nome?: string | null;
  carteira_nome?: string | null;
  id_carteira?: string | number | null;
};

@Injectable()
export class DigitalFunnelMssqlService {
  private readonly logger = new Logger(DigitalFunnelMssqlService.name);

  /**
   * T-SQL alinhado ao contrato DBA: chave (agendamento_id, numero_destino).
   * Parâmetros: @agendamento_id, @numero_destino, @nome_carteira, @provedor, @status_envio, @mensagem_erro
   */
  private static readonly MERGE_ENVIO_PENDENTE_SQL = `
MERGE TB_ENVIOS_PENDENTES AS target
USING (SELECT @agendamento_id AS agendamento_id, @numero_destino AS numero_destino) AS source 
ON (target.agendamento_id = source.agendamento_id AND target.numero_destino = source.numero_destino)
WHEN MATCHED THEN
    UPDATE SET status_envio = @status_envio, data_atualizacao = GETDATE(), mensagem_erro = @mensagem_erro
WHEN NOT MATCHED THEN
    INSERT (agendamento_id, numero_destino, nome_carteira, provedor, status_envio, data_criacao, data_atualizacao, mensagem_erro)
    VALUES (@agendamento_id, @numero_destino, @nome_carteira, @provedor, @status_envio, GETDATE(), GETDATE(), @mensagem_erro);
`;

  private static readonly INSERT_SAUDE_LINHA_SQL = `
INSERT INTO TB_SAUDE_LINHAS (id_linha, nome_linha, provedor, status_qualidade, detalhes_retorno)
VALUES (@id_linha, @nome_linha, @provedor, @status_qualidade, @detalhes_retorno);
`;

  constructor(
    private readonly sqlServer: SqlServerService,
    private readonly config: ConfigService,
  ) {}

  private strict(): boolean {
    return this.config.get<string>('MSSQL_STRICT', '') === 'true';
  }

  private resolveNomeCarteira(row: EnvioPendenteRowInput): string | null {
    const n = row.carteira_nome != null ? String(row.carteira_nome).trim() : '';
    if (n) {
      return n.slice(0, 256);
    }
    const id = row.id_carteira != null ? String(row.id_carteira).trim() : '';
    return id ? id.slice(0, 256) : null;
  }

  /**
   * Um MERGE por destino. Sem transação global: falha em um número não bloqueia os demais nem a fila BullMQ.
   */
  async upsertEnviosAguardando(
    agendamentoId: string,
    provedor: string,
    rows: EnvioPendenteRowInput[],
  ): Promise<void> {
    const pool = await this.sqlServer.getPool();
    if (!pool || rows.length === 0) {
      return;
    }
    const ag = agendamentoId.slice(0, 128);
    const prov = provedor.slice(0, 64);

    for (const item of rows) {
      const numero = String(item.telefone ?? '').trim();
      if (!numero) {
        continue;
      }
      try {
        const req = pool.request();
        req.input('agendamento_id', sql.NVarChar(128), ag);
        req.input('numero_destino', sql.NVarChar(64), numero.slice(0, 64));
        req.input('nome_carteira', sql.NVarChar(256), this.resolveNomeCarteira(item));
        req.input('provedor', sql.NVarChar(64), prov);
        req.input('status_envio', sql.NVarChar(32), 'AGUARDANDO');
        req.input('mensagem_erro', sql.NVarChar(4000), null);
        await req.query(DigitalFunnelMssqlService.MERGE_ENVIO_PENDENTE_SQL);
      } catch (e) {
        this.logger.warn(
          `TB_ENVIOS_PENDENTES MERGE (AGUARDANDO) falhou ag=${ag} num=${numero.slice(0, 8)}…: ${e}`,
        );
        if (this.strict()) {
          throw e;
        }
      }
    }
  }

  /**
   * Atualização em massa por agendamento + provedor (mesmo lote Prisma / webhook).
   */
  async updateEnviosStatusTodos(
    agendamentoId: string,
    provedor: string,
    status: EnvioMssqlStatus,
    mensagemErro?: string | null,
  ): Promise<void> {
    const pool = await this.sqlServer.getPool();
    if (!pool) {
      return;
    }
    const sqlText = `
UPDATE TB_ENVIOS_PENDENTES
SET status_envio = @status_envio,
    data_atualizacao = GETDATE(),
    mensagem_erro = @mensagem_erro
WHERE agendamento_id = @agendamento_id AND provedor = @provedor
`;
    try {
      const req = pool.request();
      req.input('agendamento_id', sql.NVarChar(128), agendamentoId.slice(0, 128));
      req.input('provedor', sql.NVarChar(64), provedor.slice(0, 64));
      req.input('status_envio', sql.NVarChar(32), status);
      const errTrunc =
        mensagemErro != null ? String(mensagemErro).slice(0, 4000) : null;
      req.input('mensagem_erro', sql.NVarChar(4000), errTrunc);
      await req.query(sqlText);
    } catch (e) {
      this.logger.warn(`TB_ENVIOS_PENDENTES UPDATE em massa falhou: ${e}`);
      if (this.strict()) {
        throw e;
      }
    }
  }

  /**
   * Histórico append-only (cron diário): um INSERT por execução por alvo.
   */
  async insertSaudeLinhaHistorico(row: {
    id_linha: string;
    nome_linha: string;
    provedor: string;
    status_qualidade: string;
    detalhes_retorno: string | null;
  }): Promise<void> {
    const pool = await this.sqlServer.getPool();
    if (!pool) {
      return;
    }
    try {
      const req = pool.request();
      req.input('id_linha', sql.NVarChar(128), row.id_linha.slice(0, 128));
      req.input('nome_linha', sql.NVarChar(256), row.nome_linha.slice(0, 256));
      req.input('provedor', sql.NVarChar(64), row.provedor.slice(0, 64));
      req.input('status_qualidade', sql.NVarChar(64), row.status_qualidade.slice(0, 64));
      const det =
        row.detalhes_retorno != null
          ? String(row.detalhes_retorno).slice(0, 4000)
          : null;
      req.input('detalhes_retorno', sql.NVarChar(4000), det);
      await req.query(DigitalFunnelMssqlService.INSERT_SAUDE_LINHA_SQL);
    } catch (e) {
      this.logger.warn(`TB_SAUDE_LINHAS INSERT falhou: ${e}`);
      if (this.strict()) {
        throw e;
      }
    }
  }
}
