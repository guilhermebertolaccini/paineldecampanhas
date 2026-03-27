# Skill: react-wp-spa
**Objetivo:** Criar ou modificar telas, componentes e lógicas da SPA React embutida no WordPress.

## Instruções para a IA:
1. **Stack Visual:** Crie componentes funcionais utilizando TypeScript. Para UI, baseie-se estritamente nos componentes do `shadcn/ui` e `Radix` presentes no projeto.
2. **Consumo de Dados (React Query):** Para buscar ou enviar dados (ex: listar campanhas, aprovar), não use fetch puro nos componentes. Crie hooks customizados com `@tanstack/react-query` que chamam as funções dentro de `src/pages/painel/lib/api.ts`.
3. **Endpoint Alvo:** Lembre-se que o backend imediato do React é o `admin-ajax.php` do WordPress, utilizando as ações (`action: 'cm_alguma_coisa'`).
4. **Rotas:** Se for criar uma nova página, registre-a utilizando o `HashRouter` existente.