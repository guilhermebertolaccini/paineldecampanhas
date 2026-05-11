# Relatório de Prontidão para Produção — Troca dinâmica de template (campanhas recorrentes)

**Escopo:** Fluxo *React → WordPress (AJAX) → fila `envios_pendentes` → NestJS (no passo de aprovação)* para a feature **“Gerar agora” com override de template**.

**Data do review (código):** 2026.

---

## 1. Visão do fluxo ponta a ponta

| Etapa | O que ocorre |
|--------|----------------|
| **React** | `executeRecurringNow(id, payload?)` envia **FormData** AJAX `action=cm_execute_recurring_now`, `nonce` (`cmNonce`), mais campos do override quando o usuário troca o template. |
| **PHP `handle_execute_recurring_now`** | Validação (nonce, permissões, base “hoje”, campanha ativa) → **transação best-effort** (`START TRANSACTION`) → `pcm_try_apply_execute_recurring_template_override` (opcional) → `execute_recurring_campaign_optimized` → atualiza `ultima_execucao` → `COMMIT` ou `ROLLBACK`. |
| **`execute_recurring_campaign_optimized`** | Lê **`$campaign`** (já atualizado em memória após o override); monta `mensagem` JSON por contato com `template_code`, `variables_map`, meta (Noah/Gosac/Making/Ótima, etc.); insere em **`wp_envios_pendentes`** com `status = pendente_aprovacao`. **Não chama NestJS.** |
| **PHP → NestJS** | Ocorre na **aprovação** (`wp_remote_post` para `.../campaigns/dispatch`): payload montado a partir das linhas da fila; se a rede falhar, o registro **permanece `pendente_aprovacao`** para nova tentativa — já documentado no código próximo à linha ~8964 do `painel-campanhas.php`. |

---

## 2. Pontos fortes

1. **Contrato React ↔ PHP alinhado ao salvamento recorrente:** campos como `template_id`, `template_code`, `template_source`, `broker_code`, `customer_code`, `variables_map` (string JSON), Noah/Gosac/Making são os mesmos conceitos de `cm_save_recurring` / fluxo manual.
2. **`variables_map`:** no React, `serializeRecurringExecuteTemplatePayload` envia `variables_map` como **string JSON** quando há mapeamento; o PHP faz `stripslashes` + `json_decode` e persiste com `wp_json_encode`. Na montagem do lote, `execute_recurring_campaign_optimized` usa `$campaign['variables_map']` decodificado — **não há cache intermédio**: é só leitura da linha em memória já mesclada após o `UPDATE`.
3. **Compatibilidade de tipo de template:** `pcm_recurring_execute_template_sources_compatible` impede saltos incompatíveis entre famílias de template; permite transições esperadas (ex.: Ótima WPP ↔ RCS, Noah ↔ Noah Oficial).
4. **Autorização na persistência:** override só com `UPDATE ... WHERE id = %d AND criado_por = %d`, coerente com “só o dono altera o filtro”.
5. **Integridade no “Gerar agora” (nova mitigação):** foi introduzido **`START TRANSACTION` … `COMMIT` / `ROLLBACK`** em `handle_execute_recurring_now`, envolvendo **`pcm_try_apply_*` + inserções em massa + `pc_campaign_settings` (throttling dentro do optimized) + `ultima_execucao`** na mesma transação **quando o motor suportar InnoDB e `START TRANSACTION` funcionar**. Falha de lote → `ROLLBACK` também desfaz o **UPDATE do template** na `cm_recurring_campaigns`, evitando “template novo salvo + fila incompleta/ausente”.
6. **Flag explícita de override:** `apply_recurring_template_override` deixa de usar apenas `empty()` sobre o POST; valores como **`1` / `true` / `yes` / `on`** habilitam override; strings vazias ou não reconhecidas ignoram o override de forma previsível.

---

## 3. Riscos residualizados e observações

### 3.1 Transações (MySQL/InnoDB vs legado)

- **`START TRANSACTION` pode falhar** (driver, MyISAM em alguma tabela envolvida, hospedagem restrita): o código **regista aviso** e prossegue **sem atomicidade** — comportamento legado, documentado no log.
- **`COMMIT` falho** após sucesso lógico: raro; há log de aviso. Operacionalmente: monitorar `error_log`.

### 3.2 “Gerar agora” ≠ Dispatch NestJS

- O novo template aparece nas linhas **`envios_pendentes.mensagem`** geradas neste pedido.
- **O Nest só vê esse JSON no momento da aprovação.** Não há risco neste código de “Nest puxar template antigo por cache da recorrência” no dispatch: **a origem são as linhas da fila já gravadas.**

### 3.3 Falha Nest após fila já aprovada (`pendente_aprovacao` → dispatch)

- O fluxo já **mantém pendentes** se `wp_remote_post` falhar (ver comentários e logs perto da aprovação).
- **Recuperação:** reenviar aprovação; não é necessário reexecutar “Gerar agora” salvo ausência real de registros na fila.

### 3.4 React — omissão de campos numéricos `0`

- `executeRecurringNow` **omite `null` e `undefined`**; **`0`** ainda é enviado (`if (raw === undefined || raw === null) continue`).
- Campos válidos como `gosac_connection_id`, `making_team_id`, etc., não devem ser `undefined` quando necessários — a UI já barra antes do envio.

### 3.5 Tipagem

- **Front:** `RecurringExecuteTemplatePayload` descreve o override; o envio real é **FormData** (strings/números coerentes com `$_POST` no PHP).
- **PHP:** arrays `ARRAY_A`; `wpdb->update` com formatos `%d/%s`; sem strict types na classe WP — esperado neste codebase.

---

## 4. Itens já corrigidos nesta auditoria

1. **`handle_execute_recurring_now`:** transação com rollback em erro (template + fila + `ultima_execucao`).
2. **`pcm_try_apply_execute_recurring_template_override`:** detecção robusta da flag **`apply_recurring_template_override`**.
3. **Helper `pcm_recurring_execute_maybe_rollback`:** rollback idempotente e logado.

---

## 5. Checklist operacional antes de produção

- [ ] Tabelas **`cm_recurring_campaigns`**, **`envios_pendentes`**, **`pc_campaign_settings`** em **InnoDB** (recomendado para a transação ser efetiva).
- [ ] Monitorar **`error_log`** para `START TRANSACTION`, `COMMIT`, `ROLLBACK` e falhas `bulk_insert_recurring`.
- [ ] Smoke test: override → **Gerar agora** → inspecionar 1 linha em `envios_pendentes.mensagem` → aprovar → confirmar Nest recebe o JSON esperado.
- [ ] Teste negativo: simular erro SQL na inserção (ex.: permissão) e confirmar **`ROLLBACK`** (template na recorrência inalterado após erro).

---

## 6. Conclusão

A cadeia **React → PHP → fila WP** está **coerente e alinhada** para uso em produção, com ganho importante de **consistência** entre override na `cm_recurring_campaigns` e geração da fila. A ponte para o **NestJS** continua sendo o fluxo de **aprovação com dispatch**, já com tratamento para falha de rede mantendo estado recuperável nos pendentes.

**Recomendação:** publicar **`painel-campanhas.php`** com estas alterações e repetir regressão rápido nos provedores prioritários (Ótima, GOSAC, Noah, Making, local).
