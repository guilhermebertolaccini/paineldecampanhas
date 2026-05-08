# Guia de assunção do projeto Midpainel (NestJS + WordPress)

Documento didático para quem vai **manter, evoluir ou operar** este repositório. Descreve o propósito do sistema, a arquitetura, os ficheiros críticos e práticas recomendadas.

---

## 1. O que este projeto é (propósito)

**Nome do pacote:** `midpainel` (NestJS).

**Função central:** atuar como **motor de disparo de campanhas** de mensageria (SMS, RCS, WhatsApp, integrações com CRM, etc.). O **WordPress** (plugin em `painel-campanhas-install-2/`) é o painel onde o utilizador cria campanhas, aprova e gere dados; o **NestJS** é o **worker orientado a filas** que:

- recebe um pedido de **dispatch** com um `agendamento_id`;
- identifica o **fornecedor** (provedor) pelo prefixo do ID;
- obtém **dados dos contactos** e **credenciais** via REST do WordPress;
- persiste estado de campanha em **PostgreSQL** (Prisma);
- enfileira trabalhos no **Redis** (BullMQ);
- executa envios através de **providers** (HTTP para APIs externas);
- atualiza **SQL Server** (funil digital / envios pendentes, quando configurado);
- **notifica o WordPress** via webhook REST para o estado da campanha (enviado, erro, processando, etc.).

Em resumo: **WordPress = UI e fonte de negócio (MySQL)**; **NestJS = execução assíncrona, fiabilidade e integrações técnicas**; **Redis = filas**; **PostgreSQL = estado operacional das campanhas no motor**; **SQL Server = telemetria / funil corporativo** (opcional, conforme `.env`).

---

## 2. Visão arquitetural (componentes)

```
[WordPress + React SPA]  ──REST (Master API Key)──►  [NestJS API]
        │                                                    │
        │ campaign data, credentials, config                 │ dispatch POST
        ▼                                                    ▼
   MySQL (envios, etc.)                              [BullMQ / Redis]
                                                           │
                                                           ▼
                                                    [Workers por provedor]
                                                           │
                         ┌─────────────────────────────────┼─────────────────────┐
                         ▼                                 ▼                     ▼
                  [APIs fornecedores]              [PostgreSQL]          [SQL Server opcional]
                  Ótima, GOSAC, …                  campanhas/mensagens    TB_ENVIOS_PENDENTES, …
```

**Fluxo mental útil:**

1. Algo no ecossistema (normalmente WordPress ou automação) chama `POST /campaigns/dispatch` com `agendamento_id`.
2. O job `dispatch-campaign` carrega tudo do WordPress, cria/atualiza campanha no Postgres, escreve linhas “aguardando” no MSSQL (se ativo), **parte a carga em lotes** (throttling) e empilha jobs na fila do **provedor** certo.
3. Cada job de envio chama `provider.send(...)` e, ao terminar, atualiza Postgres, MSSQL e **webhook** no WordPress.

---

## 3. Stack tecnológica (raiz do repositório)

| Tecnologia | Uso |
|------------|-----|
| **NestJS 11** | Framework HTTP, DI, módulos, guards |
| **BullMQ 5** + **ioredis** | Filas e workers |
| **Prisma 7** + **PostgreSQL** | Modelo `Campaign` / `CampaignMessage`, cache de saúde Ótima, histórico do validador |
| **axios** / **@nestjs/axios** | Chamadas HTTP a WordPress e fornecedores |
| **mssql** | Escrita/leitura em SQL Server (funil) |
| **@nestjs/schedule** | Crons (ex.: saúde de linhas, SFTP Ótima) |
| **ssh2-sftp-client**, **xlsx** | Integração SFTP Ótima (planilhas de linhas) |

**Outra árvore importante:** `painel-campanhas-install-2/` — plugin WordPress + SPA React (Vite). O Nest não substitui isso; **comunica-se com o WP por REST**.

---

