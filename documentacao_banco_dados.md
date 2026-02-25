# Arquitetura de Banco de Dados: Painel de Campanhas (NestJS + Prisma + PostgreSQL)

Este documento detalha a modelagem de dados do novo backend, desenhada para suportar alta volumetria de disparos, controle granular de orçamentos e facilidade de integração via BullMQ.

O principal ajuste em relação a uma modelagem convencional é a **separação do "Cabeçalho da Campanha" (`Campaign`) dos "Leads/Contatos" (`CampaignLead`)**. Isso é fundamental quando você dispara, por exemplo, 50.000 mensagens: você não pode salvar 50 mil contatos no mesmo registro JSON sem destruir o banco.

---

## 1. Schema Completo (Sintaxe Prisma)

```prisma
// schema.prisma

generator client {
  provider = "prisma-client-js"
}

datasource db {
  provider = "postgresql"
  url      = env("DATABASE_URL")
}

// ------------------------------------------------------
// 1. Controle de Acesso e Usuários
// ------------------------------------------------------
model User {
  id         String   @id @default(uuid())
  name       String
  email      String   @unique
  password   String   // Hashed with Argon2
  role       Role     @default(USER) // Enum: ADMIN, USER
  created_at DateTime @default(now())
  updated_at DateTime @updatedAt
  
  campaigns  Campaign[] // Relação reversa (quem criou a campanha)
}

enum Role {
  ADMIN
  USER
}

// ------------------------------------------------------
// 2. Entidades de Negócio (Carteiras e Fornecedores)
// ------------------------------------------------------
model Wallet {
  id             String   @id @default(uuid())
  name           String
  idgis_ambiente Int      @unique // ID utilizado nas integrações legadas GOSAC/CDA
  
  created_at     DateTime @default(now())
  updated_at     DateTime @updatedAt

  budgets        Budget[]
  credentials    Credential[]
  campaigns      Campaign[]
}

model Broker {
  id               String   @id @default(uuid())
  name             String   // ex: GOSAC, CDA, SALESFORCE, NOAH, OTIMA_WPP, OTIMA_RCS
  cost_per_message Decimal  @default(0.0) @db.Decimal(10, 4) // Custo unitário por disparo
  
  created_at       DateTime @default(now())
  updated_at       DateTime @updatedAt

  campaigns        Campaign[]
  credentials      Credential[]
}

// ------------------------------------------------------
// 3. Credenciais Dinâmicas (Por Carteira x Fornecedor)
// ------------------------------------------------------
model Credential {
  id          String   @id @default(uuid())
  wallet_id   String
  broker_id   String
  
  // JSON flexível! 
  // GOSAC: { "url": "...", "token": "..." }
  // Salesforce: { "client_id": "...", "client_secret": "...", "automation_id": "..." }
  config      Json     

  created_at  DateTime @default(now())
  updated_at  DateTime @updatedAt

  wallet      Wallet   @relation(fields: [wallet_id], references: [id], onDelete: Cascade)
  broker      Broker   @relation(fields: [broker_id], references: [id], onDelete: Cascade)

  // Uma carteira só tem UMA credencial ativa por fornecedor
  @@unique([wallet_id, broker_id])
}

// ------------------------------------------------------
// 4. Controle Financeiro (Budgets)
// ------------------------------------------------------
model Budget {
  id          String   @id @default(uuid())
  wallet_id   String
  month       Int      // 1 a 12
  year        Int      // ex: 2026
  
  budget      Decimal  @db.Decimal(12, 2) // Orçamento limite aprovado
  used_budget Decimal  @default(0.0) @db.Decimal(12, 2) // Orçamento consumido
  
  created_at  DateTime @default(now())
  updated_at  DateTime @updatedAt

  wallet      Wallet   @relation(fields: [wallet_id], references: [id], onDelete: Cascade)

  // Uma carteira só tem um orçamento por mês/ano
  @@unique([wallet_id, month, year])
}

// ------------------------------------------------------
// 5. HSM e Templates Oficiais (Ótima, Gupshup, etc)
// ------------------------------------------------------
model Template {
  id            String   @id @default(uuid())
  name          String
  template_code String   @unique // O código exigido na API (ex: cobranca_acordo_v3)
  content       String   // O texto formatado com {{1}}, {{2}} para visualização UI
  
  created_at    DateTime @default(now())
  updated_at    DateTime @updatedAt

  campaigns     Campaign[]
}

// ------------------------------------------------------
// 6. Restrições e Pescadores (Blacklist e Whitelist)
// ------------------------------------------------------
model Blocklist {
  id         String   @id @default(uuid())
  phone      String?  
  cpf_cnpj   String?  
  reason     String?
  
  created_at DateTime @default(now())
  updated_at DateTime @updatedAt
  
  // Índices para busca rápida antes de disparar
  @@index([phone])
  @@index([cpf_cnpj])
}

model Fish {
  id         String   @id @default(uuid())
  name       String
  phone      String   @unique
  
  created_at DateTime @default(now())
  updated_at DateTime @updatedAt
}

// ------------------------------------------------------
// 7. Core do Disparo (Cabeçalho da Campanha)
// ------------------------------------------------------
model Campaign {
  id                String       @id @default(uuid())
  agendamento_id    String       @unique // O ID de rastreamento principal (gerado no backend)
  name              String       
  
  user_id           String       // Quem criou o disparo
  wallet_id         String
  broker_id         String
  template_id       String?      // Opcional, usado apenas para provedores oficiais (Ótima)
  
  status            CampaignStatus @default(PENDING_APPROVAL)
  
  // Configuração Global da Campanha (Mensagem Genérica e Throttling)
  base_message      String?      // Utilizado para SMS/RCS se não for template
  throttling_type   String       @default("none") // none, fixed, random
  throttling_config Json?        // ex: { delayLimit: 120, maxRandom: 300 }
  
  scheduled_at      DateTime?    // Para disparos agendados no futuro
  
  created_at        DateTime     @default(now())
  updated_at        DateTime     @updatedAt

  leads             CampaignLead[]
  
  user              User         @relation(fields: [user_id], references: [id])
  wallet            Wallet       @relation(fields: [wallet_id], references: [id])
  broker            Broker       @relation(fields: [broker_id], references: [id])
  template          Template?    @relation(fields: [template_id], references: [id])
}

enum CampaignStatus {
  PENDING_APPROVAL // Fila da supervisão
  APPROVED         // Aprovado, aguardando o cron/BullMQ puxar
  PROCESSING       // BullMQ está enviando ativamente
  PAUSED           // Pausado manualmente
  FINISHED         // Disparo concluído com sucesso
  FAILED           // Erro fatal (ex: saldo insuficiente, API Key Inválida)
}

// ------------------------------------------------------
// 8. Core do Disparo (As Linhas/Contatos a Receberem)
// ------------------------------------------------------
model CampaignLead {
  id              String       @id @default(uuid())
  campaign_id     String
  
  // Dados do lead
  phone           String
  nome            String?
  cpf_cnpj        String?
  idcob_contrato  String?      // Fundamental para RCS CDA e Salesforce
  
  // A mensagem a ser enviada. Se for nula, o Worker usa a `base_message` da Campaign ou o `template`
  custom_message  String?      
  
  // Status individual do envio
  status          LeadStatus   @default(PENDING)
  provider_msg_id String?      // ID de retorno do fornecedor (para webhook de leitura/entrega)
  error_message   String?      // Se deu erro, salva o motivo aqui
  sent_at         DateTime?    // A hora exata que disparou
  
  campaign        Campaign     @relation(fields: [campaign_id], references: [id], onDelete: Cascade)
  
  @@index([campaign_id])
  @@index([phone])
}

enum LeadStatus {
  PENDING   // Fila
  SENT      // Aceito na API
  DELIVERED // Webhook avisou que entregou
  READ      // Webhook avisou que leu
  ERROR     // Telefone inválido, blocklist, etc
}
```

