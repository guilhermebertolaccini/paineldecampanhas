# Arquitetura de dados: SQL Server (`DB_DIGITAL` @ 10.103.2.26)

Este documento descreve o papel do **Microsoft SQL Server** no ecossistema **Midpainel / Painel de Campanhas**, com foco na separação **escrita (NestJS)** vs **leitura (WordPress/PHP)** e nos pipelines de **saúde de linhas** e **rastreamento Salesforce**.

---

## Visão geral do fluxo

```
                    ┌─────────────────┐
   Salesforce ────► │     NestJS      │──────► INSERT / UPDATE / MERGE
   (webhooks)       │  (motor escrita) │        em DB_DIGITAL (.26)
                    │  driver: mssql   │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ 10.103.2.26     │
                    │ DB_DIGITAL      │
                    │ (telemetria)    │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │   WordPress     │◄──── SELECT (somente leitura)
                    │ PDO sqlsrv      │      painel / AJAX / REST auxiliar
                    └─────────────────┘
```

---

## 1. Separação de responsabilidades: leitura vs escrita

### NestJS — motor de escrita (“trator”)

- O microserviço **NestJS** conecta ao SQL Server com o pacote **`mssql`** (Tedious), via pool configurado em **`SqlServerService`**.
- Parâmetros vêm do **`.env`** do Nest, por exemplo:
  - `MSSQL_ENABLED=true`
  - `MSSQL_HOST=10.103.2.26`
  - `MSSQL_PORT=1433`
  - `MSSQL_DATABASE=DB_DIGITAL`
  - `MSSQL_USER`, `MSSQL_PASSWORD`
  - `MSSQL_ENCRYPT`, `MSSQL_TRUST_SERVER_CERTIFICATE`
  - Opcional: `MSSQL_STRICT=true` para propagar falhas de gravação em cenários críticos (padrão costuma ser não estrito: log + continuidade da fila).
- Responsabilidades típicas no `.26`:
  - **Webhooks** (ex.: tracking Salesforce) com resposta rápida ao chamador externo.
  - **Workers / cron** (ex.: saúde de linhas) que avaliam integrações e persistem histórico.
  - **MERGE / UPDATE / INSERT** em tabelas operacionais (ex.: `TB_ENVIOS_PENDENTES`, `TB_SAUDE_LINHAS`, `TB_SALESFORCE_TRACKING`), sempre parametrizados via `pool.request().input(...)`.

### WordPress / PHP — ponte de leitura

- O plugin usa **`PC_SqlServer_Connector`** com extensão PHP **`pdo_sqlsrv`**.
- Credenciais podem vir de **`wp-config.php`** (constantes `PC_MSSQL_*`) ou das opções salvas pela interface (**API Manager** / opções `pc_mssql_*`), alinhado ao deploy de cada ambiente.
- O WP **não** é a fonte da verdade para telemetria pesada no SQL Server: ele **consulta** dados para:
  - menus e fluxos de **Nova Campanha** (ex.: views `VW_BASE*` quando configurado),
  - tela **Saúde das Linhas** (leitura de `TB_SAUDE_LINHAS`),
  - outras leituras pontuais documentadas no código.
- **Resiliência:** chamadas ao `.26` envolvem **try/catch** (e logs). Se o host não responder, o conector retorna estruturas vazias ou degrada o recurso sem derrubar o painel inteiro (comportamento “fail soft” no PHP).

### Por que duas pilhas de conexão?

| Aspecto | NestJS | WordPress |
|--------|--------|-----------|
| Driver | `mssql` (Node) | `pdo_sqlsrv` (PHP) |
| Config | `.env` do serviço | API Manager + `wp-config` |
| Papel dominante | Escrita, filas, webhooks | Leitura para UI e integrações WP |

As duas pontas apontam para o **mesmo banco lógico** (`DB_DIGITAL` no `.26`), mas com **papéis diferentes** na arquitetura.

---

