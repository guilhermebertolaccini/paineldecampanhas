-- TB_SALESFORCE_TRACKING — webhook de tracking Salesforce → DB_DIGITAL (SQL Server)
-- Alinhado ao contrato de salesforce_returns / import_salesforce (MySQL) + TemplateName.

IF OBJECT_ID(N'dbo.TB_SALESFORCE_TRACKING', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.TB_SALESFORCE_TRACKING (
    id_registro INT IDENTITY(1,1) NOT NULL
      CONSTRAINT PK_TB_SALESFORCE_TRACKING PRIMARY KEY,
    uniqueid NVARCHAR(MAX) NOT NULL,
    uniqueid_hash VARCHAR(64) NOT NULL,
    trackingtype NVARCHAR(100) NULL,
    sendtype NVARCHAR(100) NULL,
    mid NVARCHAR(100) NULL,
    eid NVARCHAR(200) NULL,
    contactkey NVARCHAR(200) NULL,
    mobilenumber NVARCHAR(50) NULL,
    eventdateutc DATETIME2(3) NULL,
    appid NVARCHAR(100) NULL,
    channelid NVARCHAR(100) NULL,
    channeltype NVARCHAR(50) NULL,
    conversationtype NVARCHAR(50) NULL,
    activityname NVARCHAR(150) NULL,
    channelname NVARCHAR(150) NULL,
    status NVARCHAR(100) NULL,
    reason NVARCHAR(MAX) NULL,
    jbdefinitionid NVARCHAR(200) NULL,
    sendidentifier NVARCHAR(200) NULL,
    assetid NVARCHAR(100) NULL,
    messagetypeid NVARCHAR(100) NULL,
    operacao__c NVARCHAR(100) NULL,
    cpf_cnpj__c NVARCHAR(50) NULL,
    name NVARCHAR(255) NULL,
    TemplateName NVARCHAR(255) NULL,
    data_criacao DATETIME2(3) NOT NULL
      CONSTRAINT DF_TB_SALESFORCE_TRACKING_criacao DEFAULT (SYSDATETIME()),
    CONSTRAINT UQ_TB_SALESFORCE_TRACKING_hash UNIQUE (uniqueid_hash)
  );
END
GO
