import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Play, Trash2, Clock, CheckCircle, Pause, Loader2, Info, Users } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import { useToast } from "@/hooks/use-toast";
import {
  getRecurring,
  deleteRecurring,
  toggleRecurring,
  executeRecurringNow,
  getRecurringEstimates,
} from "@/lib/api";

interface RecurringCampaign {
  id: string;
  nome_campanha: string;
  tabela_origem: string;
  filtros_json?: string;
  providers_config?: string;
  template_id: string;
  ativo: number | boolean | string;
  ultima_execucao?: string;
  totalRuns?: number;
}

const isActive = (value: unknown): boolean =>
  value === true || value === 1 || value === '1';

const parseProviderNames = (config?: string): string => {
  if (!config) return '—';
  try {
    const parsed = JSON.parse(config);
    if (Array.isArray(parsed?.providers)) {
      return parsed.providers.map((p: any) => p.id || p.name || '?').join(', ') || '—';
    }
    if (typeof parsed === 'object' && parsed !== null) {
      const mode = parsed.mode ? `${parsed.mode}` : '';
      const ids = Object.keys(parsed).filter(k => k !== 'mode');
      return [mode, ...ids].filter(Boolean).join(' · ') || '—';
    }
    return String(parsed) || '—';
  } catch {
    return String(config);
  }
};

export default function CampanhasRecorrentes() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [estimates, setEstimates] = useState<Record<string, { count: number; date: string }>>({});

  // Buscar campanhas recorrentes
  const { data: campaigns = [], isLoading } = useQuery({
    queryKey: ['recurring-campaigns'],
    queryFn: getRecurring,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteRecurring(id),
    onSuccess: () => {
      toast({ title: "Campanha excluída com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['recurring-campaigns'] });
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
    mutationFn: ({ id, active }: { id: string; active: boolean }) => toggleRecurring(id, active),
    onSuccess: (_, variables) => {
      toast({
        title: variables.active ? "Campanha ativada!" : "Campanha pausada!",
      });
      queryClient.invalidateQueries({ queryKey: ['recurring-campaigns'] });
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
        description: "A geração da campanha foi agendada e será processada em breve.",
      });
      queryClient.invalidateQueries({ queryKey: ['recurring-campaigns'] });
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
      if (data.success) {
        setEstimates(prev => ({ ...prev, [id]: { count: data.data.estimated_count, date: new Date().toISOString() } }));
        toast({ title: "Estimativa concluída", description: `Público estimado: ${data.data.estimated_count} contatos.` });
      } else {
        toast({ title: "Aviso", description: data.data?.message || "Erro na estimativa.", variant: "destructive" });
      }
    },
    onError: (error: any) => {
      toast({ title: "Erro", description: error.message || "Erro ao estimar", variant: "destructive" });
    },
  });

  const handleToggleActive = (campaign: RecurringCampaign) => {
    toggleMutation.mutate({ id: campaign.id, active: !isActive(campaign.ativo) });
  };

  const handleExecuteNow = (campaign: RecurringCampaign) => {
    executeMutation.mutate(campaign.id);
  };

  const handleDelete = (id: string) => {
    deleteMutation.mutate(id);
  };

  const formatDate = (dateString?: string) => {
    if (!dateString || dateString === '0000-00-00 00:00:00') return '-';
    try {
      return new Date(dateString).toLocaleString('pt-BR');
    } catch {
      return '-';
    }
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
          Para criar novos filtros salvos, vá em <strong>Nova Campanha</strong> e marque a opção "Salvar Filtros" ao final do formulário. As campanhas geradas terão um cooldown automático de 24h por número.
        </AlertDescription>
      </Alert>

      {isLoading ? (
        <div className="grid gap-4">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-48" />
          ))}
        </div>
      ) : campaigns.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Clock className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold">Nenhum filtro salvo</h3>
            <p className="text-muted-foreground text-center mt-2">
              Crie seu primeiro filtro salvo através da tela <strong>Nova Campanha</strong>
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {campaigns.map((campaign: any, index: number) => (
            <Card
              key={`campaign-${campaign.id || index}`}
              className="animate-slide-in"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                  <div className="flex items-start gap-4">
                    <div
                      className={`flex h-12 w-12 items-center justify-center rounded-xl ${isActive(campaign.ativo) ? "bg-success/10" : "bg-muted"
                        }`}
                    >
                      {isActive(campaign.ativo) ? (
                        <CheckCircle className="h-6 w-6 text-success" />
                      ) : (
                        <Pause className="h-6 w-6 text-muted-foreground" />
                      )}
                    </div>
                    <div>
                      <CardTitle className="text-lg">{campaign.nome_campanha}</CardTitle>
                      <CardDescription className="flex flex-wrap items-center gap-2 mt-1">
                        <Badge variant="secondary">{campaign.tabela_origem}</Badge>
                        <span>•</span>
                        <span>{parseProviderNames(campaign.providers_config)}</span>
                      </CardDescription>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <Switch
                      checked={isActive(campaign.ativo)}
                      onCheckedChange={() => handleToggleActive(campaign)}
                      disabled={toggleMutation.isPending}
                    />
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => estimateMutation.mutate(campaign.id)}
                      disabled={!isActive(campaign.ativo) || estimateMutation.isPending}
                    >
                      {estimateMutation.isPending && estimateMutation.variables === campaign.id ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <Users className="mr-2 h-4 w-4" />
                      )}
                      Estimar
                    </Button>
                    <Button
                      variant="default"
                      size="sm"
                      onClick={() => handleExecuteNow(campaign)}
                      disabled={!isActive(campaign.ativo) || executeMutation.isPending}
                    >
                      {executeMutation.isPending && executeMutation.variables === campaign.id ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <Play className="mr-2 h-4 w-4" />
                      )}
                      Gerar Agora
                    </Button>
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive">
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>Excluir filtro salvo?</AlertDialogTitle>
                          <AlertDialogDescription>
                            Esta ação não pode ser desfeita. O filtro salvo "{campaign.nome_campanha}" será removido permanentemente.
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>Cancelar</AlertDialogCancel>
                          <AlertDialogAction
                            onClick={() => handleDelete(String(campaign.id))}
                            className="bg-destructive hover:bg-destructive/90"
                            disabled={deleteMutation.isPending}
                          >
                            {deleteMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Excluir
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4 sm:grid-cols-4">
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Base de Dados</p>
                    <p className="font-medium text-sm">{campaign.tabela_origem}</p>
                  </div>
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Template ID</p>
                    <p className="font-medium text-sm">{campaign.template_id}</p>
                  </div>
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                      <Clock className="h-3 w-3" /> Última Execução
                    </p>
                    <p className="font-medium text-sm">{formatDate(campaign.ultima_execucao)}</p>
                  </div>
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Status</p>
                    <Badge variant={isActive(campaign.ativo) ? "default" : "secondary"}>
                      {isActive(campaign.ativo) ? "Ativa" : "Inativa"}
                    </Badge>
                  </div>
                  {estimates[campaign.id] && (
                    <div className="rounded-lg bg-primary/10 p-3 sm:col-span-4 mt-2">
                      <p className="text-sm font-medium flex items-center gap-2">
                        <Users className="h-4 w-4" /> Público estimado agora: {estimates[campaign.id].count} contatos
                        <span className="text-xs text-muted-foreground font-normal ml-auto">
                          (Cooldown de 24h já aplicado)
                        </span>
                      </p>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
