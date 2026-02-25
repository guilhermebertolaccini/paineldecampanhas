# Regras de Negócio: Motor de Disparos e Campanhas

Este documento compila todas as regras de negócio, proteções (safeguards) e validações que o backend (NestJS + BullMQ) deve aplicar rigorosamente antes, durante e depois da execução de qualquer campanha de mensagens.

Essas regras visam proteger o orçamento do cliente (Budgets), evitar bloqueios em provedores oficiais (Meta/WhatsApp), impedir spam (Anti-fadiga) e garantir a integridade da base de dados.

---

## 1. Regras de Ingestão e Validação de Base

Antes de uma campanha ser enviada para a fila de aprovação, a base de contatos (CSV ou input manual) deve passar pelos seguintes filtros:

### 1.1. Base Expirada (Data de Atualização)
A aplicação **não deve permitir disparos para bases desatualizadas**.
* **Regra:** O CSV importado (ou a data de referência `data_cadastro` do lead) não pode ser mais antiga que **X dias** (ex: 7 dias limite).
* **Motivo:** Evitar cobrança de clientes que já pagaram a dívida na última semana ou cujos contratos foram repassados para outra assessoria.
* **Ação do Sistema:** O endpoint de upload/criação deve rejeitar o arquivo/registro com HTTP 400 informando "Base expirada ou data inválida".

### 1.2. Higienização de Telefone e Documento
O dado puro que entra muitas vezes não obedece aos padrões rigorosos das APIs (como Ótima Oficial ou Salesforce).
* **Regra Telefone:** 
  * Remover todos os caracteres que não sejam números (ex: `(11) 99999-9999` vira `11999999999`).
  * Verificar se possui exatamente 10 (fixo) ou 11 (celular) dígitos.
  * *Para provedores internacionais/GOSAC/CDA:* Inserir o prefixo `55` automaticamente, caso não exista.
* **Regra CPF/CNPJ:** Remover formatações (`.`, `-`, `/`). Rejeitar registros onde o documento exigido for nulo (apenas para provedores que exigem, como Salesforce).

### 1.3. Blocklist Global e Opcional
* Qualquer lead cujo `phone` ou `cpf_cnpj` exista na tabela `Blocklist` **não pode ser inserido na tabela `CampaignLead`**, ou deve ser inserido com o `status` já marcado como `ERROR (Blocklist)`.
* **Procon / Não Perturbe:** Opcionalmente, importar bases públicas de "Não me ligue" para o Blocklist.

---

## 2. Regras de Anti-Fadiga (Rate Limit por Usuário)

Fundamental para evitar banimento na Meta (WhatsApp) e irritação do cliente (redução de eficácia da cobrança).

### 2.1. Bloqueio de 24 Horas (Duplicidade Cross-Campaigns)
* **Regra:** Um mesmo telefone (ou CPF) **não pode receber mais de uma mensagem no intervalo de 24 horas corridas**, independentemente de qual carteira, operador ou campanha originou o disparo.
* **Execução:** Antes da inserção no banco ou logo antes do Worker (BullMQ) pegar a mensagem da fila, é feita uma validação: 
  `SELECT 1 FROM CampaignLead WHERE phone = ? AND sent_at >= NOW() - INTERVAL 1 DAY AND status IN ('SENT', 'DELIVERED')`
* **Exceção de Negócio (Opcional):** Administradores podem forçar o bypass dessa regra usando uma flag (ex: `ignore_fatigue: true`) em casos de comunicados urgentes fora da cobrança comum.

### 2.2. Deduping Intra-Campaign (Duplicados no CSV)
* **Regra:** Se o mesmo telefone aparecer 3 vezes no mesmo CSV de upload de uma campanha, o backend deve **deduplicar e inserir apenas 1 linha**.
* **Motivo:** Economia de chamadas de API desnecessárias e proteção imediata.

---

## 3. Regras Financeiras (Budget Guard)

O sistema opera com um pré-pagamento virtual (`budget` mensal fixo).