---

## 2. Padrões de Arquitetura e Decisões Tomadas

### 2.1 Separação `Campaign` (Header) e `CampaignLead` (Items)
No WordPress, todo o disparo ia para uma única tabela (`envios_pendentes`), o que misturava a configuração da Campanha com os contatos.
No novo modelo:
* A `Campaign` concentra os limites de negócio: Quantos minutos de throttling? Qual carteira pagará a conta? Qual broker vai usar?
* O `CampaignLead` é a unidade de trabalho que o **BullMQ** vai processar.

**Fluxo BullMQ com essa estrutura:**
1. O usuário sobe CSV com 10.000 linhas.
2. O NestJS cria 1 `Campaign`.
3. O NestJS usa o `prisma.campaignLead.createMany` para inserir 10.000 linhas ligadas a ela instantaneamente.
4. Ao aprovar, um *Job* no BullMQ pega a `Campaign` e joga 10.000 sub-jobs na fila de disparos. Fica perfeitamente rastreável e se uma mensagem der erro HTTP, só ela terá o status `ERROR`.

### 2.2 Controle Estrito de Custos (Budgets)
Ao adicionar o campo `used_budget`, abrimos margem para um "Interceptor/Guard" ou até mesmo dentro do Job do BullMQ verificar antes de cada disparo de lote:

```typescript
// Lógica de pseudo-código
const custoMensagem = broker.cost_per_message;
const custoLote = lote.length * custoMensagem;

const budget = await prisma.budget.findUnique(...);

if (budget.used_budget + custoLote > budget.budget) {
   throw new Error("Budget Insufficient!");
}
```
Isso previne que contas estourem.

### 2.3 `idcob_contrato` é Nativo (Entity Core)
Nas plataformas legadas, o `idcob_contrato` era tratado como um campo qualquer, causando problemas (como descobrimos e arrumamos nas integrações CDA/Salesforce passadas). Transformamos ele numa coluna raiz no `CampaignLead` para que provedores que exijam ele possam apenas buscar `lead.idcob_contrato`.

### 2.4 Credenciais via JSON (`config`)
Criar colunas rígidas (`token_url`, `client_secret`, `access_token_mkc`) na tabela de configuração trava o banco se amanhã entrar um fornecedor novo que exija 4 chaves (Ex: Twilio usa `Account SID` e `Auth Token`).

Ao definir o campo `config` como `Json` (tipado pelo Postgres como JSONB), seu backend ganha total flexibilidade.
Exemplo no Typescript:
```typescript
interface GosacConfig { url: string; token: string; }
interface SalesforceConfig { client_id: string; client_secret: string; url: string; operacao: string; }

const cred = await prisma.credential.findFirst(...);
const config = cred.config as SalesforceConfig;
```

---

## 3. Resumo de Migração do Legado (Onde estavam -> Onde Ficam)

| Antigo WP / Plugin | Novo Schema Prisma |
| :--- | :--- |
| `options(acm_master_api_key)` | Removido. Autenticação dos Microsserviços -> Feita via Roles ou JWT padrão API no `User` (ou Admin). |
| `options(acm_provider_credentials)` | `Credential` model (Coluna JSON `config`). |
| `pc_orcamentos_bases` | `Budget` model. |
| `envios_pendentes` | Dividido em `Campaign` (O disparo único) e `CampaignLead` (As listas de nomes e numeros). O status da linha passou a ser Enum `LeadStatus`. |
| Campo livre HTML/JSON da Mensagem | Coluna JSON dentro de `Credential` quando exigido ou `Template` (`template_code`) para envios oficiais. |
| Colunas soltas `id_carteira` | Resolvido com chaves estrangeiras (`wallet_id`). O `idgis_ambiente` se torna um identificador em `Wallet` para mapear com o banco do cliente. |