## 2. Ponte operacional WordPress → SQL Server (saúde e espelho)

A operação de **saúde das linhas** e a **telemetria consolidada no `.26`** passam a ser alimentadas a partir das **tabelas operacionais do MySQL** (fonte da verdade no painel), **sem** lista fixa de alvos (`LINE_HEALTH_TARGETS`) no Nest.

### 2.1. Tabelas espelhadas no `DB_DIGITAL`

O PHP (**`PC_Wp_Mssql_Bridge`**) copia linhas do WordPress para **`dbo.PC_WP_MIRROR_ROWS`**:

| Origem MySQL (exemplo) | Chave lógica no espelho |
|------------------------|---------------------------|
| `{prefixo}envios_pendentes` | `source_table = envios_pendentes` |
| `{prefixo}eventos_envios` | `eventos_envios` |
| `{prefixo}eventos_indicadores` | `eventos_indicadores` |
| `{prefixo}eventos_tempos` | `eventos_tempos` |
| `salesforce_returns` (sem prefixo) | `salesforce_returns` |

Cada linha vira um registro **`(source_table, wp_row_id, payload_json)`** com **`MERGE`** (atualiza se já existir, insere se novo).

### 2.2. Snapshot de saúde: `dbo.PC_LINE_HEALTH_SNAPSHOT`

- **Recálculo** a partir do MySQL: agregação por **`fornecedor` + `idgis_ambiente`** sobre **`envios_pendentes`** (janela ~30 dias), taxa de status “ruins”, e contagens auxiliares das tabelas de eventos / `salesforce_returns` (resumidas em JSON).
- **Tiers** (`saude_tier`): `GREEN` / `YELLOW` / `RED` / `SEM_DADOS` (regras heurísticas no PHP; ajustáveis).
- A tabela no `.26` é **substituída** a cada recálculo (`DELETE` + `INSERT` por execução), servindo como **visão atual** para dashboards.

### 2.3. Quando roda o quê

```
MySQL (wp_envios_pendentes, eventos_*, salesforce_returns)
        │
        │  WP-Cron diário: painel_campanhas_daily_mssql_bridge
        ▼
PC_Wp_Mssql_Bridge::run_daily_operational_job()
        ├─► MERGE em PC_WP_MIRROR_ROWS (espelho completo)
        └─► rebuild PC_LINE_HEALTH_SNAPSHOT

Abertura das telas de saúde (AJAX)
        │
        │  pc_get_all_connections_health  e/ou  pc_get_line_health
        ▼
PC_Wp_Mssql_Bridge::on_operational_health_page_visit()
        └─► só rebuild PC_LINE_HEALTH_SNAPSHOT (rápido; dados ao vivo do MySQL)
```

- **`pc_get_line_health`** (React **OperationsHealth** / métricas): lê **`PC_LINE_HEALTH_SNAPSHOT`**; se vazia, faz **fallback** para histórico append-only **`TB_SAUDE_LINHAS`** (Nest legado).
- **Página “Saúde das Linhas”** (conexões por carteira): continua em **`pc_get_all_connections_health`**; antes da resposta, dispara o **rebuild do snapshot** para alinhar tier no `.26` com o MySQL.

### 2.4. NestJS `LineHealthService` (legado)

- **Desligado por padrão** (`LINE_HEALTH_CRON_ENABLED` ≠ `true` no `.env`).
- Se reativado, continua gravando histórico em **`TB_SAUDE_LINHAS`** via probes HTTP + credenciais dinâmicas — **opcional** e **independente** da ponte WP→MSSQL.

---

## 3. Pipeline: rastreamento Salesforce (`TB_SALESFORCE_TRACKING`)

### Entrada: webhook HTTP

- A Salesforce (ou middleware) envia eventos para o NestJS:
  - **`POST /webhooks/salesforce/tracking`**
  - Corpo: JSON **plano** (chaves alinhadas ao contrato de tracking; matching **case-insensitive** no parser).
