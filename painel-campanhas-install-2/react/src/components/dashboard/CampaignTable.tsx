import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Eye, MoreHorizontal, Ban, Loader2, Info } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { cancelCampanha } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Progress } from "@/components/ui/progress";

export interface Campaign {
  id: string;
  /** ID técnico (`agendamento_id` no MySQL) — obrigatório para cancelar; se ausente, usa-se `name`. */
  agendamentoId?: string;
  name: string;
  status: "pending" | "approved" | "sent" | "denied" | "scheduled" | "cancelled" | string;
  /** Status bruto do MySQL (ex.: processando) — útil para UI de progresso. */
  statusRaw?: string;
  provider: string;
  /** Valor exato da coluna fornecedor no MySQL (para cancelamento). */
  fornecedor?: string;
  /** Nome denormalizado vindo de `envios_pendentes.nome_carteira` (API: carteira_nome / nomeCarteira) */
  nomeCarteira?: string;
  carteira_nome?: string;
  /** Código cliente id_carteira — não exibir como rótulo principal */
  idCarteira?: string;
  quantity: number;
  createdAt: string;
  user: string;
  motivoCancelamento?: string;
  canceladoPor?: string;
  /** Métricas do WP (snake_case) — preferidas quando presentes */
  total_messages?: number;
  processed_messages?: number;
  error_messages?: number;
  progress_percent?: number;
  /** Aliases camelCase (retrocompat) */
  totalMessages?: number;
  totalProcessed?: number;
  messagesSent?: number;
  messagesError?: number;
  progressPercent?: number;
}

interface CampaignTableProps {
  campaigns: Campaign[];
  showActions?: boolean;
}

const statusConfig: Record<
  string,
  { label: string; variant: "warning" | "info" | "success" | "destructive" | "secondary" }
> = {
  pending: { label: "Pendente", variant: "warning" },
  approved: { label: "Aprovado", variant: "info" },
  sent: { label: "Enviado", variant: "success" },
  denied: { label: "Negado", variant: "destructive" },
  scheduled: { label: "Agendado", variant: "secondary" },
  cancelled: { label: "Cancelada", variant: "destructive" },
};

function campaignProgressMetrics(c: Campaign) {
  const total = c.total_messages ?? c.totalMessages ?? c.quantity ?? 0;
  const processed = c.processed_messages ?? c.totalProcessed ?? 0;
  const err = c.error_messages ?? c.messagesError ?? 0;
  const sent = c.messagesSent ?? 0;
  const pctRaw = c.progress_percent ?? c.progressPercent ?? 0;
  const pct = typeof pctRaw === "number" && !Number.isNaN(pctRaw) ? Math.min(100, Math.max(0, pctRaw)) : 0;
  return { total, processed, err, sent, pct };
}

/** Barra visível durante processamento, após envios parciais/total ou campanha concluída (enviado). */
function shouldShowCampaignProgress(c: Campaign): boolean {
  const { total, processed, pct } = campaignProgressMetrics(c);
  if (total <= 0) return false;
  const raw = String(c.statusRaw ?? "").toLowerCase();
  const ui = String(c.status).toLowerCase();

  if (raw.includes("processando")) return true;
  if (ui === "sent") return true;
  if (processed > 0 || pct > 0) return true;
  return false;
}

function CampaignProgressRow({ c }: { c: Campaign }) {
  const { total, processed, err, sent, pct } = campaignProgressMetrics(c);
  const isRunning =
    c.status === "scheduled" ||
    String(c.statusRaw ?? "")
      .toLowerCase()
      .includes("processando");

  if (!shouldShowCampaignProgress(c)) {
    return <span className="text-muted-foreground text-xs">—</span>;
  }

  const pctLabel = Number.isInteger(pct) ? String(pct) : pct.toFixed(1);
  const hint =
    isRunning && pct < 100
      ? `${processed} de ${total} linhas já saíram da fila (enviadas, com erro ou finalizadas). Demais ainda em pendente/agendamento/processamento.`
      : err > 0
        ? `${sent} enviadas, ${err} falha(s), ${total} contatos no total.`
        : undefined;

  return (
    <div className="min-w-[200px] max-w-[280px] space-y-1">
      <Progress value={Math.round(pct)} className="h-2" />
      <div className="flex flex-wrap items-center gap-x-1 gap-y-1 text-[11px] text-muted-foreground leading-tight">
        <span>
          Processado: {pctLabel}% ({processed.toLocaleString("pt-BR")} / {total.toLocaleString("pt-BR")} msgs)
        </span>
        {err > 0 && (
          <Badge variant="destructive" className="text-[10px] px-1.5 py-0 h-5 font-normal">
            {err.toLocaleString("pt-BR")} falha{err !== 1 ? "s" : ""}
          </Badge>
        )}
        {hint && (
          <Tooltip>
            <TooltipTrigger asChild>
              <button type="button" className="text-foreground/70 hover:text-foreground underline-offset-2 hover:underline">
                Detalhe
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs text-xs">
              {hint}
            </TooltipContent>
          </Tooltip>
        )}
      </div>
    </div>
  );
}

