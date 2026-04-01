import { Activity } from "lucide-react";
import { useQuery } from "@tanstack/react-query";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { getLineHealth, type LineHealthRow } from "@/lib/api";
import { Skeleton } from "@/components/ui/skeleton";

function statusCardClass(status: string): string {
  const s = (status || "").toUpperCase();
  if (s.startsWith("OK") || s.includes("SAUD")) {
    return "border-emerald-500/40 bg-emerald-500/5 text-emerald-900 dark:text-emerald-100";
  }
  if (s.startsWith("ERRO") || s.includes("FALHA") || s.startsWith("HTTP_5")) {
    return "border-red-500/40 bg-red-500/5 text-red-900 dark:text-red-100";
  }
  if (s.startsWith("HTTP_") || s.includes("DEGRAD")) {
    return "border-amber-500/40 bg-amber-500/5 text-amber-950 dark:text-amber-100";
  }
  if (s.includes("SEM_") || s.includes("NAO_") || s.includes("PENDENTE")) {
    return "border-muted-foreground/30 bg-muted/40 text-muted-foreground";
  }
  return "border-primary/30 bg-primary/5";
}

function formatCheckDate(raw: string): string {
  if (!raw) return "—";
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return raw;
  return d.toLocaleString("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  });
}

export function OperationsHealth() {
  const { data, isLoading, error } = useQuery({
    queryKey: ["line-health"],
    queryFn: getLineHealth,
    staleTime: 60_000,
  });

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <Activity className="h-5 w-5" />
            Saúde das Operações
          </CardTitle>
          <CardDescription>Telemetria das linhas de disparo (SQL Server)</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </CardContent>
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-lg font-semibold">Saúde das Operações</CardTitle>
          <CardDescription className="text-destructive">
            {error instanceof Error ? error.message : "Erro ao carregar"}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  if (!data?.configured) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <Activity className="h-5 w-5" />
            Saúde das Operações
          </CardTitle>
          <CardDescription>
            MSSQL não configurado ou extensão <code className="text-xs">pdo_sqlsrv</code> indisponível. Defina{" "}
            <code className="text-xs">PC_MSSQL_*</code> no wp-config.
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const rows = (data.rows || []) as LineHealthRow[];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg font-semibold">
          <Activity className="h-5 w-5" />
          Saúde das Operações
        </CardTitle>
        <CardDescription>Última checagem diária por linha (TB_SAUDE_LINHAS)</CardDescription>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <p className="text-sm text-muted-foreground py-4 text-center">
            Nenhum registro ainda. O job Nest às 06h preenche após configurar{" "}
            <code className="text-xs">LINE_HEALTH_TARGETS</code>.
          </p>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {rows.map((row, i) => (
              <div
                key={row.id != null ? String(row.id) : `${row.id_linha}-${row.provedor ?? ""}-${i}`}
                className={`rounded-lg border p-4 transition-shadow hover:shadow-sm ${statusCardClass(row.status_qualidade)}`}
              >
                <p className="font-medium leading-tight line-clamp-2">{row.nome_linha || row.id_linha}</p>
                {row.provedor ? (
                  <p className="text-xs opacity-80 mt-1 font-mono">{row.provedor}</p>
                ) : null}
                <p className="text-sm font-semibold mt-2">{row.status_qualidade}</p>
                {row.detalhes_retorno ? (
                  <p className="text-xs opacity-80 mt-2 line-clamp-3 break-words">{row.detalhes_retorno}</p>
                ) : null}
                {row.data_checagem ? (
                  <p className="text-xs opacity-70 mt-1">{formatCheckDate(row.data_checagem)}</p>
                ) : row.id != null ? (
                  <p className="text-xs opacity-60 mt-1 tabular-nums">#{row.id}</p>
                ) : null}
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
