/*
  Referência de DDL para DB_DIGITAL (SQL Server) — alinhado ao contrato operacional.
  Não executar automaticamente; validar com o DBA.
*/

IF OBJECT_ID('dbo.TB_ENVIOS_PENDENTES', 'U') IS NULL
BEGIN
  CREATE TABLE dbo.TB_ENVIOS_PENDENTES (
    agendamento_id    NVARCHAR(128) NOT NULL,
    numero_destino    NVARCHAR(64)  NOT NULL,
    nome_carteira     NVARCHAR(256) NULL,
    provedor          NVARCHAR(64)  NOT NULL,
    status_envio      NVARCHAR(32)  NOT NULL,
    data_criacao      DATETIME2(3)  NOT NULL CONSTRAINT DF_tb_env_pend_cri DEFAULT (GETDATE()),
    data_atualizacao  DATETIME2(3)  NOT NULL CONSTRAINT DF_tb_env_pend_atu DEFAULT (GETDATE()),
    mensagem_erro     NVARCHAR(4000) NULL,
    CONSTRAINT PK_TB_ENVIOS_PENDENTES PRIMARY KEY (agendamento_id, numero_destino)
  );
END;

/*
  Histórico append-only: cada execução do cron insere uma linha.
  Coluna id opcional para ordenação no dashboard (ORDER BY id DESC).
*/
IF OBJECT_ID('dbo.TB_SAUDE_LINHAS', 'U') IS NULL
BEGIN
  CREATE TABLE dbo.TB_SAUDE_LINHAS (
    id                BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    id_linha          NVARCHAR(128) NOT NULL,
    nome_linha        NVARCHAR(256) NOT NULL,
    provedor          NVARCHAR(64)  NOT NULL,
    status_qualidade  NVARCHAR(64)  NOT NULL,
    detalhes_retorno  NVARCHAR(4000) NULL
  );
END;
