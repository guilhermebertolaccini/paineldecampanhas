import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Download, Filter, Loader2 } from "lucide-react";
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
  PieChart,
  Pie,
  Cell,
  Legend,
} from "recharts";
import { getRelatorioCustos } from "@/lib/api";

const COLORS = ["hsl(var(--primary))", "hsl(var(--info))", "hsl(var(--success))"];

export default function RelatorioCusto() {
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [provider, setProvider] = useState("all");

  const { data: reportData, isLoading, refetch } = useQuery({
    queryKey: ['relatorio-custos', dateFrom, dateTo, provider],
    queryFn: () =>
      getRelatorioCustos({
        data_inicio: dateFrom,
        data_fim: dateTo,
        provider: provider !== "all" ? provider : "",
      }),
    enabled: !!dateFrom && !!dateTo, // Só busca quando ambas as datas estão preenchidas
  });

  const handleFilter = () => {
    if (!dateFrom || !dateTo) {
      return;
    }
    refetch();
  };

  const providerCostData =
    reportData?.por_provider?.map((p: any) => ({
      provider: p.provider || p.fornecedor || "N/A",
      custo: parseFloat(p.total_custo || p.custo_total || 0),
      mensagens: parseInt(p.total_mensagens || p.quantidade || 0),
    })) || [];

  const carteiraData =
    reportData?.por_carteira?.map((c: any) => ({
      name: c.nome_carteira || `Carteira #${c.carteira_id}`,
      value: parseFloat(c.total_custo || 0),
    })) || [];

  const totalCusto = providerCostData.reduce((acc: number, p: any) => acc + p.custo, 0);
  const totalMensagens = providerCostData.reduce((acc: number, p: any) => acc + p.mensagens, 0);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Relatório de Custos"
        description="Visualize gastos por fornecedor e carteira"
      >
        <Button variant="outline">
          <Download className="mr-2 h-4 w-4" />
          Exportar
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

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          title="Total Gasto"
          value={`R$ ${totalCusto.toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          icon={<Download className="h-6 w-6" />}
          variant="primary"
        />
        <StatCard
          title="Total de Mensagens"
          value={totalMensagens.toLocaleString("pt-BR")}
          icon={<Download className="h-6 w-6" />}
          variant="success"
        />
        <StatCard
          title="Custo Médio"
          value={`R$ ${totalMensagens > 0 ? (totalCusto / totalMensagens).toFixed(4).replace('.', ',') : '0,0000'}`}
          icon={<Download className="h-6 w-6" />}
          variant="info"
        />
      </div>

      {/* Charts */}
      {isLoading ? (
        <div className="space-y-4">
          <Skeleton className="h-96" />
          <Skeleton className="h-96" />
        </div>
      ) : providerCostData.length > 0 ? (
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Bar Chart */}
          <Card>
            <CardHeader>
              <CardTitle>Custo por Fornecedor</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={providerCostData}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="provider" />
                  <YAxis />
                  <Tooltip
                    formatter={(value: number) =>
                      `R$ ${value.toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`
                    }
                  />
                  <Bar dataKey="custo" fill="hsl(var(--primary))" />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          {/* Pie Chart */}
          <Card>
            <CardHeader>
              <CardTitle>Distribuição por Carteira</CardTitle>
            </CardHeader>
            <CardContent>
              {carteiraData.length > 0 ? (
                <ResponsiveContainer width="100%" height={300}>
                  <PieChart>
                    <Pie
                      data={carteiraData}
                      cx="50%"
                      cy="50%"
                      labelLine={false}
                      label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                      outerRadius={80}
                      fill="#8884d8"
                      dataKey="value"
                    >
                      {carteiraData.map((entry: any, index: number) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip
                      formatter={(value: number) =>
                        `R$ ${value.toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`
                      }
                    />
                  </PieChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex items-center justify-center h-[300px] text-muted-foreground">
                  Sem dados de carteira para o período selecionado
                </div>
              )}
            </CardContent>
          </Card>
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
    </div>
  );
}
