import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Check, X, Eye, MessageSquare, Users, Calendar, Loader2 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useToast } from "@/hooks/use-toast";
import { getPendingCampaigns, approveCampaign, denyCampaign } from "@/lib/api";

interface PendingCampaign {
  agendamento_id: string;
  idgis_ambiente: string;
  provider: string;
  status: string;
  created_at: string;
  total_clients: number;
  scheduled_by: string;
}

export default function AprovarCampanhas() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [selectedCampaign, setSelectedCampaign] = useState<PendingCampaign | null>(null);
  const [denyReason, setDenyReason] = useState("");
  const [showDenyDialog, setShowDenyDialog] = useState(false);
  const [showDetailsDialog, setShowDetailsDialog] = useState(false);

  const { data: campaigns = [], isLoading, error } = useQuery({
    queryKey: ['pending-campaigns'],
    queryFn: getPendingCampaigns,
    refetchInterval: 30000, // Refetch a cada 30 segundos
  });

  const approveMutation = useMutation({
    mutationFn: (campaign: PendingCampaign) => approveCampaign(campaign.agendamento_id, campaign.provider),
    onSuccess: () => {
      toast({
        title: "Campanha aprovada!",
        description: "A campanha foi enviada para execução.",
      });
      queryClient.invalidateQueries({ queryKey: ['pending-campaigns'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard-stats'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao aprovar",
        description: error.message || "Erro ao aprovar campanha",
        variant: "destructive",
      });
    },
  });

  const denyMutation = useMutation({
    mutationFn: ({ campaign, motivo }: { campaign: PendingCampaign; motivo: string }) => 
      denyCampaign(campaign.agendamento_id, campaign.provider, motivo),
    onSuccess: () => {
      toast({
        title: "Campanha negada",
        description: "O usuário será notificado sobre a negação.",
      });
      queryClient.invalidateQueries({ queryKey: ['pending-campaigns'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard-stats'] });
      setShowDenyDialog(false);
      setDenyReason("");
      setSelectedCampaign(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao negar",
        description: error.message || "Erro ao negar campanha",
        variant: "destructive",
      });
    },
  });

  const handleApprove = (campaign: PendingCampaign) => {
    approveMutation.mutate(campaign);
  };

  const handleDeny = () => {
    if (selectedCampaign) {
      denyMutation.mutate({ campaign: selectedCampaign, motivo: denyReason });
    }
  };

  const openDenyDialog = (campaign: PendingCampaign) => {
    setSelectedCampaign(campaign);
    setShowDenyDialog(true);
  };

  const openDetails = (campaign: PendingCampaign) => {
    setSelectedCampaign(campaign);
    setShowDetailsDialog(true);
  };

  if (error) {
    return (
      <div className="space-y-6">
        <PageHeader title="Aprovar Campanhas" description="Revise e aprove campanhas pendentes" />
        <Card>
          <CardContent className="py-12 text-center">
            <p className="text-destructive">Erro ao carregar campanhas pendentes</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Aprovar Campanhas"
        description="Revise e aprove campanhas pendentes"
      >
        <Badge variant="outline" className="text-warning border-warning/30 bg-warning/10">
          {campaigns.length} pendentes
        </Badge>
      </PageHeader>

      {isLoading ? (
        <div className="space-y-4">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : campaigns.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-success/10 mb-4">
              <Check className="h-8 w-8 text-success" />
            </div>
            <h3 className="text-lg font-semibold">Tudo em dia!</h3>
            <p className="text-muted-foreground">Não há campanhas pendentes de aprovação.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-4">
          {campaigns.map((campaign, index) => (
            <Card
              key={`${campaign.agendamento_id}-${campaign.provider}`}
              className="animate-slide-in"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                  <div>
                    <CardTitle className="text-lg">{campaign.agendamento_id}</CardTitle>
                    <CardDescription className="flex flex-wrap items-center gap-2 mt-1">
                      <span className="flex items-center gap-1">
                        <Users className="h-3.5 w-3.5" />
                        {campaign.scheduled_by}
                      </span>
                      <span>•</span>
                      <span className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        {new Date(campaign.created_at).toLocaleString('pt-BR')}
                      </span>
                    </CardDescription>
                  </div>
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => openDetails(campaign)}>
                      <Eye className="mr-2 h-4 w-4" />
                      Detalhes
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      className="text-destructive border-destructive/30 hover:bg-destructive/10"
                      onClick={() => openDenyDialog(campaign)}
                    >
                      <X className="mr-2 h-4 w-4" />
                      Negar
                    </Button>
                    <Button
                      size="sm"
                      className="bg-success hover:bg-success/90"
                      onClick={() => handleApprove(campaign)}
                      disabled={approveMutation.isPending}
                    >
                      {approveMutation.isPending ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <Check className="mr-2 h-4 w-4" />
                      )}
                      Aprovar
                    </Button>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4 sm:grid-cols-3">
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Fornecedor</p>
                    <p className="font-semibold">{campaign.provider.toUpperCase()}</p>
                  </div>
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Quantidade</p>
                    <p className="font-semibold">{campaign.total_clients.toLocaleString("pt-BR")}</p>
                  </div>
                  <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-xs text-muted-foreground mb-1">Ambiente IDGIS</p>
                    <p className="font-semibold text-sm">{campaign.idgis_ambiente}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Deny Dialog */}
      <Dialog open={showDenyDialog} onOpenChange={setShowDenyDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Negar Campanha</DialogTitle>
            <DialogDescription>
              Informe o motivo da negação para "{selectedCampaign?.agendamento_id}"
            </DialogDescription>
          </DialogHeader>
          <div className="py-4">
            <Textarea
              placeholder="Motivo da negação..."
              value={denyReason}
              onChange={(e) => setDenyReason(e.target.value)}
              rows={4}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDenyDialog(false)} disabled={denyMutation.isPending}>
              Cancelar
            </Button>
            <Button 
              onClick={handleDeny} 
              className="bg-destructive hover:bg-destructive/90"
              disabled={denyMutation.isPending || !denyReason.trim()}
            >
              {denyMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Confirmar Negação
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Details Dialog */}
      <Dialog open={showDetailsDialog} onOpenChange={setShowDetailsDialog}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{selectedCampaign?.agendamento_id}</DialogTitle>
            <DialogDescription>Detalhes completos da campanha</DialogDescription>
          </DialogHeader>
          {selectedCampaign && (
            <div className="space-y-4 py-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Usuário</p>
                  <p className="font-medium">{selectedCampaign.scheduled_by}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Fornecedor</p>
                  <p className="font-medium">{selectedCampaign.provider.toUpperCase()}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Quantidade</p>
                  <p className="font-medium">{selectedCampaign.total_clients.toLocaleString("pt-BR")}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Data de Criação</p>
                  <p className="font-medium">{new Date(selectedCampaign.created_at).toLocaleString('pt-BR')}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Ambiente IDGIS</p>
                  <p className="font-medium">{selectedCampaign.idgis_ambiente}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Status</p>
                  <Badge variant="outline">{selectedCampaign.status}</Badge>
                </div>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDetailsDialog(false)}>
              Fechar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
