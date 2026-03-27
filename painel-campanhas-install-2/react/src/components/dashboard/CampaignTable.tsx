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
import { cancelCampanha } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Progress } from "@/components/ui/progress";

export interface Campaign {
  id: string;
  name: string;
  status: "pending" | "approved" | "sent" | "denied" | "scheduled" | "cancelled" | string;
  /** Status bruto do MySQL (ex.: processando) — útil para UI de progresso. */
  statusRaw?: string;
  provider: string;
  /** Valor exato da coluna fornecedor no MySQL (para cancelamento). */
  fornecedor?: string;
  nomeCarteira?: string;
  idCarteira?: string;
  quantity: number;
  createdAt: string;
  user: string;
  motivoCancelamento?: string;
  canceladoPor?: string;
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

function CampaignProgressRow({ c }: { c: Campaign }) {
  const total = c.totalMessages ?? c.quantity ?? 0;
  const processed = c.totalProcessed ?? 0;
  const sent = c.messagesSent ?? 0;
  const err = c.messagesError ?? 0;
  const pct = typeof c.progressPercent === "number" ? Math.min(100, Math.max(0, c.progressPercent)) : 0;
  const isRunning =
    c.status === "scheduled" ||
    String(c.statusRaw ?? "")
      .toLowerCase()
      .includes("processando");
  if (total <= 0) {
    return <span className="text-muted-foreground text-xs">—</span>;
  }
  const hint =
    isRunning && pct < 100
      ? `${processed} de ${total} linhas com status final (enviado ou erro). As demais ainda estão na fila ou em processamento.`
      : err > 0
        ? `${sent} enviadas, ${err} com erro, total ${total} contatos.`
        : undefined;
  return (
    <div className="min-w-[200px] max-w-[260px] space-y-1">
      <Progress value={pct} className="h-2" />
      <div className="flex flex-wrap items-center gap-x-1 text-[11px] text-muted-foreground leading-tight">
        <span>
          Processado: {pct}% ({processed} de {total} mensagens)
        </span>
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
  return `Motivo: ${c.motivoCancelamento.trim()} — Cancelado por: ${por}`;
}

export function CampaignTable({ campaigns, showActions = true }: CampaignTableProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [cancelTarget, setCancelTarget] = useState<Campaign | null>(null);
  const [cancelMotivo, setCancelMotivo] = useState("");

  const cancelMutation = useMutation({
    mutationFn: () =>
      cancelCampanha({
        agendamento_id: cancelTarget!.name,
        fornecedor: cancelTarget!.fornecedor ?? cancelTarget!.provider,
        motivo: cancelMotivo.trim(),
      }),
    onSuccess: () => {
      toast({ title: "Campanha cancelada", description: "O status foi atualizado." });
      setCancelTarget(null);
      setCancelMotivo("");
      queryClient.invalidateQueries({ queryKey: ["campanhas"] });
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

  const canCancel = (c: Campaign) =>
    (c.status === "pending" || c.status === "scheduled") && !!(c.fornecedor ?? c.provider);

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
              <TableHead className="font-semibold">Usuário</TableHead>
              <TableHead className="font-semibold min-w-[120px]">Observações</TableHead>
              {showActions && <TableHead className="font-semibold w-[100px]">Ações</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {campaigns.map((campaign, index) => {
              const feedback = motivoFeedback(campaign);
              return (
                <TableRow
                  key={campaign.id}
                  className="animate-fade-in"
                  style={{ animationDelay: `${index * 50}ms` }}
                >
                  <TableCell className="font-medium">{campaign.name}</TableCell>
                  <TableCell className="text-muted-foreground text-sm max-w-[220px]">
                    {campaign.nomeCarteira ? (
                      <span className="line-clamp-2 font-medium text-foreground" title={campaign.nomeCarteira}>
                        {campaign.nomeCarteira}
                      </span>
                    ) : campaign.idCarteira ? (
                      <span className="font-mono text-xs">{campaign.idCarteira}</span>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <StatusBadge status={campaign.status} />
                      {feedback && (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              className="text-muted-foreground hover:text-foreground"
                              aria-label="Ver motivo"
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
                  </TableCell>
                  <TableCell className="text-muted-foreground">{campaign.provider}</TableCell>
                  <TableCell className="text-right font-medium">
                    {campaign.quantity.toLocaleString("pt-BR")}
                  </TableCell>
                  <TableCell>
                    <CampaignProgressRow c={campaign} />
                  </TableCell>
                  <TableCell className="text-muted-foreground">{campaign.createdAt}</TableCell>
                  <TableCell className="text-muted-foreground">{campaign.user}</TableCell>
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
                          {canCancel(campaign) && (
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
