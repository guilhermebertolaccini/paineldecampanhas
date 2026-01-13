import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Play, Trash2, Clock, CheckCircle, Pause, Loader2, Info } from "lucide-react";
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
} from "@/lib/api";

interface RecurringCampaign {
  id: string;
  nome_campanha: string;
  tabela_origem: string;
  filtros_json?: string;
  providers_config: string;
  template_id: string;
  ativo: boolean;
  ultima_execucao?: string;
  totalRuns?: number;
}

export default function CampanhasRecorrentes() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

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
        description: "A campanha está sendo executada.",
      });
      queryClient.invalidateQueries({ queryKey: ['recurring-campaigns'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao executar",
        description: error.message || "Erro ao executar campanha",
        variant: "destructive",
      });
    },
  });

  const handleToggleActive = (campaign: RecurringCampaign) => {
    toggleMutation.mutate({ id: campaign.id, active: !campaign.ativo });
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
        title="Campanhas Recorrentes"
        description="Visualize e execute campanhas automáticas já configuradas"
      />

      <Alert>
        <Info className="h-4 w-4" />
        <AlertDescription>
          Para criar novas campanhas recorrentes, vá em <strong>Nova Campanha</strong> e marque a opção "Campanha Recorrente" ao final do formulário.
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
            <h3 className="text-lg font-semibold">Nenhuma campanha recorrente</h3>
            <p className="text-muted-foreground text-center mt-2">
              Crie sua primeira campanha recorrente através da tela <strong>Nova Campanha</strong>
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
                      className={`flex h-12 w-12 items-center justify-center rounded-xl ${
                        campaign.ativo ? "bg-success/10" : "bg-muted"
                      }`}
                    >
                      {campaign.ativo ? (
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
                        <span>{JSON.parse(campaign.providers_config || '{}')}</span>
                      </CardDescription>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <Switch
                      checked={campaign.ativo}
                      onCheckedChange={() => handleToggleActive(campaign)}
                      disabled={toggleMutation.isPending}
                    />
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleExecuteNow(campaign)}
                      disabled={!campaign.ativo || executeMutation.isPending}
                    >
                      {executeMutation.isPending ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <Play className="mr-2 h-4 w-4" />
                      )}
                      Executar Agora
                    </Button>
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive">
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>Excluir campanha recorrente?</AlertDialogTitle>
                          <AlertDialogDescription>
                            Esta ação não pode ser desfeita. A campanha "{campaign.nome_campanha}" será removida permanentemente.
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
                    <Badge variant={campaign.ativo ? "default" : "secondary"}>
                      {campaign.ativo ? "Ativa" : "Inativa"}
                    </Badge>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