- Autenticação opcional: se **`SALESFORCE_TRACKING_WEBHOOK_SECRET`** estiver definido no `.env`, o header deve ser **`Authorization: Bearer <secret>`**. Se a variável estiver vazia, o endpoint aceita requisições sem Bearer (útil só em dev — em produção, configure o segredo).

### Processamento e persistência

1. **`SalesforceTrackingWebhookController`** responde com **`202 Accepted`** (`@HttpCode(HttpStatus.ACCEPTED)`) para não segurar a fila do lado Salesforce.
2. **`DigitalFunnelMssqlService.insertSalesforceTrackingFromPayload`**:
   - Exige **`uniqueid`** no payload; sem isso, não persiste (log + `persisted: false`).
   - **Idempotência:** calcula **`uniqueid_hash`**:
     - se o payload já trouxer `uniqueid_hash`, usa-o (truncado);
     - senão, gera **`SHA-256(uniqueid)`** em hexadecimal.
   - Executa **`INSERT`** explícito em colunas de **`dbo.TB_SALESFORCE_TRACKING`** (sem armazenar o evento bruto em um único campo JSON genérico): `uniqueid`, `uniqueid_hash`, `trackingtype`, `sendtype`, `mid`, `eid`, `contactkey`, `mobilenumber`, `eventdateutc`, `appid`, `channelid`, etc., até campos custom como `operacao__c`, `cpf_cnpj__c`, `name`, `TemplateName`.
3. Se o INSERT violar **unicidade** (ex.: erro SQL Server **2627/2601** ou mensagem de duplicate), o serviço trata como **duplicado idempotente**: log em nível warn e retorno **`persisted: true`** do ponto de vista de negócio (evento já conhecido).
4. Em falha de pool ou erro não duplicado, o controller ainda responde **`202`** com **`{ accepted: true, persisted: false }`** quando o erro é capturado no controller — priorizando **resposta rápida** ao chamador; erros são registrados em log.

Fluxo resumido:

```
Salesforce
    │  POST /webhooks/salesforce/tracking
    ▼
SalesforceTrackingWebhookController  ──► 202 Accepted
    │
    ▼
insertSalesforceTrackingFromPayload
    │  uniqueid → uniqueid_hash (SHA-256 se necessário)
    ▼
INSERT colunar em TB_SALESFORCE_TRACKING
    │
    ├─ sucesso        → persisted: true
    ├─ duplicado (UK) → idempotente, persisted: true
    └─ falha BD       → log; persisted: false (ainda 202)
```

### Leitura no WordPress

- O painel pode consultar dados de tracking Salesforce via fluxos **MySQL** / importações legadas (**`pc_get_salesforce_tracking`**, opções como `pc_last_salesforce_tracking_run`, etc.). A **fonte canônica colunar** dos eventos webhook no `.26` é a tabela **`TB_SALESFORCE_TRACKING`** alimentada pelo Nest; qualquer espelhamento para MySQL é pipeline à parte (import/cron PHP), não substitui o papel do webhook no Nest.

---

## 4. Configuração do ambiente e credenciais

### 4.1. O arquivo `.env` do NestJS (exemplo prático)

No deploy do microserviço, as variáveis ficam no **`.env`** (ou equivalente do orquestrador: Coolify, Docker Compose, etc.). Abaixo um exemplo **ilustrativo** — **não** commite segredos reais; use o `.env.example` na raiz do repositório como checklist.

**Nota de nomenclatura:** no código, a URL base do WordPress é **`WORDPRESS_URL`** (não `WORDPRESS_API_URL`). Todas as rotas REST são montadas a partir dela (ver `src/config/wordpress.config.ts`).

