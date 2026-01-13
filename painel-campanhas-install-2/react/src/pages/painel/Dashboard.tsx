import { FileText, Clock, Send, CalendarDays, TrendingUp, Loader2 } from "lucide-react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { PageHeader } from "@/components/layout/PageHeader";
import { StatCard } from "@/components/dashboard/StatCard";
import { CampaignTable, Campaign } from "@/components/dashboard/CampaignTable";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { getDashboardStats } from "@/lib/api";
import { Skeleton } from "@/components/ui/skeleton";

export default function Dashboard() {
  const navigate = useNavigate();
  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: getDashboardStats,
  });

  if (isLoading) {
    return (
      <div className="space-y-8">
        <PageHeader title="Dashboard" description="Visão geral do sistema de campanhas" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[1, 2, 3, 4].map((i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
        <Skeleton className="h-96" />
      </div>
    );
  }

  if (error) {
    console.error('Erro ao carregar dashboard:', error);
    return (
      <div className="space-y-8">
        <PageHeader title="Dashboard" description="Visão geral do sistema de campanhas" />
        <div className="text-center py-12">
          <p className="text-destructive mb-2">Erro ao carregar dados do dashboard</p>
          <p className="text-sm text-muted-foreground">
            {error instanceof Error ? error.message : 'Erro desconhecido'}
          </p>
          <button 
            onClick={() => window.location.reload()} 
            className="mt-4 px-4 py-2 bg-primary text-primary-foreground rounded-md hover:bg-primary/90"
          >
            Recarregar página
          </button>
        </div>
      </div>
    );
  }

  const stats = [
    { 
      title: "Total de Campanhas", 
      value: data?.total?.toString() || "0", 
      icon: <FileText className="h-6 w-6" />, 
      variant: "primary" as const 
    },
    { 
      title: "Pendentes de Aprovação", 
      value: data?.pending?.toString() || "0", 
      icon: <Clock className="h-6 w-6" />, 
      variant: "warning" as const 
    },
    { 
      title: "Campanhas Enviadas", 
      value: data?.sent?.toString() || "0", 
      icon: <Send className="h-6 w-6" />, 
      variant: "success" as const 
    },
    { 
      title: "Criadas Hoje", 
      value: data?.today?.toString() || "0", 
      icon: <CalendarDays className="h-6 w-6" />, 
      variant: "info" as const 
    },
  ];

  return (
    <div className="space-y-8">
      <PageHeader
        title="Dashboard"
        description="Visão geral do sistema de campanhas"
      />

      {/* Stats Grid */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {stats.map((stat, index) => (
          <StatCard
            key={stat.title}
            {...stat}
            className="animate-slide-in"
            style={{ animationDelay: `${index * 100}ms` } as React.CSSProperties}
          />
        ))}
      </div>

      {/* Main Content Grid */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Recent Campaigns */}
        <div className="lg:col-span-2">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-lg font-semibold">Últimas Campanhas</CardTitle>
              <button 
                onClick={() => navigate('/painel/campanhas')}
                className="text-sm text-primary hover:underline font-medium"
              >
                Ver todas
              </button>
            </CardHeader>
            <CardContent className="p-0">
              {data?.recentCampaigns && data.recentCampaigns.length > 0 ? (
                <CampaignTable campaigns={data.recentCampaigns as Campaign[]} showActions={false} />
              ) : (
                <div className="p-8 text-center text-muted-foreground">
                  Nenhuma campanha ainda
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Quick Actions */}
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg font-semibold">Ações Rápidas</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <button
                onClick={() => navigate('/painel/nova-campanha')}
                className="w-full p-4 bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 hover:from-blue-100 hover:to-purple-100 dark:hover:from-blue-800/30 dark:hover:to-purple-800/30 rounded-lg text-blue-600 dark:text-blue-400 transition-all text-left"
              >
                <span className="font-medium block mb-1">Nova Campanha</span>
                <span className="text-sm opacity-80">Criar nova campanha</span>
              </button>
              <button
                onClick={() => navigate('/painel/mensagens')}
                className="w-full p-4 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 hover:from-green-100 hover:to-emerald-100 dark:hover:from-green-800/30 dark:hover:to-emerald-800/30 rounded-lg text-green-600 dark:text-green-400 transition-all text-left"
              >
                <span className="font-medium block mb-1">Templates de Mensagem</span>
                <span className="text-sm opacity-80">Gerenciar templates</span>
              </button>
              <button
                onClick={() => navigate('/painel/relatorios')}
                className="w-full p-4 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 hover:from-purple-100 hover:to-pink-100 dark:hover:from-purple-800/30 dark:hover:to-pink-800/30 rounded-lg text-purple-600 dark:text-purple-400 transition-all text-left"
              >
                <span className="font-medium block mb-1">Relatórios</span>
                <span className="text-sm opacity-80">Ver relatórios</span>
              </button>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}

