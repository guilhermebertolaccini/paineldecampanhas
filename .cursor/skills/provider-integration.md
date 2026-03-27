# Skill: provider-integration
**Objetivo:** Criar ou atualizar integrações com fornecedores externos (Ótima, GOSAC, Noah, Robbu, CDA, Salesforce, Evolution) dentro do microserviço NestJS.

## Instruções para a IA:
1. **Design Pattern:** Utilize o padrão Strategy/Adapter. Cada provider deve ser uma classe ou módulo isolado no NestJS que implementa uma interface comum de disparo.
2. **Mapeamento de Variáveis:** Construa lógicas rígidas para substituir variáveis do template (ex: mapear `-1-`, `-2-` da Ótima ou parâmetros do GOSAC) com os dados da base repassados pelo WP no JSON da fila.
3. **Credenciais Dinâmicas:** Tokens de autenticação com provedores NUNCA são estáticos no Node. Eles devem ser extraídos do payload recebido do WordPress (configurado via API Manager no admin do WP).
4. **Throttling e Limites:** Respeite o rate limit específico da documentação da API do fornecedor na formatação do payload, processando chamadas em lote (bulk) sempre que a API de destino suportar.