```env
# --- PostgreSQL / Redis (stack do Nest; omitidos aqui por foco MSSQL+WP) ---

# --- WordPress: base URL + chave para chamar a REST do painel (API Manager) ---
WORDPRESS_URL=https://seu-wordpress.com.br
WORDPRESS_API_KEY=sua_master_api_key_do_wp

# Alias aceito pelo código (se WORDPRESS_API_KEY estiver vazio):
# ACM_MASTER_API_KEY=sua_master_api_key_do_wp

# --- SQL Server (DB_DIGITAL @ 10.103.2.26 — escrita telemetria / funil) ---
MSSQL_ENABLED=true
MSSQL_HOST=10.103.2.26
MSSQL_PORT=1433
MSSQL_DATABASE=DB_DIGITAL
MSSQL_USER=user_digital
MSSQL_PASSWORD=use_um_segredo_no_deploy
MSSQL_ENCRYPT=true
MSSQL_TRUST_SERVER_CERTIFICATE=true
# Opcional: propagar erros de gravação MSSQL (padrão costuma ser false)
# MSSQL_STRICT=false

# --- Saúde Nest (legado): cron 06:00 + LINE_HEALTH_TARGETS — desligado por padrão ---
# A saúde operacional no .26 vem da ponte PHP (PC_WP_MIRROR_ROWS + PC_LINE_HEALTH_SNAPSHOT).
# LINE_HEALTH_CRON_ENABLED=false
# LINE_HEALTH_TARGETS=[]

# Webhook Salesforce (seção 3)
# SALESFORCE_TRACKING_WEBHOOK_SECRET=
```

### 4.2. Busca dinâmica de credenciais no Nest (dispatch e legado `LineHealth`)

Tokens de Z-API, Evolution, NOAH, GOSAC, Salesforce por ambiente **não** ficam no `.env` do Nest como lista fixa. Eles vivem no **WordPress** (API Manager).

Fluxo usado pelo **dispatch de campanhas** e, **se** `LINE_HEALTH_CRON_ENABLED=true`, pelo **`LineHealthService`** legado:

```
(provider, envId)
        │
        ▼
CampaignsService.fetchCredentials(provider, envId)
        │
        │  GET {WORDPRESS_URL}/wp-json/api-manager/v1/credentials/{provider}/{envId}
        │  Header: X-API-KEY: {WORDPRESS_API_KEY ou ACM_MASTER_API_KEY}
        ▼
WordPress devolve credenciais atualizadas
```

**Benefício:** alteração na interface WP vale no próximo job/cron **sem reiniciar** o Nest.

### 4.3. Ponte PHP (WordPress) — credenciais MSSQL

- A sincronização **MySQL → `.26`** usa o mesmo **`PC_SqlServer_Connector`** já configurado no painel (**API Manager** / `wp-config`).
- Não é necessário duplicar no Nest os dados das tabelas operacionais do WP: o **cron WordPress** `painel_campanhas_daily_mssql_bridge` empurra o espelho e atualiza o snapshot.

---

## Referências no repositório

| Componente | Caminho (indicativo) |
|------------|----------------------|
| Pool MSSQL Nest | `src/sql-server/sql-server.service.ts` |
| Escritas telemetria / webhook | `src/sql-server/digital-funnel-mssql.service.ts` |
| Webhook Salesforce | `src/webhooks/salesforce-tracking-webhook.controller.ts` |
| Cron saúde linhas (legado Nest) | `src/line-health/line-health.service.ts` |
| `fetchCredentials` + chamada HTTP ao WP | `src/campaigns/campaigns.service.ts` |
| Montagem das URLs REST do WordPress | `src/config/wordpress.config.ts` |
| Conector MSSQL PHP | `painel-campanhas-install-2/includes/class-pc-sqlserver-connector.php` |
| Ponte MySQL → MSSQL + snapshot | `painel-campanhas-install-2/includes/class-pc-wp-mssql-bridge.php` |
| Variáveis Nest (exemplo) | `.env.example` |

---

*Documento alinhado ao código do repositório; revisar após mudanças em schema DBA ou variáveis de ambiente.*