## 4. Ponto de entrada e configuração global

| Ficheiro | Papel |
|----------|--------|
| `src/main.ts` | Bootstrap: `ValidationPipe` global (whitelist, forbidNonWhitelisted), **CORS** com `CORS_ORIGIN` ou default fixo, `PORT` (default 3000). |
| `src/app.module.ts` | Importa todos os módulos de domínio: Prisma, SqlServer, Campaigns, Providers, Jobs, LineHealth, Webhooks, WpSync, Validator, OtimaSftpSync. |
| `.env` / `.env.example` | Variáveis: `DATABASE_URL`, Redis, `WORDPRESS_URL`, `WORDPRESS_API_KEY`, MSSQL, Evolution (validador), SFTP Ótima, segredos de webhook Salesforce, etc. |

**Regra prática:** nunca commitar `.env` com segredos reais; usar `.env.example` como checklist de deploy.

---

## 5. Módulos Nest principais (mapa mental)

### 5.1 `CampaignsModule` — orquestração de campanhas via API

| Ficheiro | Função |
|----------|--------|
| `src/campaigns/campaigns.controller.ts` | `POST /campaigns/dispatch` (aceite na fila), `GET /campaigns/:id/status`. Protegido por **`ApiKeyGuard`** (header de API key alinhado ao WordPress). |
| `src/campaigns/campaigns.service.ts` | **`identifyProvider(agendamento_id)`** — primeiro carácter do ID mapeia para `CDA`, `RCS_OTIMA`, `WHATSAPP_OTIMA`, etc. **`fetchDataFromWordPress`**, **`fetchCredentials`**, **`fetchThrottlingConfig`**, **`createCampaign`**, **`createCampaignMessages`**, **`dispatchCampaign`**. **`mapCredentials`** normaliza tokens/broker/customer_code por provedor. |

### 5.2 `JobsModule` — filas e workers

| Ficheiro | Função |
|----------|--------|
| `src/config/bullmq.config.ts` | Nomes das filas (`DISPATCH_CAMPAIGN`, `CDA_SEND`, `RCS_OTIMA_SEND`, …) e ligação Redis. |
| `src/jobs/dispatch-campaign.processor.ts` | Worker da fila **dispatch-campaign**: identifica provider, busca dados, credenciais, throttling, cria campanha Prisma, **`upsertEnviosAguardando` no MSSQL**, **distributeMessages**, **add jobs** na fila do provider. |
| `src/jobs/providers/base-provider.processor.ts` | Classe base dos envios: atualiza Prisma (PROCESSING/COMPLETED/FAILED), **`digitalFunnel.updateEnviosStatusTodos`**, **`webhookService.sendStatusUpdate`** (processando, enviado, erro). **Todo provedor “simples” deve reutilizar este fluxo** para paridade com WordPress. |
| `src/jobs/providers/*-send.processor.ts` | Um processor por fila (ex.: `whatsapp-otima-send.processor.ts`, `rcs-otima-send.processor.ts`) estendendo `BaseProviderProcessor` e definindo `providerName`. |

### 5.3 `ProvidersModule` — integrações com fornecedores

Cada provedor costuma ter: `*.module.ts`, `*.provider.ts` (implementa envio HTTP e validação de credenciais), possivelmente estendendo `BaseProvider` (`src/providers/base/base.provider.ts`) com **`executeWithRetry`**, normalização de telefone, etc.

Subpastas em `src/providers/`: `cda`, `gosac`, `noah`, `rcs`, `rcs-otima`, `whatsapp-otima`, `salesforce`, `gosac-oficial`, `noah-oficial`, `robbu-oficial`, `techia`, `making-oficial`, …

**Contrato:** `send(data: CampaignData[], credentials): Promise<ProviderResponse>` com `{ success, error?, data? }`.

### 5.4 `SqlServerModule` + `DigitalFunnelMssqlService`

