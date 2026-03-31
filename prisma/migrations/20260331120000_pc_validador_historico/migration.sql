-- CreateTable
CREATE TABLE "pc_validador_historico" (
    "id" TEXT NOT NULL,
    "wp_user_id" INTEGER NOT NULL,
    "nome_arquivo" VARCHAR(512) NOT NULL,
    "path_original" VARCHAR(1024) NOT NULL,
    "path_validado" VARCHAR(1024) NOT NULL,
    "total_linhas" INTEGER NOT NULL,
    "linhas_validas" INTEGER NOT NULL,
    "linhas_invalidas" INTEGER NOT NULL,
    "data_criacao" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "pc_validador_historico_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "pc_validador_historico_wp_user_id_data_criacao_idx" ON "pc_validador_historico"("wp_user_id", "data_criacao");

-- CreateIndex
CREATE INDEX "pc_validador_historico_data_criacao_idx" ON "pc_validador_historico"("data_criacao");