### 3.1. Verificação Pré-Disparo (Approval Guard)
* **Regra:** Quando o Supervisor clica em "Aprovar Campanha", o sistema calcula o teto de gasto: `Teto = (Total_Leads_Validos) * (Custo_Por_Mensagem_do_Fornecedor)`.
* **Verificação:** Se `(used_budget + Teto) > total_budget`, a campanha não deve entrar para `PROCESSING`. A interface exibirá erro de "Saldo Insuficiente na Carteira".

### 3.2. Dedução em Tempo Real (Worker Guard)
* **Regra:** Dentro do loop do BullMQ (que pode enviar dezenas por segundo), a cada *batch* de 50 envios confirmados pela API do fornecedor com retorno HTTP 200, soma-se o custo no `used_budget` do mês/ano atual no banco.
* **Motivo:** Como campanhas podem estar rodando simultaneamente (ex: Carteira Itau 1 e Carteira Itau 2 atirando ao mesmo tempo), atualizar em tempo real previne "estouro" do budget se a campanha 1 já raspar todo o saldo antes da 2 terminar.

---

## 4. Regras de Disparo Temporal (Throttling)

Certas plataformas de recepção bloqueiam grandes picos de rede (Spam).

### 4.1. Limite de Tempo Útil (Horário Comercial)
* **Regra:** Mensagens só podem ser ativamente despachadas para o fornecedor entre **08:00 e 20:00**.
* **Execução:** Se uma campanha for aprovada às 19:55 e tiver 5.000 mensagens, as envidas a partir das 20:01 ficarão bloqueadas no banco ou a fila do Redis (BullMQ) será pausada automaticamente via Cron até as 08:00 do dia seguinte.

### 4.2. Delay Customizado (Warm-up)
Ao usar números de celular físicos ou conexões diretas não-oficiais (quando aplicável):
* **Regra Fixed:** A campanha inteira insere um atraso exato entre uma mensagem e outra (exemplo: mandar 1 a cada 30 segundos). Configurado no Frontend na etapa "Throttling".
* **Regra Random:** O BullMQ sorteia um delay aleatório entre o `min` e `max` configurado pelo usuário para emular digitação humana e driblar os algoritmos anti-robô das operadoras móveis.

---

## 5. Regras Específicas de Fornecedores Oficiais (Templates)

Para fornecedores como Ótima WhatsApp e RCS:

### 5.1. Obrigatoriedade de Template ID
* **Regra:** Diferente de SMS comum, campanhas direcionadas a fornecedores categorizados como "Oficiais" **não podem ter um texto digitado livremente (custom_message)**. O NestJS deve bloquear campanhas aprovadas onde o `template_id` (ou `template_code`) esteja vazio ou nulo. O texto livre é permanentemente bloqueado pelos provedores se fora da janela de interação do usuário (24h).

### 5.2. Mapeamento de Variáveis Obrigatórias
* **Regra:** Se o template escolhido exige, por exemplo, `{{1}}` para o Nome e `{{2}}` para o Contrato, o parser de envio da campanha deve garantir que as chaves `nome` e `idcob_contrato` **não sejam vazias** nos leads que compõem o lote. Se no lead essas chaves estiverem em branco, aquele lead específico falha (`LeadStatus = ERROR: Missing Template Variables`).

---

## Resumo Arquitetural para Implementação

Para garantir estas lógicas no NestJS, a estrutura de pastas recomendada é:

1. `Guards / Interceptors`: Verificar o `used_budget` e `Horário Comercial`.
2. `Pipes (Validation)`: Fazer a validação rigorosa (Regex) de Telefones e CPFs.
3. `Services > DedupingService`: Onde reside a query contra a tabela `Lead` buscando as 24 horas usando o Redis para *fast-lookup* (Cache do Redis pra não onerar o Postgres em toda validação de 24h).
4. `BullMQ Processors`: Lidar estritamente com o envio HTTP para o broker e a falha se o Rate Limit da API do fornecedor estourar (usando o recuo Exponencial Backoff).
