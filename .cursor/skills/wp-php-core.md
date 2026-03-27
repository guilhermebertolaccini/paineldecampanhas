# Skill: wp-php-core
**Objetivo:** Desenvolver lógicas de negócio, handlers AJAX e endpoints REST no plugin do WordPress (`painel-campanhas-install-2`).

## Instruções para a IA:
1. **Padrão de Arquivo:** O código deve ser compatível com PHP 7.4+.
2. **Handlers AJAX:** Ao criar uma nova ação para o React consumir, registre os hooks `wp_ajax_` e `wp_ajax_nopriv_` adequadamente. Retorne respostas padronizadas usando `wp_send_json_success()` ou `wp_send_json_error()`.
3. **Acesso a Dados:** Ao lidar com carteiras, bases ou aprovações de campanhas, interaja com as tabelas customizadas (`pc_*`, `envios_pendentes`) utilizando a classe global `$wpdb`. Sempre prepare as queries (`$wpdb->prepare()`).
4. **Endpoints NestJS:** Se o NestJS precisar de dados, crie ou modifique endpoints REST do WP (`/wp-json/api-manager/v1/...`) garantindo a validação da `ACM_MASTER_API_KEY` no cabeçalho da requisição.