| Ficheiro | Função |
|----------|--------|
| `src/sql-server/sql-server.service.ts` | Pool `mssql` (ligação condicionada a `MSSQL_ENABLED`). |
| `src/sql-server/digital-funnel-mssql.service.ts` | **`upsertEnviosAguardando`**, **`updateEnviosStatusTodos`** em `TB_ENVIOS_PENDENTES`; outras rotinas (Salesforce tracking, saúde, etc.) conforme evolução. |

Documentação de contexto: `docs/ARQUITETURA_MSSQL.md`.

### 5.5 `WebhookModule` — `WebhookService`

| Ficheiro | Função |
|----------|--------|
| `src/webhook/webhook.service.ts` | POST para `wp-json/webhook-status/v1/update` com `X-API-KEY`, retentativas, fila de concorrência, modo bulk opcional. |

### 5.6 Outros módulos (resumo)

| Módulo | Papel |
|--------|--------|
| `LineHealthModule` | Saúde de linhas (cron, MSSQL, eventual sync WP). |
| `WebhooksModule` | Ex.: webhook de tracking Salesforce (`salesforce-tracking-webhook.controller.ts`). |
| `WpSyncModule` | Sincronizações pontuais WordPress ↔ dados operacionais. |
| `ValidatorModule` | Validador de bases WhatsApp (Evolution API, ficheiros, histórico Prisma). |
| `OtimaSftpSyncModule` | Cron SFTP Ótima, ingestão XLSX → tabela `OtimaLineHealth` / snapshot MSSQL. |

---

## 6. Modelo de dados local (PostgreSQL / Prisma)

Ficheiro: `prisma/schema.prisma`.

- **`Campaign`**: liga a `agendamentoId` (único), `provider`, `status` (PENDING → … → COMPLETED/FAILED), totais e timestamps.
- **`CampaignMessage`**: uma linha por destino, `phone`, `status` (PENDING/SENT/FAILED).
- **`OtimaLineHealth`**: cache de **saúde de linhas** Ótima (atualizado pelo sync SFTP / rotinas de saúde).
- **`ValidatorHistory`**: histórico do validador (Evolution).

Migrações: fluxo Prisma habitual (`prisma migrate` / deploy conforme política da equipa).

---

## 7. Identificação do fornecedor pelo `agendamento_id`

A convenção está em `CampaignsService.identifyProvider`: o **primeiro carácter** do ID de agendamento escolhe o pipeline (ex.: prefixo para **RCS Ótima**, outro para **WhatsApp Ótima**). Qualquer novo canal precisa de:

- entrada no **mapa** em `identifyProvider`;
- fila em `bullmq.config.ts` + registro em `JobsModule` + `getProviderQueue` no dispatch;
- processor + provider + credenciais no `mapCredentials`.

---

## 8. Documentação já existente no repositório (leitura recomendada)

| Documento | Conteúdo |
|-----------|----------|
| `documentacao_regras_negocio.md` | Regras desejadas (anti-fadiga, budget, throttling, templates) — parte pode ser **aspiracional** vs implementado 100% no Nest; usar como especificação e auditoria. |
| `documentacao_fornecedores.md` | Detalhe por fornecedor (se estiver alinhado à versão atual). |
| `documentacao_banco_dados.md` | Notas de BD. |
| `docs/ARQUITETURA_MSSQL.md` | Papel do SQL Server, escrita Nest vs leitura WP. |
| `.cursor/skills/*.md` | Skills internas (dispatch worker, providers, PHP, React) — úteis para agentes e para humanos como índice. |

---

## 9. WordPress (`painel-campanhas-install-2/`) — relação com o Nest

Não é obrigatório programar PHP neste backend, mas **é obrigatório entender a ponte**:

- REST **`campaigns/v1/data/:id`**, **`api-manager/v1/credentials`**, **`campaigns/v1/config`**, **`webhook-status/v1/update`** (nomes aproximados — confirmar em `src/config/wordpress.config.ts`).
- O plugin define **fornecedor**, tabelas `envios_pendentes`, UI de campanhas e **Master API Key** (`WORDPRESS_API_KEY` no Nest deve coincidir com a chave configurada no WP).