function motivoFeedback(c: Campaign): string | null {
  const s = String(c.status).toLowerCase();
  if ((s !== "denied" && s !== "cancelled") || !c.motivoCancelamento?.trim()) {
    return null;
  }
  const por = c.canceladoPor?.trim() ? c.canceladoPor : "—";
  return `Motivo: ${c.motivoCancelamento.trim()} — Por: ${por}`;
}

function userCanManageOptions(): boolean {
  if (typeof window === "undefined") return false;
  return Boolean((window as unknown as { pcAjax?: { canManageOptions?: boolean } }).pcAjax?.canManageOptions);
}

/** Alinhado ao PHP: assinante não cancela em `processando`; admin pode. */
function canCancelCampaign(c: Campaign): boolean {
  const fornecedor = c.fornecedor ?? c.provider;
  if (!fornecedor) return false;
  const raw = String(c.statusRaw ?? "").toLowerCase().trim();
  const admin = userCanManageOptions();
  if (raw === "processando") {
    return admin;
  }
  return ["pendente_aprovacao", "pendente", "agendado_mkc"].includes(raw);
}

function carteiraDisplayLabel(c: Campaign): string {
  const raw = (c.carteira_nome ?? c.nomeCarteira ?? "").trim();
  return raw;
}

export function CampaignTable({ campaigns, showActions = true }: CampaignTableProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const showCreatorColumn = userCanManageOptions();
  const [cancelTarget, setCancelTarget] = useState<Campaign | null>(null);
  const [cancelMotivo, setCancelMotivo] = useState("");

  const cancelMutation = useMutation({
    mutationFn: () =>
      cancelCampanha({
        agendamento_id: cancelTarget!.agendamentoId ?? cancelTarget!.name,
        fornecedor: cancelTarget!.fornecedor ?? cancelTarget!.provider,
        motivo: cancelMotivo.trim(),
      }),
    onSuccess: () => {
      toast({ title: "Campanha cancelada", description: "O status foi atualizado." });
      setCancelTarget(null);
      setCancelMotivo("");
      queryClient.invalidateQueries({ queryKey: ["campanhas"] });
      queryClient.invalidateQueries({ queryKey: ["dashboard-stats"] });
    },
    onError: (e: Error) => {
      toast({
        variant: "destructive",
        title: "Não foi possível cancelar",
        description: e.message || "Tente novamente.",
      });
    },
  });

  const openCancel = (c: Campaign) => {
    setCancelTarget(c);
    setCancelMotivo("");
  };

  return (
    <>
      <div className="rounded-xl border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/50 hover:bg-muted/50">
            <TableHead className="font-semibold">Campanha</TableHead>
            <TableHead className="font-semibold">Carteira</TableHead>
            <TableHead className="font-semibold">Status</TableHead>
            <TableHead className="font-semibold">Fornecedor</TableHead>
            <TableHead className="font-semibold text-right">Quantidade</TableHead>
            <TableHead className="font-semibold min-w-[220px]">Progresso</TableHead>
            <TableHead className="font-semibold">Criado em</TableHead>
            {showCreatorColumn ? <TableHead className="font-semibold">Criador</TableHead> : null}
            <TableHead className="font-semibold min-w-[120px]">Observações</TableHead>
              {showActions && <TableHead className="font-semibold w-[100px]">Ações</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {campaigns.map((campaign, index) => {
              const feedback = motivoFeedback(campaign);
              const carteiraLabel = carteiraDisplayLabel(campaign);
              return (
                <TableRow
                  key={campaign.id}
                  className="animate-fade-in"
                  style={{ animationDelay: `${index * 50}ms` }}
                >
                  <TableCell className="font-medium">{campaign.name}</TableCell>
                  <TableCell className="text-muted-foreground text-sm max-w-[220px]">
                    {carteiraLabel ? (
                      <span className="line-clamp-2 font-medium text-foreground" title={carteiraLabel}>
                        {carteiraLabel}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col gap-2 min-w-[140px]">
                      <div className="flex items-center gap-2">
                        <StatusBadge status={campaign.status} />
                        {feedback && (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground shrink-0"
                                aria-label="Ver motivo e responsável"
                              >
                                <Info className="h-4 w-4" />
                              </button>
                            </TooltipTrigger>
                            <TooltipContent side="top" className="max-w-sm">
                              <p className="text-xs">{feedback}</p>
                            </TooltipContent>
                          </Tooltip>
                        )}
                      </div>
                      {feedback && (
                        <Alert variant="destructive" className="py-2 px-3 border-destructive/40">
                          <AlertDescription className="text-xs leading-snug m-0">{feedback}</AlertDescription>
                        </Alert>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{campaign.provider}</TableCell>
                  <TableCell className="text-right font-medium">
                    {campaign.quantity.toLocaleString("pt-BR")}
                  </TableCell>
                  <TableCell>
                    <CampaignProgressRow c={campaign} />
                  </TableCell>
                  <TableCell className="text-muted-foreground">{campaign.createdAt}</TableCell>
                  {showCreatorColumn ? (
                    <TableCell className="text-muted-foreground max-w-[180px]">
                      <span className="line-clamp-2" title={campaign.user}>
                        {campaign.user || "—"}
                      </span>
                    </TableCell>
                  ) : null}
                  <TableCell className="text-muted-foreground text-xs max-w-[220px]">
                    {feedback ? (
                      <span className="line-clamp-2" title={feedback}>
                        {feedback}
                      </span>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                  {showActions && (
                    <TableCell>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="h-8 w-8">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem disabled>
                            <Eye className="mr-2 h-4 w-4" />
                            Ver detalhes
                          </DropdownMenuItem>
                          {canCancelCampaign(campaign) && (
                            <DropdownMenuItem onClick={() => openCancel(campaign)}>
                              <Ban className="mr-2 h-4 w-4" />
                              Cancelar campanha
                            </DropdownMenuItem>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  )}
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      <Dialog open={!!cancelTarget} onOpenChange={(o) => !o && setCancelTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancelar campanha</DialogTitle>
            <DialogDescription>
              Informe o motivo do cancelamento (obrigatório). Esta ação atualiza o status para
              &quot;Cancelada&quot;.
            </DialogDescription>
          </DialogHeader>
          {cancelTarget && (
            <div className="space-y-2 py-2">
              <p className="text-sm text-muted-foreground">
                Campanha: <span className="font-medium text-foreground">{cancelTarget.name}</span> —{" "}
                {cancelTarget.provider}
              </p>
              <div className="space-y-2">
                <Label htmlFor="motivo-cancel">Motivo do cancelamento</Label>
                <Textarea
                  id="motivo-cancel"
                  value={cancelMotivo}
                  onChange={(e) => setCancelMotivo(e.target.value)}
                  placeholder="Descreva o motivo..."
                  rows={4}
                  className="resize-none"
                />
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelTarget(null)} type="button">
              Voltar
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={cancelMotivo.trim().length < 3 || cancelMutation.isPending}
              onClick={() => cancelMutation.mutate()}
            >
              {cancelMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Confirmar cancelamento
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function StatusBadge({ status }: { status: Campaign["status"] }) {
  const key = String(status).toLowerCase();
  const config = statusConfig[key] || { label: String(status), variant: "secondary" as const };
  return (
    <Badge
      variant={config.variant}
      className={cn(
        "font-medium",
        config.variant === "warning" &&
          "bg-warning/10 text-warning border-warning/20 hover:bg-warning/20",
        config.variant === "success" &&
          "bg-success/10 text-success border-success/20 hover:bg-success/20",
        config.variant === "info" && "bg-info/10 text-info border-info/20 hover:bg-info/20",
        config.variant === "destructive" &&
          "bg-destructive/10 text-destructive border-destructive/20 hover:bg-destructive/20"
      )}
    >
      {config.label}
    </Badge>
  );
}
