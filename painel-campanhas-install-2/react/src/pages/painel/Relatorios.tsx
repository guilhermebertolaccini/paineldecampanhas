import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { Download, Filter, BarChart3, PieChart, TrendingUp, Calendar, Loader2 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { StatCard } from "@/components/dashboard/StatCard";
import { Skeleton } from "@/components/ui/skeleton";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart as RechartsPie,
  Pie,
  Cell,
  LineChart,
  Line,
  Legend,
} from "recharts";
import { getReportData, getReport1x1Stats } from "@/lib/api";

const COLORS = ["hsl(var(--primary))", "hsl(var(--info))", "hsl(var(--success))", "hsl(var(--warning))"];

// Função helper para obter primeiro dia do mês atual
const getFirstDayOfMonth = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}-01`;
};

// Função helper para obter data de hoje
const getToday = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

export default function Relatorios() {
  const [dateFrom, setDateFrom] = useState(getFirstDayOfMonth());
  const [dateTo, setDateTo] = useState(getToday());
  const [provider, setProvider] = useState("all");
  const [status, setStatus] = useState("all");

  const { data: reportData, isLoading, refetch } = useQuery({
    queryKey: ['report-data', dateFrom, dateTo, provider, status],
    queryFn: () =>
      getReportData({
        data_inicio: dateFrom,
        data_fim: dateTo,
        fornecedor: provider !== "all" ? provider : "",
        status: status !== "all" ? status : "",
      }),
    enabled: !!dateFrom && !!dateTo,
  });

  const { data: stats1x1 } = useQuery({
    queryKey: ['report-1x1-stats', dateFrom, dateTo],
    queryFn: () =>
      getReport1x1Stats({
        data_inicio: dateFrom,
        data_fim: dateTo,
      }),
    enabled: !!dateFrom && !!dateTo,
  });

  const handleFilter = () => {
    if (!dateFrom || !dateTo) {
      return;
    }
    refetch();
  };

  // Processa dados do relatório
  const totals = reportData?.totals || {};
  const rows = reportData?.data || [];

  // Agrupa por status a partir dos totals
  const statusData = [
    { status: "Enviado", quantidade: totals.total_enviado || 0, fill: COLORS[0] },
    { status: "Pendente Aprovação", quantidade: totals.total_pendente_aprovacao || 0, fill: COLORS[1] },
    { status: "Pendente", quantidade: totals.total_pendente || 0, fill: COLORS[2] },
    { status: "Negado", quantidade: totals.total_negado || 0, fill: COLORS[3] },
  ].filter(s => s.quantidade > 0);

  // Agrupa por fornecedor a partir dos dados
  const providerMap = new Map<string, number>();
  rows.forEach((row: any) => {
    const provider = row.FORNECEDOR || row.fornecedor || "N/A";
    const qtd = parseInt(row.QTD_ENVIADO || row.qtd_enviado || 0);
    providerMap.set(provider, (providerMap.get(provider) || 0) + qtd);
  });
  const providerData = Array.from(providerMap.entries()).map(([name, value]) => ({
    name,
    value,
  }));

  // Agrupa por dia a partir dos dados
  const dailyMap = new Map<string, { enviados: number; entregues: number }>();
  rows.forEach((row: any) => {
    const data = row.DATA || row.data;
    if (data) {
      const qtdEnviado = parseInt(row.QTD_ENVIADO || row.qtd_enviado || 0);
      const current = dailyMap.get(data) || { enviados: 0, entregues: 0 };
      dailyMap.set(data, {
        enviados: current.enviados + qtdEnviado,
        entregues: current.entregues + qtdEnviado, // Assumindo que enviado = entregue
      });
    }
  });
  const weeklyData = Array.from(dailyMap.entries())
    .slice(0, 7)
    .map(([data, values]) => ({
      dia: new Date(data).toLocaleDateString('pt-BR', { weekday: 'short' }),
      enviados: values.enviados,
      entregues: values.entregues,
    }));

  const totalEnviadas = totals.total_enviado || 0;
  const taxaEntrega = totalEnviadas > 0 ? ((totals.total_enviado || 0) / totalEnviadas) * 100 : 0;
  const custoTotal = 0; // Não temos esse dado no relatório atual
  const custoPorMensagem = 0;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Relatórios"
        description="Estatísticas e análises de campanhas"
      >
        <Button variant="outline">
          <Download className="mr-2 h-4 w-4" />
          Exportar CSV
        </Button>
      </PageHeader>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div className="space-y-2 flex-1">
              <Label>Data Inicial</Label>
              <Input
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
              />
            </div>
            <div className="space-y-2 flex-1">
              <Label>Data Final</Label>
              <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
            </div>
            <div className="space-y-2 flex-1">
              <Label>Fornecedor</Label>
              <Select value={provider} onValueChange={setProvider}>
                <SelectTrigger>
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="CDA">CDA</SelectItem>
                  <SelectItem value="GOSAC">GOSAC</SelectItem>
                  <SelectItem value="NOAH">NOAH</SelectItem>
                  <SelectItem value="RCS">RCS</SelectItem>
                  <SelectItem value="SALESFORCE">Salesforce</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2 flex-1">
              <Label>Status</Label>
              <Select value={status} onValueChange={setStatus}>
                <SelectTrigger>
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="enviado">Enviado</SelectItem>
                  <SelectItem value="pendente_aprovacao">Pendente</SelectItem>
                  <SelectItem value="negado">Negado</SelectItem>
                  <SelectItem value="erro">Erro</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <Button
              onClick={handleFilter}
              disabled={!dateFrom || !dateTo || isLoading}
              className="gradient-primary hover:opacity-90"
            >
              {isLoading ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Filter className="mr-2 h-4 w-4" />
              )}
              Filtrar
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Summary Stats */}
      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[1, 2, 3, 4].map((i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total Enviadas"
            value={totalEnviadas.toLocaleString("pt-BR")}
            icon={<TrendingUp className="h-6 w-6" />}
            variant="primary"
          />
          <StatCard
            title="Taxa de Entrega"
            value={`${taxaEntrega.toFixed(1)}%`}
            icon={<BarChart3 className="h-6 w-6" />}
            variant="success"
          />
          <StatCard
            title="Custo Total"
            value={`R$ ${custoTotal.toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            icon={<PieChart className="h-6 w-6" />}
            variant="info"
          />
          <StatCard
            title="Custo/Mensagem"
            value={`R$ ${custoPorMensagem.toFixed(4).replace('.', ',')}`}
            icon={<Calendar className="h-6 w-6" />}
            variant="warning"
          />
        </div>
      )}

      {/* Charts */}
      {isLoading ? (
        <div className="grid gap-6 lg:grid-cols-2">
          <Skeleton className="h-96" />
          <Skeleton className="h-96" />
        </div>
      ) : reportData ? (
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Weekly Performance */}
          {weeklyData.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Performance Semanal</CardTitle>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                  <LineChart data={weeklyData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="dia" />
                    <YAxis />
                    <Tooltip />
                    <Legend />
                    <Line
                      type="monotone"
                      dataKey="enviados"
                      stroke="hsl(var(--primary))"
                      name="Enviados"
                    />
                    <Line
                      type="monotone"
                      dataKey="entregues"
                      stroke="hsl(var(--success))"
                      name="Entregues"
                    />
                  </LineChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          )}

          {/* Status Distribution */}
          {statusData.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Distribuição por Status</CardTitle>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart data={statusData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="status" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="quantidade" fill="hsl(var(--primary))" />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          )}

          {/* Provider Distribution */}
          {providerData.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Distribuição por Fornecedor</CardTitle>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                  <RechartsPie>
                    <Pie
                      data={providerData}
                      cx="50%"
                      cy="50%"
                      labelLine={false}
                      label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                      outerRadius={80}
                      fill="#8884d8"
                      dataKey="value"
                    >
                      {providerData.map((entry: any, index: number) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip />
                  </RechartsPie>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          )}
        </div>
      ) : (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <p className="text-muted-foreground">
              {!dateFrom || !dateTo
                ? "Selecione um período para visualizar o relatório"
                : "Nenhum dado encontrado para o período selecionado"}
            </p>
          </CardContent>
        </Card>
      )}

      {/* Stats 1x1 */}
      {stats1x1 && stats1x1.total && (
        <Card>
          <CardHeader>
            <CardTitle>Estatísticas 1x1</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="text-center p-4 rounded-lg bg-muted/50">
                <p className="text-2xl font-bold">{stats1x1.total || 0}</p>
                <p className="text-sm text-muted-foreground">Total 1x1</p>
              </div>
              {stats1x1.carteiras && stats1x1.carteiras.length > 0 && (
                <>
                  <div className="text-center p-4 rounded-lg bg-muted/50">
                    <p className="text-2xl font-bold">{stats1x1.carteiras.length}</p>
                    <p className="text-sm text-muted-foreground">Carteiras</p>
                  </div>
                  <div className="text-center p-4 rounded-lg bg-muted/50">
                    <p className="text-2xl font-bold">
                      {stats1x1.carteiras[0]?.carteira || 'N/A'}
                    </p>
                    <p className="text-sm text-muted-foreground">Top Carteira</p>
                  </div>
                </>
              )}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