Sem alinhar **URL** e **API key**, o dispatch ou o webhook falham com 401/404.

---

## 10. Melhores práticas para dar continuidade ao projeto

### 10.1 Segurança e configuração

- Tratar **`WORDPRESS_API_KEY`** e credenciais MSSQL/SFTP como segredos de produção.
- Manter **CORS** explícito (`CORS_ORIGIN`) nos ambientes que não usam o default do `main.ts`.
- Para novos endpoints expostos à internet, preferir **o mesmo padrão de autenticação** (`ApiKeyGuard` ou segredo específico) já usado em webhooks.

### 10.2 Novos provedores ou alterações em envio

1. Implementar **`send()`** retornando **`ProviderResponse`** consistente (`success` booleano confiável).
2. Preferir **`BaseProviderProcessor`** para **webhook + MSSQL + Prisma** iguais aos restantes.
3. Adicionar **`mapCredentials`** para todos os aliases que o WordPress possa enviar (tokens, `customer_code`, etc.).
4. Documentar timeout, rate limit e formato de payload da API externa nos comentários mínimos necessários (sem romance no código).

### 10.3 Filas e idempotência

- BullMQ: jobs podem **repetir** em falha transitória; o `base-provider.processor` já cria mensagens Prisma se não existirem — ter cuidado com **efeitos colaterais duplicados** em integrações externas (usar chaves idempotentes na API do fornecedor quando existirem).
- Throttling no **dispatch** gera **vários jobs** com **delays**; compreender que cada job dispara um **lote** e que o webhook final por lote pode afetar como o WordPress agrega estado (dependendo da implementação PHP).

### 10.4 Observabilidade

- Procurar logs com prefixos como **`[Webhook WP]`**, **`[RCS Ótima]`**, nome do processor.
- Em caso de “status preso no WordPress”: verificar (1) retorno `success` do provider, (2) resposta do webhook (status HTTP e `success` no body JSON do WP), (3) `agendamento_id` igual ao das linhas em `envios_pendentes`.

### 10.5 Qualidade de código

- Seguir o **estilo já presente** nos providers (logs, `executeWithRetry`, tratamento de erros HTTP).
- Executar **`npm run build`** antes de PR; **`npm run lint`** quando fizer sentido na equipa.
- Testes unitários (`*.spec.ts`) quando houver lógica condensada (parsing de payloads, `identifyProvider`, mapeamentos).

### 10.6 Deploy e operações

- Garantir **Redis** disponível e persistente conforme política (senão filas perdem-se).
- Garantir **PostgreSQL** backupado (estado de campanhas).
- Documentar no runbook: versão Node, variáveis obrigatórias, e se **MSSQL** é mandatório no ambiente ou opcional.

---

## 11. Comandos úteis (desenvolvimento)

| Comando | Descrição |
|---------|-----------|
| `npm install` | Instalar dependências |
| `npm run start:dev` | Nest em modo watch |
| `npm run build` | Compilação produção |
| `npm run lint` | ESLint |
| `npm test` | Jest |

Prisma: `npx prisma generate`, migrações conforme fluxo da equipa.

---

## 12. Resumo em uma frase

O **Midpainel** é um **serviço NestJS** que **desacopla o WordPress da execução pesada de campanhas**, usando **Redis/BullMQ** para processar envios por **provedor**, persistindo estado em **PostgreSQL**, opcionalmente **telemetria em SQL Server**, e **devolvendo o resultado ao WordPress por webhook** para o painel refletir o estado das campanhas.

---

*Última organização do guia baseada na estrutura do repositório (Nest + plugin WP em `painel-campanhas-install-2`). Ajustar exemplos de hosts e nomes de rotas se o seu fork divergir.*
