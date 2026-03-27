import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Play, Trash2, Clock, CheckCircle, Pause, Loader2, Info,
  Users, Filter, RefreshCw, Hash, Layers, Pencil,
} from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Separator } from "@/components/ui/separator";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { useToast } from "@/hooks/use-toast";
import {
  getRecurring,
  deleteRecurring,
  toggleRecurring,
  executeRecurringNow,
  getRecurringEstimates,
  saveRecurring,
  getIscas,
} from "@/lib/api";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";

interface ParsedFilter {
  column?: string;
  operator?: string;
  value?: any;
  field?: string;
}

interface RecurringCampaign {
  id: string;
  nome_campanha: string;
  tabela_origem: string;
  filtros_json?: string;
  providers_config?: string;
  /** Decodificado pelo PHP em `handle_get_recurring` */
  providers_config_parsed?: Record<string, unknown>;
  template_id: string;
  template_code?: string;
  template_source?: string;
  broker_code?: string;
  customer_code?: string;
  carteira?: string;
  record_limit?: string | number;
  ativo: number | boolean | string;
  ultima_execucao?: string;
  parsed_filters?: ParsedFilter[];
  estimated_count?: number;
  throttling_type?: string;
  throttling_config?: string;
  include_baits?: number | string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const isActive = (value: unknown): boolean =>
  value === true || value === 1 || value === '1';

const OPERATOR_LABELS: Record<string, string> = {
  equals: "=",
  not_equals: "≠",
  greater: ">",
  greater_equals: "≥",
  less: "<",
  less_equals: "≤",
  contains: "contém",
  not_contains: "não contém",
  starts_with: "começa com",
  ends_with: "termina com",
  in: "em",
  not_in: "não está em",
  exclude_recent: "excluir recentes",
  is_null: "é nulo",
  is_not_null: "não é nulo",
};

const formatFilterValue = (value: any): string => {
  if (value === null || value === undefined) return "—";
  if (Array.isArray(value)) return value.join(", ");
  return String(value);
};

const CONFIG_META_KEYS = new Set([
  "mode",
  "percentages",
  "providers",
  "exclude_recent_phones",
  "exclude_recent_hours",
  "bait_ids",
]);

/** Lê objeto já parseado pelo backend ou faz parse da string `providers_config`. */
function getProvidersConfigParsed(campaign: RecurringCampaign | Record<string, unknown>): Record<string, any> {
  const p = (campaign as RecurringCampaign).providers_config_parsed;
  if (p && typeof p === "object" && p !== null && !Array.isArray(p)) {
    return { ...p } as Record<string, any>;
  }
  const raw = (campaign as RecurringCampaign).providers_config;
  if (!raw || typeof raw !== "string") return {};
  try {
    const parsed = JSON.parse(raw);
    return typeof parsed === "object" && parsed !== null && !Array.isArray(parsed) ? { ...parsed } : {};
  } catch {
    return {};
  }
}

function normalizeBaitIds(raw: unknown): number[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x) => parseInt(String(x), 10))
    .filter((n) => !Number.isNaN(n) && n > 0);
}

const parseProviderNames = (config?: string): string => {
  if (!config) return "—";
  try {
    const parsed = JSON.parse(config);
    if (Array.isArray(parsed?.providers)) {
      return (
        parsed.providers.map((p: any) => p.id || p.name || "?").join(", ") ||
        "—"
      );
    }
    if (typeof parsed === "object" && parsed !== null) {
      const mode = parsed.mode ? `${parsed.mode}` : "";
      const ids = Object.keys(parsed).filter((k) => !CONFIG_META_KEYS.has(k));
      return [mode, ...ids].filter(Boolean).join(" · ") || "—";
    }
    return String(parsed) || "—";
  } catch {
    return String(config);
  }
};

