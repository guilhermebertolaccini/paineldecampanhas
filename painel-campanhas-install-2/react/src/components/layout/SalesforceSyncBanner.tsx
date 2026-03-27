import { useQuery } from "@tanstack/react-query";
import { Cloud, AlertTriangle, WifiOff } from "lucide-react";
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

  const payload = data as
    | {
        lastRunMysql?: string;
        nextRunLabel?: string;
        staleAfter24h?: boolean;
      }
    | undefined;

  const lastMysql = payload?.lastRunMysql;
  const parsed = lastMysql ? parseWpMysqlDate(lastMysql) : null;

  const formattedLast =
    parsed != null
      ? `${parsed.toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit" })} às ${parsed.toLocaleTimeString("pt-BR", {
          hour: "2-digit",
          minute: "2-digit",
        })}`
      : "Nunca registrada";

  const hoursSince = parsed ? (Date.now() - parsed.getTime()) / 36e5 : null;

  const stale24 =
    typeof payload?.staleAfter24h === "boolean"
      ? payload.staleAfter24h
      : hoursSince == null || hoursSince > 24;

  const level: "ok" | "bad" | "error" = isError ? "error" : stale24 ? "bad" : "ok";

  const nextRunLabel = typeof payload?.nextRunLabel === "string" ? payload.nextRunLabel.trim() : "";

  return (
    <div
      className={cn(
        "mb-4 flex flex-wrap items-start gap-2 rounded-lg border px-3 py-2.5 text-sm",
        level === "bad" && "border-destructive/60 bg-destructive/10 text-destructive",
        level === "error" && "border-amber-500/50 bg-amber-500/10 text-amber-950 dark:text-amber-100",
        level === "ok" && "border-border bg-muted/40"
      )}
      role="status"
    >
      {level === "bad" ? (
        <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
      ) : level === "error" ? (
        <WifiOff className="h-4 w-4 shrink-0 mt-0.5" />
      ) : (
        <Cloud className="h-4 w-4 shrink-0 mt-0.5" />
      )}
      <div className="min-w-0 flex-1">
        <p>
          <span className="font-semibold">Última sync Salesforce: </span>
          <span className={cn(level === "bad" && "font-medium")}>{formattedLast}</span>
        </p>
        {nextRunLabel ? (
          <p className="text-xs mt-1 opacity-80">
            Próxima execução agendada (WP-Cron): {nextRunLabel} — job diário às 09:00 (fuso do site).
          </p>
        ) : null}
        {level === "bad" && !isError && (
          <p className="text-xs mt-1 opacity-90">
            Sem sincronização registrada nas últimas 24 horas. O job diário pode estar travado ou o WP-Cron inativo —
            confira <code className="text-[11px]">wp-content/sf_cron.log</code> e o agendamento em Ferramentas → Saúde do
            site (ou o disparo real do cron no servidor).
          </p>
        )}
        {isError && (
          <p className="text-xs mt-1">Não foi possível carregar o status. Recarregue a página.</p>
        )}
      </div>
    </div>
  );
}
