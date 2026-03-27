# Skill: nestjs-dispatch-worker
**Objetivo:** Modificar o orquestrador de filas, comunicação com providers e gravação de logs no microserviço NestJS.

## Instruções para a IA:
1. **Fluxo do BullMQ:** Ao alterar lógicas de envio, respeite a separação de responsabilidades. O Controller recebe o `agendamento_id`, enfileira o job e o *Processor* (worker) assume a execução.
2. **Busca de Contexto:** O microserviço não possui todas as regras. O Worker deve usar a função `fetchDataFromWordPress(agendamentoId)` para resgatar credenciais, variáveis e template em JSON do WordPress.
3. **Rastreabilidade (Prisma):** Atualize o status das mensagens (`CampaignMessage`) e campanhas (`Campaign`) no banco PostgreSQL via Prisma, garantindo que o relatório final esteja alinhado com `documentacao_banco_dados.md`.
4. **Tratamento de Erros:** Disparos falhos (ex: timeout do provider) devem utilizar a lógica de *retry* do BullMQ antes de marcar a mensagem como falha no Prisma.