-- CreateTable
CREATE TABLE "otima_line_health" (
    "id" TEXT NOT NULL,
    "name" VARCHAR(512) NOT NULL,
    "number" VARCHAR(32) NOT NULL,
    "provider" VARCHAR(128) NOT NULL,
    "status" VARCHAR(32) NOT NULL,
    "messaging_limit" VARCHAR(32) NOT NULL,
    "quality" VARCHAR(32) NOT NULL,
    "source_file" VARCHAR(512),
    "raw" JSONB,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,

    CONSTRAINT "otima_line_health_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "otima_line_health_number_key" ON "otima_line_health"("number");

-- CreateIndex
CREATE INDEX "otima_line_health_provider_idx" ON "otima_line_health"("provider");

-- CreateIndex
CREATE INDEX "otima_line_health_status_idx" ON "otima_line_health"("status");

-- CreateIndex
CREATE INDEX "otima_line_health_updated_at_idx" ON "otima_line_health"("updated_at");