const getFilters = (campaign: any): ParsedFilter[] => {
  if (Array.isArray(campaign.parsed_filters) && campaign.parsed_filters.length > 0) {
    return campaign.parsed_filters;
  }
  if (campaign.filtros_json) {
    try {
      const parsed = JSON.parse(campaign.filtros_json);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }
  return [];
};

const formatNumber = (n: number): string =>
  n.toLocaleString("pt-BR");

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function FilterTags({ filters }: { filters: ParsedFilter[] }) {
  const visible = filters.filter(
    (f) => f.operator !== "exclude_recent" && (f.column || f.field),
  );

  if (visible.length === 0) {
    return (
      <span className="text-xs text-muted-foreground italic">
        Sem filtros aplicados (base completa)
      </span>
    );
  }

  return (
    <div className="flex flex-wrap gap-1.5">
      {visible.map((f, i) => {
        const col = f.column || f.field || "?";
        const op = OPERATOR_LABELS[f.operator ?? ""] || f.operator || "?";
        const val = formatFilterValue(f.value);
        return (
          <Badge
            key={`${col}-${i}`}
            variant="outline"
            className="text-xs font-normal gap-1 py-0.5"
          >
            <span className="font-medium">{col}</span>
            <span className="text-muted-foreground">{op}</span>
            <span className="max-w-[180px] truncate">{val}</span>
          </Badge>
        );
      })}
    </div>
  );
}

function AudienceBadge({
  count,
  recordLimit,
}: {
  count?: number;
  recordLimit?: number;
}) {
  if (count === undefined || count === null || count < 0) {
    return (
      <Badge variant="secondary" className="gap-1.5 text-sm py-1 px-3">
        <Users className="h-3.5 w-3.5" />
        Audiência indisponível
      </Badge>
    );
  }

  return (
    <Badge
      variant="default"
      className="gap-1.5 text-sm py-1 px-3 bg-primary/90 hover:bg-primary"
    >
      <Users className="h-3.5 w-3.5" />
      {formatNumber(count)} contatos
      {recordLimit && recordLimit > 0 && count >= recordLimit && (
        <span className="text-primary-foreground/70 ml-1">(limite: {formatNumber(recordLimit)})</span>
      )}
    </Badge>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export default function CampanhasRecorrentes() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [refreshedEstimates, setRefreshedEstimates] = useState<
    Record<string, { count: number; date: string }>
  >({});

  const { data: campaigns = [], isLoading } = useQuery({
    queryKey: ["recurring-campaigns"],
    queryFn: getRecurring,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteRecurring(id),
    onSuccess: () => {
      toast({ title: "Campanha excluída com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ["recurring-campaigns"] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir",
        description: error.message || "Erro ao excluir campanha",
        variant: "destructive",
      });
    },
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, active }: { id: string; active: boolean }) =>
      toggleRecurring(id, active),
    onSuccess: (_, variables) => {
      toast({
        title: variables.active ? "Campanha ativada!" : "Campanha pausada!",
      });
      queryClient.invalidateQueries({ queryKey: ["recurring-campaigns"] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro",
        description: error.message || "Erro ao alterar status",
        variant: "destructive",
      });
    },
  });

  const executeMutation = useMutation({
    mutationFn: (id: string) => executeRecurringNow(id),
    onSuccess: () => {
      toast({
        title: "Execução iniciada",
        description:
          "A geração da campanha foi agendada e será processada em breve.",
      });
      queryClient.invalidateQueries({ queryKey: ["recurring-campaigns"] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao executar",
        description: error.message || "Erro ao gerar campanha",
        variant: "destructive",
      });
    },
  });

  const estimateMutation = useMutation({
    mutationFn: (id: string) => getRecurringEstimates(id),
    onSuccess: (data: any, id: string) => {
      if (data.success !== false && data.estimate !== undefined) {
        setRefreshedEstimates((prev) => ({
          ...prev,
          [id]: { count: data.estimate, date: new Date().toISOString() },
        }));
        toast({
          title: "Estimativa atualizada",
          description: `Público estimado: ${formatNumber(data.estimate)} contatos.`,
        });
      } else if (data.success !== false && data.data?.estimate !== undefined) {
        setRefreshedEstimates((prev) => ({
          ...prev,
          [id]: { count: data.data.estimate, date: new Date().toISOString() },
        }));
        toast({
          title: "Estimativa atualizada",
          description: `Público estimado: ${formatNumber(data.data.estimate)} contatos.`,
        });
      } else {
        toast({
          title: "Aviso",
          description: data.data?.message || "Erro na estimativa.",
          variant: "destructive",
        });
      }
    },
    onError: (error: any) => {
      toast({
        title: "Erro",
        description: error.message || "Erro ao estimar",
        variant: "destructive",
      });
    },
  });

  const [editOpen, setEditOpen] = useState(false);
  const [editSource, setEditSource] = useState<RecurringCampaign | null>(null);
  const [editNome, setEditNome] = useState("");
  const [editIncludeBaits, setEditIncludeBaits] = useState(false);
  const [editSelectedBaitIds, setEditSelectedBaitIds] = useState<number[]>([]);

  const { data: editBaits = [] } = useQuery({
    queryKey: ["baits-recurring-edit"],
    queryFn: getIscas,
    enabled: editOpen,
  });

  const openEditDialog = (c: RecurringCampaign) => {
    setEditSource(c);
    setEditNome(c.nome_campanha || "");
    const inc = Number(c.include_baits) === 1;
    setEditIncludeBaits(inc);
    const cfg = getProvidersConfigParsed(c);
    setEditSelectedBaitIds(normalizeBaitIds(cfg.bait_ids));
    setEditOpen(true);
  };

  // Filtro legado com include_baits e sem `bait_ids` no JSON: equivale a todas as iscas ativas
  useEffect(() => {
    if (!editOpen || !editIncludeBaits || !editSource) return;
    const cfg = getProvidersConfigParsed(editSource);
    if (normalizeBaitIds(cfg.bait_ids).length > 0) return;
    if (!Array.isArray(editBaits) || editBaits.length === 0) return;
    setEditSelectedBaitIds((prev) => {
      if (prev.length > 0) return prev;
      return editBaits
        .map((b: { id?: unknown }) => Number(b.id))
        .filter((n: number) => !Number.isNaN(n) && n > 0);
    });
  }, [editOpen, editIncludeBaits, editSource, editBaits]);

  const saveEditMutation = useMutation({
    mutationFn: async () => {
      if (!editSource) throw new Error("Nenhum filtro selecionado");
      const cfg = getProvidersConfigParsed(editSource);
      if (editIncludeBaits) {
        cfg.bait_ids = [...editSelectedBaitIds];
      } else {
        delete cfg.bait_ids;
      }

      let filters: unknown = [];
      if (editSource.filtros_json) {
        try {
          filters = JSON.parse(editSource.filtros_json);
        } catch {
          filters = [];
        }
      }

      let throttling: Record<string, unknown> = {};
      if (editSource.throttling_config) {
        try {
          throttling =
            typeof editSource.throttling_config === "string"
              ? JSON.parse(editSource.throttling_config)
              : (editSource.throttling_config as Record<string, unknown>);
        } catch {
          throttling = {};
        }
      }

      const excludePhones = Number(cfg.exclude_recent_phones ?? 1);
      const excludeHours = Number(cfg.exclude_recent_hours ?? 48);

      return saveRecurring({
        id: parseInt(String(editSource.id), 10),
        nome_campanha: editNome.trim(),
        table_name: editSource.tabela_origem,
        carteira: editSource.carteira || "",
        providers_config: cfg,
        filters: Array.isArray(filters) ? filters : [],
        template_id:
          editSource.template_source === "local" && editSource.template_id
            ? parseInt(String(editSource.template_id), 10)
            : null,
        template_code: editSource.template_code ?? "",
        template_source: editSource.template_source || "local",
        broker_code: editSource.broker_code,
        customer_code: editSource.customer_code,
        record_limit: parseInt(String(editSource.record_limit || 0), 10) || 0,
        exclude_recent_phones: excludePhones,
        exclude_recent_hours: excludeHours,
        include_baits: editIncludeBaits ? 1 : 0,
        throttling_type: editSource.throttling_type || "none",
        throttling_config: throttling,
      });
    },
    onSuccess: () => {
      toast({ title: "Filtro atualizado", description: "As alterações foram salvas." });
      setEditOpen(false);
      setEditSource(null);
      queryClient.invalidateQueries({ queryKey: ["recurring-campaigns"] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Não foi possível atualizar o filtro.",
        variant: "destructive",
      });
    },
  });

  const handleToggleActive = (campaign: RecurringCampaign) => {
    toggleMutation.mutate({
      id: campaign.id,
      active: !isActive(campaign.ativo),
    });
  };

  const formatDate = (dateString?: string) => {
    if (!dateString || dateString === "0000-00-00 00:00:00") return "—";
    try {
      return new Date(dateString).toLocaleString("pt-BR");
    } catch {
      return "—";
    }
  };

  const getDisplayCount = (campaign: any): number | undefined => {
    if (refreshedEstimates[campaign.id]) {
      return refreshedEstimates[campaign.id].count;
    }
    if (
      campaign.estimated_count !== undefined &&
      campaign.estimated_count !== null &&
      campaign.estimated_count >= 0
    ) {
      return campaign.estimated_count;
    }
    return undefined;
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Filtros Salvos"
        description="Visualize, estime e execute campanhas com filtros pré-configurados."
      />

      <Alert>
        <Info className="h-4 w-4" />
        <AlertDescription>
          Para criar novos filtros salvos, vá em{" "}
          <strong>Nova Campanha</strong> e marque a opção "Salvar Filtros"
          ao final do formulário. As campanhas geradas terão um cooldown
          automático de 24h por número.
        </AlertDescription>
      </Alert>

      {isLoading ? (
        <div className="grid gap-4">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : campaigns.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Clock className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold">Nenhum filtro salvo</h3>
            <p className="text-muted-foreground text-center mt-2">
              Crie seu primeiro filtro salvo através da tela{" "}
              <strong>Nova Campanha</strong>
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {campaigns.map((campaign: any, index: number) => {
            const filters = getFilters(campaign);
            const displayCount = getDisplayCount(campaign);
            const recordLimit = parseInt(campaign.record_limit) || 0;
            const hasRefreshed = !!refreshedEstimates[campaign.id];

            return (
              <Card
                key={`campaign-${campaign.id || index}`}
                className="animate-slide-in"
                style={{ animationDelay: `${index * 80}ms` }}
              >
                {/* ---- Header ---- */}
                <CardHeader className="pb-3">
                  <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div className="flex items-start gap-4 min-w-0">
                      <div
                        className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${
                          isActive(campaign.ativo)
                            ? "bg-success/10"
                            : "bg-muted"
                        }`}
                      >
                        {isActive(campaign.ativo) ? (
                          <CheckCircle className="h-6 w-6 text-success" />
                        ) : (
                          <Pause className="h-6 w-6 text-muted-foreground" />
                        )}
                      </div>
                      <div className="min-w-0">
                        <CardTitle className="text-lg truncate">
                          {campaign.nome_campanha}
                        </CardTitle>
                        <CardDescription className="flex flex-wrap items-center gap-2 mt-1">
                          <Badge variant="secondary">
                            {campaign.tabela_origem}
                          </Badge>
                          <span className="text-muted-foreground">•</span>
                          <span>
                            {parseProviderNames(campaign.providers_config)}
                          </span>
                        </CardDescription>

                        {/* Audience badge right below the title */}
                        <div className="flex items-center gap-2 mt-2">
                          <AudienceBadge
                            count={displayCount}
                            recordLimit={recordLimit}
                          />
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() =>
                              estimateMutation.mutate(campaign.id)
                            }
                            disabled={estimateMutation.isPending}
                            title="Atualizar estimativa"
                          >
                            {estimateMutation.isPending &&
                            estimateMutation.variables === campaign.id ? (
                              <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                              <RefreshCw className="h-3.5 w-3.5" />
                            )}
                          </Button>
                          {hasRefreshed && (
                            <span className="text-[11px] text-muted-foreground">
                              atualizado agora
                            </span>
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Action buttons */}
                    <div className="flex items-center gap-3 shrink-0">
                      <Switch
                        checked={isActive(campaign.ativo)}
                        onCheckedChange={() => handleToggleActive(campaign)}
                        disabled={toggleMutation.isPending}
                      />
                      <Button
                        variant="default"
                        size="sm"
                        onClick={() => executeMutation.mutate(campaign.id)}
                        disabled={
                          !isActive(campaign.ativo) ||
                          executeMutation.isPending
                        }
                      >
                        {executeMutation.isPending &&
                        executeMutation.variables === campaign.id ? (
                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                          <Play className="mr-2 h-4 w-4" />
                        )}
                        Gerar Agora
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => openEditDialog(campaign as RecurringCampaign)}
                        title="Editar nome e iscas"
                      >
                        <Pencil className="h-4 w-4 sm:mr-1" />
                        <span className="hidden sm:inline">Editar</span>
                      </Button>
                      <AlertDialog>
                        <AlertDialogTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-destructive hover:text-destructive"
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                          <AlertDialogHeader>
                            <AlertDialogTitle>
                              Excluir filtro salvo?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                              Esta ação não pode ser desfeita. O filtro salvo
                              &ldquo;{campaign.nome_campanha}&rdquo; será
                              removido permanentemente.
                            </AlertDialogDescription>
                          </AlertDialogHeader>
                          <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                              onClick={() =>
                                deleteMutation.mutate(String(campaign.id))
                              }
                              className="bg-destructive hover:bg-destructive/90"
                              disabled={deleteMutation.isPending}
                            >
                              {deleteMutation.isPending && (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                              )}
                              Excluir
                            </AlertDialogAction>
                          </AlertDialogFooter>
                        </AlertDialogContent>
                      </AlertDialog>
                    </div>
                  </div>
                </CardHeader>

                <CardContent className="space-y-4">
                  {/* ---- Filters section ---- */}
                  <Collapsible defaultOpen={filters.length <= 6}>
                    <div className="flex items-center gap-2 mb-2">
                      <Filter className="h-3.5 w-3.5 text-muted-foreground" />
                      <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                        Filtros Aplicados
                        {filters.filter(f => f.operator !== 'exclude_recent').length > 0 && (
                          <span className="ml-1.5 text-foreground">
                            ({filters.filter(f => f.operator !== 'exclude_recent').length})
                          </span>
                        )}
                      </span>
                      {filters.length > 6 && (
                        <CollapsibleTrigger asChild>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-6 px-2 text-xs"
                          >
                            ver todos
                          </Button>
                        </CollapsibleTrigger>
                      )}
                    </div>
                    <CollapsibleContent>
                      <FilterTags filters={filters} />
                    </CollapsibleContent>
                  </Collapsible>

                  <Separator />

                  {/* ---- Metadata grid ---- */}
                  <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg bg-muted/50 p-3">
                      <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                        <Layers className="h-3 w-3" /> Base de Dados
                      </p>
                      <p className="font-medium text-sm truncate">
                        {campaign.tabela_origem}
                      </p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3">
                      <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                        <Hash className="h-3 w-3" /> Template
                      </p>
                      <p className="font-medium text-sm truncate">
                        {campaign.template_code || campaign.template_id || "—"}
                        {campaign.template_source && campaign.template_source !== "local" && (
                          <span className="text-xs text-muted-foreground ml-1">
                            ({campaign.template_source})
                          </span>
                        )}
                      </p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3">
                      <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                        <Clock className="h-3 w-3" /> Última Execução
                      </p>
                      <p className="font-medium text-sm">
                        {formatDate(campaign.ultima_execucao)}
                      </p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3">
                      <p className="text-xs text-muted-foreground mb-1">
                        Status
                      </p>
                      <Badge
                        variant={
                          isActive(campaign.ativo) ? "default" : "secondary"
                        }
                      >
                        {isActive(campaign.ativo) ? "Ativa" : "Inativa"}
                      </Badge>
                    </div>
                  </div>

                  {(() => {
                    const pcfg = getProvidersConfigParsed(campaign as RecurringCampaign);
                    const baitIds = normalizeBaitIds(pcfg.bait_ids);
                    const inc = Number(campaign.include_baits) === 1;
                    if (!inc && baitIds.length === 0) return null;
                    return (
                      <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-sm">
                        <span className="font-medium text-foreground">Iscas: </span>
                        {!inc ? (
                          <span className="text-muted-foreground">desligado (há IDs salvos no JSON legado)</span>
                        ) : baitIds.length > 0 ? (
                          <span>
                            {baitIds.length} selecionada(s) no filtro
                            <span className="text-muted-foreground text-xs ml-1">
                              (IDs: {baitIds.slice(0, 8).join(", ")}
                              {baitIds.length > 8 ? "…" : ""})
                            </span>
                          </span>
                        ) : (
                          <span className="text-muted-foreground">
                            todas as iscas ativas (nenhum <code className="text-xs">bait_ids</code> no JSON — legado)
                          </span>
                        )}
                      </div>
                    );
                  })()}
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      <Dialog
        open={editOpen}
        onOpenChange={(open) => {
          setEditOpen(open);
          if (!open) setEditSource(null);
        }}
      >
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Editar filtro salvo</DialogTitle>
            <DialogDescription>
              Atualize o nome e as iscas usadas quando a opção estiver ativa. O campo{" "}
              <strong>providers_config</strong> no servidor passa a refletir o array{" "}
              <code className="text-xs">bait_ids</code>.
            </DialogDescription>
          </DialogHeader>
          {editSource && (
            <div className="space-y-4 py-2">
              <div className="space-y-2">
                <Label htmlFor="edit-nome-recurring">Nome da campanha</Label>
                <Input
                  id="edit-nome-recurring"
                  value={editNome}
                  onChange={(e) => setEditNome(e.target.value)}
                  placeholder="Nome do filtro salvo"
                />
              </div>
              <div className="rounded-lg border border-dashed border-border p-3 space-y-3">
                <div className="flex items-center gap-3">
                  <Checkbox
                    id="edit-include-baits-recurring"
                    checked={editIncludeBaits}
                    onCheckedChange={(checked) => {
                      const on = !!checked;
                      setEditIncludeBaits(on);
                      if (on && Array.isArray(editBaits) && editBaits.length) {
                        setEditSelectedBaitIds(
                          editBaits
                            .map((b: { id?: unknown }) => Number(b.id))
                            .filter((n: number) => !Number.isNaN(n) && n > 0),
                        );
                      } else if (!on) {
                        setEditSelectedBaitIds([]);
                      }
                    }}
                  />
                  <Label htmlFor="edit-include-baits-recurring" className="font-medium leading-snug cursor-pointer">
                    Incluir iscas ao gerar campanha
                  </Label>
                </div>
                {editIncludeBaits && (
                  <div className="space-y-2 max-h-52 overflow-y-auto pl-0.5">
                    {Array.isArray(editBaits) && editBaits.length === 0 && (
                      <p className="text-xs text-muted-foreground">Carregando iscas…</p>
                    )}
                    {Array.isArray(editBaits) &&
                      editBaits.map((isca: { id?: unknown; telefone?: string; nome?: string }) => {
                        const id = Number(isca.id);
                        if (!id) return null;
                        return (
                          <label
                            key={id}
                            className="flex items-center gap-2 text-sm cursor-pointer rounded-md px-1 py-0.5 hover:bg-muted/80"
                          >
                            <Checkbox
                              checked={editSelectedBaitIds.includes(id)}
                              onCheckedChange={(ck) => {
                                const on = !!ck;
                                setEditSelectedBaitIds((prev) =>
                                  on ? [...prev, id] : prev.filter((x) => x !== id),
                                );
                              }}
                            />
                            <span className="truncate">
                              {isca.telefone}
                              {isca.nome ? ` — ${isca.nome}` : ""}
                            </span>
                          </label>
                        );
                      })}
                  </div>
                )}
              </div>
            </div>
          )}
          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => setEditOpen(false)}
            >
              Cancelar
            </Button>
            <Button
              type="button"
              onClick={() => saveEditMutation.mutate()}
              disabled={!editNome.trim() || saveEditMutation.isPending}
            >
              {saveEditMutation.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : null}
              Salvar alterações
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
