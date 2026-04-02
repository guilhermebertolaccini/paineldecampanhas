import { createHash } from 'crypto';
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

  private static readonly INSERT_SALESFORCE_TRACKING_SQL = `
INSERT INTO dbo.TB_SALESFORCE_TRACKING (
  uniqueid, uniqueid_hash, trackingtype, sendtype, mid, eid, contactkey, mobilenumber,
  eventdateutc, appid, channelid, channeltype, conversationtype, activityname, channelname,
  status, reason, jbdefinitionid, sendidentifier, assetid, messagetypeid,
  operacao__c, cpf_cnpj__c, name, TemplateName
) VALUES (
  @uniqueid, @uniqueid_hash, @trackingtype, @sendtype, @mid, @eid, @contactkey, @mobilenumber,
  @eventdateutc, @appid, @channelid, @channeltype, @conversationtype, @activityname, @channelname,
  @status, @reason, @jbdefinitionid, @sendidentifier, @assetid, @messagetypeid,
  @operacao__c, @cpf_cnpj__c, @name, @TemplateName
);
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

  /**
   * Webhook Salesforce: persiste uma linha colunar em TB_SALESFORCE_TRACKING.
   * Chaves alinhadas a salesforce_returns / import_salesforce (case-insensitive no JSON).
   * Em falha de BD retorna false (o controller responde 202 mesmo assim).
   */
  async insertSalesforceTrackingFromPayload(
    raw: Record<string, unknown>,
  ): Promise<boolean> {
    const pool = await this.sqlServer.getPool();
    if (!pool) {
      this.logger.warn(
        'TB_SALESFORCE_TRACKING: pool MSSQL indisponível; evento não persistido',
      );
      return false;
    }

    const pick = DigitalFunnelMssqlService.pickCaseInsensitive;
    const uniqueid = (pick(raw, 'uniqueid') ?? '').trim();
    if (!uniqueid) {
      this.logger.warn(
        'TB_SALESFORCE_TRACKING: payload sem uniqueid; não persistido',
      );
      return false;
    }

    let uniqueidHash = (pick(raw, 'uniqueid_hash') ?? '').trim();
    if (!uniqueidHash) {
      uniqueidHash = createHash('sha256').update(uniqueid, 'utf8').digest('hex');
    } else {
      uniqueidHash = uniqueidHash.slice(0, 64);
    }

    const eventRaw = pick(raw, 'eventdateutc');
    let eventDateUtc: Date | null = null;
    if (eventRaw) {
      const d = new Date(eventRaw);
      if (!Number.isNaN(d.getTime())) {
        eventDateUtc = d;
      }
    }

    const trunc = DigitalFunnelMssqlService.truncNullable;

    try {
      const req = pool.request();
      req.input('uniqueid', sql.NVarChar(sql.MAX), uniqueid);
      req.input('uniqueid_hash', sql.VarChar(64), uniqueidHash);
      req.input(
        'trackingtype',
        sql.NVarChar(100),
        trunc(pick(raw, 'trackingtype'), 100),
      );
      req.input('sendtype', sql.NVarChar(100), trunc(pick(raw, 'sendtype'), 100));
      req.input('mid', sql.NVarChar(100), trunc(pick(raw, 'mid'), 100));
      req.input('eid', sql.NVarChar(200), trunc(pick(raw, 'eid'), 200));
      req.input(
        'contactkey',
        sql.NVarChar(200),
        trunc(pick(raw, 'contactkey'), 200),
      );
      req.input(
        'mobilenumber',
        sql.NVarChar(50),
        trunc(pick(raw, 'mobilenumber'), 50),
      );
      req.input('eventdateutc', sql.DateTime2, eventDateUtc);
      req.input('appid', sql.NVarChar(100), trunc(pick(raw, 'appid'), 100));
      req.input(
        'channelid',
        sql.NVarChar(100),
        trunc(pick(raw, 'channelid'), 100),
      );
      req.input(
        'channeltype',
        sql.NVarChar(50),
        trunc(pick(raw, 'channeltype'), 50),
      );
      req.input(
        'conversationtype',
        sql.NVarChar(50),
        trunc(pick(raw, 'conversationtype'), 50),
      );
      req.input(
        'activityname',
        sql.NVarChar(150),
        trunc(pick(raw, 'activityname'), 150),
      );
      req.input(
        'channelname',
        sql.NVarChar(150),
        trunc(pick(raw, 'channelname'), 150),
      );
      req.input('status', sql.NVarChar(100), trunc(pick(raw, 'status'), 100));
      const reasonVal = pick(raw, 'reason');
      req.input(
        'reason',
        sql.NVarChar(sql.MAX),
        reasonVal != null && String(reasonVal).trim() !== ''
          ? String(reasonVal)
          : null,
      );
      req.input(
        'jbdefinitionid',
        sql.NVarChar(200),
        trunc(pick(raw, 'jbdefinitionid'), 200),
      );
      req.input(
        'sendidentifier',
        sql.NVarChar(200),
        trunc(pick(raw, 'sendidentifier'), 200),
      );
      req.input('assetid', sql.NVarChar(100), trunc(pick(raw, 'assetid'), 100));
      req.input(
        'messagetypeid',
        sql.NVarChar(100),
        trunc(pick(raw, 'messagetypeid'), 100),
      );
      req.input(
        'operacao__c',
        sql.NVarChar(100),
        trunc(pick(raw, 'operacao__c'), 100),
      );
      req.input(
        'cpf_cnpj__c',
        sql.NVarChar(50),
        trunc(pick(raw, 'cpf_cnpj__c'), 50),
      );
      req.input('name', sql.NVarChar(255), trunc(pick(raw, 'name'), 255));
      req.input(
        'TemplateName',
        sql.NVarChar(255),
        trunc(pick(raw, 'templatename', 'TemplateName'), 255),
      );

      await req.query(DigitalFunnelMssqlService.INSERT_SALESFORCE_TRACKING_SQL);
      return true;
    } catch (e: unknown) {
      const err = e as { number?: number; message?: string };
      const msg = String(err?.message ?? e);
      if (
        err?.number === 2627 ||
        err?.number === 2601 ||
        /UNIQUE KEY|duplicate/i.test(msg)
      ) {
        this.logger.warn(
          `TB_SALESFORCE_TRACKING: duplicado uniqueid_hash (idempotência)`,
        );
        return true;
      }
      this.logger.error(`TB_SALESFORCE_TRACKING INSERT falhou: ${msg}`);
      if (this.strict()) {
        throw e;
      }
      return false;
    }
  }

  private static pickCaseInsensitive(
    raw: Record<string, unknown>,
    ...keys: string[]
  ): string | null {
    const lower = new Map<string, unknown>();
    for (const [k, v] of Object.entries(raw)) {
      lower.set(k.toLowerCase(), v);
    }
    for (const key of keys) {
      const v = lower.get(key.toLowerCase());
      if (v != null && String(v).trim() !== '') {
        return String(v);
      }
    }
    return null;
  }

  private static truncNullable(
    s: string | null,
    max: number,
  ): string | null {
    if (s == null) {
      return null;
    }
    const t = s.trim();
    if (!t) {
      return null;
    }
    return t.length <= max ? t : t.slice(0, max);
  }
}
