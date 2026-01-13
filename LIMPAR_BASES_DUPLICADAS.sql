-- ============================================
-- SCRIPT PARA LIMPAR BASES DUPLICADAS
-- ============================================
-- Data: 29/12/2024
-- Autor: Claude Code
--
-- IMPORTANTE: Faça BACKUP do banco antes de executar!
--
-- Como usar:
-- 1. Acesse phpMyAdmin ou MySQL Workbench
-- 2. Selecione seu banco de dados WordPress
-- 3. Execute os comandos abaixo UM POR VEZ
-- 4. Verifique os resultados após cada comando
-- ============================================

-- ============================================
-- PASSO 1: VERIFICAR SE HÁ DUPLICATAS
-- ============================================
-- Execute este SELECT para ver se há bases duplicadas
SELECT
    carteira_id,
    nome_base,
    COUNT(*) as total_duplicatas
FROM wp_pc_carteiras_bases
GROUP BY carteira_id, nome_base
HAVING COUNT(*) > 1
ORDER BY total_duplicatas DESC;

-- Se o resultado mostrar registros, significa que há duplicatas
-- Continue com os próximos passos


-- ============================================
-- PASSO 2: VER DETALHES DAS DUPLICATAS
-- ============================================
-- Este SELECT mostra TODOS os registros duplicados (incluindo IDs)
SELECT
    cb1.id,
    cb1.carteira_id,
    cb1.nome_base,
    cb1.criado_em
FROM wp_pc_carteiras_bases cb1
WHERE EXISTS (
    SELECT 1
    FROM wp_pc_carteiras_bases cb2
    WHERE cb1.carteira_id = cb2.carteira_id
    AND cb1.nome_base = cb2.nome_base
    AND cb1.id != cb2.id
)
ORDER BY cb1.carteira_id, cb1.nome_base, cb1.id;

-- Anote os IDs para ter certeza de quais serão removidos


-- ============================================
-- PASSO 3: REMOVER DUPLICATAS (MANTÉM O MAIS ANTIGO)
-- ============================================
-- ⚠️ ATENÇÃO: Este comando DELETE é IRREVERSÍVEL!
-- Ele mantém apenas o registro com o MENOR ID (mais antigo)
-- e remove todos os demais duplicados

DELETE cb1
FROM wp_pc_carteiras_bases cb1
INNER JOIN wp_pc_carteiras_bases cb2
WHERE cb1.id > cb2.id
AND cb1.carteira_id = cb2.carteira_id
AND cb1.nome_base = cb2.nome_base;

-- Após executar, você verá algo como: "Query OK, 5 rows affected"
-- O número indica quantas duplicatas foram removidas


-- ============================================
-- PASSO 4: VERIFICAR SE FOI RESOLVIDO
-- ============================================
-- Execute novamente a query do PASSO 1 para confirmar
SELECT
    carteira_id,
    nome_base,
    COUNT(*) as total_duplicatas
FROM wp_pc_carteiras_bases
GROUP BY carteira_id, nome_base
HAVING COUNT(*) > 1
ORDER BY total_duplicatas DESC;

-- Se não retornar nenhum resultado, está RESOLVIDO! ✅


-- ============================================
-- PASSO 5: ADICIONAR ÍNDICE ÚNICO (PREVENIR FUTURAS DUPLICATAS)
-- ============================================
-- Este comando adiciona um índice único que IMPEDE a criação de duplicatas no futuro

-- Primeiro, verifica se o índice já existe
SHOW INDEXES FROM wp_pc_carteiras_bases;

-- Se não existir um índice único para (carteira_id, nome_base), crie:
ALTER TABLE wp_pc_carteiras_bases
ADD UNIQUE INDEX idx_carteira_base_unique (carteira_id, nome_base);

-- Se der erro "Duplicate entry", significa que ainda há duplicatas
-- Volte ao PASSO 3 e execute novamente o DELETE


-- ============================================
-- PASSO 6 (OPCIONAL): VERIFICAR TODAS AS BASES
-- ============================================
-- Lista todas as bases vinculadas a cada carteira (após limpeza)
SELECT
    c.id as carteira_id,
    c.nome as carteira_nome,
    cb.nome_base,
    cb.criado_em
FROM wp_pc_carteiras c
LEFT JOIN wp_pc_carteiras_bases cb ON c.id = cb.carteira_id
ORDER BY c.id, cb.nome_base;


-- ============================================
-- ROLLBACK (SE ALGO DER ERRADO)
-- ============================================
-- Se você fez backup e quer restaurar:
--
-- 1. Vá no phpMyAdmin
-- 2. Selecione a tabela wp_pc_carteiras_bases
-- 3. Clique em "Operações" > "Esvaziar a tabela" (TRUNCATE)
-- 4. Vá em "Importar" e selecione seu arquivo de backup .sql
-- 5. Execute a importação
--
-- OU use o comando (substitua o caminho do arquivo):
-- SOURCE /caminho/para/seu/backup.sql;


-- ============================================
-- FIM DO SCRIPT
-- ============================================
-- Após executar todos os passos:
-- 1. Faça logout do WordPress
-- 2. Faça login novamente
-- 3. Acesse "Nova Campanha"
-- 4. Selecione uma carteira
-- 5. Verifique se aparece apenas UMA de cada base
-- ============================================
