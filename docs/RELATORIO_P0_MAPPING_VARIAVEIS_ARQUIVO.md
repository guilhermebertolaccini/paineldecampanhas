# Relatório P0 — Variáveis vazias em campanha por arquivo (CSV)

## Resumo executivo

Houve perda/consistência incorreta **na camada NestJS na integração com a Ótima (WhatsApp e RCS)**, combinada com **espelhamento incompleto** no endpoint REST WordPress (`get_campaign_data_rest`): apenas `making_oficial` copiava `mensagem.variables` para `CampaignData.variables`.  

O fluxo PHP em `handle_create_cpf_campaign` já grava corretamente `variables_map` **e** `variables` por linha no JSON da coluna `mensagem`; o problema estava principalmente na **forma como o Nest indexava os valores já resolvidos** (usando o **nome da coluna CSV** onde o objeto PHP usa a **chave da variável do template**).

## Onde o dado se perdeu (ou foi lido errado)

| Camada | Situação |
|--------|----------|
| **React (`CampanhaArquivo` + `createCpfCampaign`)** | Envia `variables_map` no formato esperado (`{ varTemplate: { type, value: coluna } }`). **Sem falha identificada** para o mapeamento em si. |
| **PHP (`handle_cpf_upload_csv` + `handle_create_cpf_campaign`)** | Monta `rows_by_match`, faz merge na base (`merge_csv_rows_into_cpf_records`) e resolve com `resolve_noah_variables_row_for_csv` / GOSAC / etc. As chaves em `mensagem.variables` são os **nomes lógicos da variável do template** (ex.: `valor`, `data`), não necessariamente o cabeçalho da planilha. **Correto para consumo por chave de template.** |
| **WordPress REST `get_campaign_data_rest`** | Antes: só mesclava `mensagem.variables` em `variables` quando `template_source === 'making_oficial'`. Nos demais provedores, o Nest dependia só de parsear `mensagem` ou tratar `variables` reduzidas. **Corrigido:** merge de `mensagem.variables` para `otima_wpp`, `otima_rcs`, `gosac_oficial`, `noah_oficial`, `robbu_oficial`, `techia_discador`, etc. (`painel-campanhas.php`). |
| **NestJS — `WhatsappOtimaProvider` / `RcsOtimaProvider`** | Para `variables_map[].type === 'field'`, o código buscava em `mensagem.variables` usando **`mapping.value` (nome da coluna CSV)**. O PHP gravou valores sob **`varName` (nome da variável do template)**. Resultado típico: variáveis resolvidas no JSON e, mesmo assim, **strings vazias** enviadas à API. **Corrigido:** priorizar `varName` (e `{{n}}` sem chaves) em `mensagem.variables`, depois fallback pela coluna + `item.variables` + raiz. |
| **NestJS — `MakingOficialProvider.mapMakingVariables`** | Já funcionava bem com `CampaignData.variables` após REST; foi adicionada leitura explícita de `mensagem.variables` para redundância. |

## Correções aplicadas

1. **`painel-campanhas-install-2/painel-campanhas.php`** — `get_campaign_data_rest`: espelhar `mensagem.variables` para `variables` nos `template_source` oficiais listados acima.

2. **`src/providers/whatsapp-otima/whatsapp-otima.provider.ts`** — Resolver variáveis de campo pela **chave do template** primeiro; log `[VAR DEBUG]` com a **primeira mensagem** do payload da API Ótima.

3. **`src/providers/rcs-otima/rcs-otima.provider.ts`** — Mesma correção para RCS; log `[VAR DEBUG]` na primeira mensagem.

4. **`src/providers/base/template-variable-failsafe.ts`** (novo) — `enforceTemplateVariableIntegrity`:
   - Se existir `variables_map` não vazio, **bloqueia o lote** se qualquer entrada `field` tiver valor final vazio (compara `mensagem.variables` + REST `variables`, case-insensitive).
   - `text` fixo também não pode ficar em branco.
   - **GOSAC:** se existir `contact_variables` não vazio, valida cada `value`.
   - Lança `Error('FALHA DE SEGURANÇA: ...')` → `BaseProviderProcessor` marca **FAILED**, webhook **`erro_envio`**.

5. **`src/jobs/providers/base-provider.processor.ts`** — Chama `enforceTemplateVariableIntegrity` **imediatamente antes** de `provider.send()`, após webhook “processando”.

6. **`src/providers/making-oficial/making-oficial.provider.ts`** — `mapMakingVariables` injeta valores de `mensagem.variables` antes de `item.variables`.

## Comportamento pós-patch

- Ótima WPP/RCS volta a enviar os valores vindos do PHP quando o usuário mapeia colunas → variáveis de template.
- Se ainda assim alguma variável **mapeada** chegar vazia, o Nest **não** chama o POST do fornecedor; falha o lote com mensagem explícita e notificação WordPress de erro.

## Validação

- `npm run build` no repositório Nest — **sucesso.**

## Observação operacional

- Deploy conjunto recomendado: **plugin WP (PHP)** + **NestJS**, para REST enriquecida e validação/rota de envio alinhadas.
