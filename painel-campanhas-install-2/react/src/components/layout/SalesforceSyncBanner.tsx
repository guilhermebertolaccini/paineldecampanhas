import { useQuery } from "@tanstack/react-query";
import { Cloud, AlertTriangle } from "lucide-react";
import { getSalesforceSyncStatus } from "@/lib/api";
import { cn } from "@/lib/utils";

function parseWpMysqlDate(s: string): Date | null {
  if (!s || typeof s !== "string") return null;
  const iso = s.trim().replace(" ", "T");
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function SalesforceSyncBanner() {
  const { data, isError } = useQuery({
    queryKey: ["salesforce-sync-status"],
    queryFn: getSalesforceSyncStatus,
    staleTime: 60_000,
    refetchInterval: 180_000,
  });

  const lastMysql = (data as { lastRunMysql?: string } | undefined)?.lastRunMysql;
  const parsed = lastMysql ? parseWpMysqlDate(lastMysql) : null;
  const formatted = parsed
    ? parsed.toLocaleString("pt-BR", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "Nunca registrada";

  const hoursSince = parsed ? (Date.now() - parsed.getTime()) / 36e5 : null;
  const level: "ok" | "warn" | "bad" | "neutral" =
    isError ? "warn" : hoursSince == null ? "neutral" : hoursSince > 72 ? "bad" : hoursSince > 26 ? "warn" : "ok";

  return (
    <div
      className={cn(
        "mb-4 flex flex-wrap items-start gap-2 rounded-lg border px-3 py-2.5 text-sm",
        level === "bad" && "border-destructive/60 bg-destructive/10 text-destructive",
        level === "warn" && "border-amber-500/50 bg-amber-500/10 text-amber-950 dark:text-amber-100",
        level === "ok" && "border-border bg-muted/40",
        level === "neutral" && "border-dashed border-border bg-muted/20 text-muted-foreground"
      )}
      role="status"
    >
      {level === "bad" ? (
        <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
      ) : (
        <Cloud className="h-4 w-4 shrink-0 mt-0.5" />
      )}
      <div className="min-w-0 flex-1">
        <p>
          <span className="font-semibold">Última sincronização Salesforce: </span>
          {formatted}
        </p>
        {level === "bad" && (
          <p className="text-xs mt-1 opacity-90">
            Sem sucesso registrado há mais de 3 dias. Confira WP-Cron, o log em wp-content/sf_cron.log e o script
            import_salesforce.php.
          </p>
        )}
        {level === "warn" && !isError && (
          <p className="text-xs mt-1 opacity-90">
            Job diário esperado às 09:00 (fuso do WordPress). Se o horário acima estiver desatualizado, verifique o
            servidor.
          </p>
        )}
        {isError && (
          <p className="text-xs mt-1">Não foi possível carregar o status. Recarregue a página.</p>
        )}
      </div>
    </div>
  );
}
