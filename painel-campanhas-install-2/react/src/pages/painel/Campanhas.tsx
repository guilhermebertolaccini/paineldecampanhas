import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Search, Filter, Download, Plus, Loader2 } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { PageHeader } from "@/components/layout/PageHeader";
import { CampaignTable, Campaign } from "@/components/dashboard/CampaignTable";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { getCampanhas } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";

export default function Campanhas() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [providerFilter, setProviderFilter] = useState("all");

  const { data: campaigns = [], isLoading, error, refetch } = useQuery({
    queryKey: ['campanhas', statusFilter, providerFilter, search],
    queryFn: () => getCampanhas({
      status: statusFilter !== 'all' ? statusFilter : '',
      fornecedor: providerFilter !== 'all' ? providerFilter : '',
      search: search || '',
    }),
  });

  const filteredCampaigns = campaigns.filter((campaign: Campaign) => {
    const matchesSearch = campaign.name.toLowerCase().includes(search.toLowerCase());
    const matchesStatus = statusFilter === "all" || campaign.status === statusFilter;
    const matchesProvider = providerFilter === "all" || campaign.provider === providerFilter;
    return matchesSearch && matchesStatus && matchesProvider;
  });

  if (error) {
    toast({
      title: "Erro",
      description: "Erro ao carregar campanhas",
      variant: "destructive",
    });
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Minhas Campanhas"
        description="Visualize e gerencie todas as suas campanhas"
      >
        <Link to="/painel/nova-campanha">
          <Button className="gradient-primary hover:opacity-90">
            <Plus className="mr-2 h-4 w-4" />
            Nova Campanha
          </Button>
        </Link>
      </PageHeader>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Buscar campanhas..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-10"
              />
            </div>
            <div className="flex gap-3">
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-[160px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos Status</SelectItem>
                  <SelectItem value="pending">Pendente</SelectItem>
                  <SelectItem value="approved">Aprovado</SelectItem>
                  <SelectItem value="sent">Enviado</SelectItem>
                  <SelectItem value="denied">Negado</SelectItem>
                  <SelectItem value="scheduled">Agendado</SelectItem>
                </SelectContent>
              </Select>
              <Select value={providerFilter} onValueChange={setProviderFilter}>
                <SelectTrigger className="w-[160px]">
                  <SelectValue placeholder="Fornecedor" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos Fornecedores</SelectItem>
                  <SelectItem value="CDA">CDA</SelectItem>
                  <SelectItem value="GOSAC">GOSAC</SelectItem>
                  <SelectItem value="NOAH">NOAH</SelectItem>
                </SelectContent>
              </Select>
              <Button variant="outline">
                <Download className="mr-2 h-4 w-4" />
                Exportar
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Results */}
      <div className="space-y-4">
        {isLoading ? (
          <div className="space-y-2">
            {[1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : (
          <>
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                {filteredCampaigns.length} campanhas encontradas
              </p>
            </div>
            {filteredCampaigns.length > 0 ? (
              <CampaignTable campaigns={filteredCampaigns as Campaign[]} />
            ) : (
              <Card>
                <CardContent className="p-12 text-center">
                  <p className="text-muted-foreground">Nenhuma campanha encontrada</p>
                </CardContent>
              </Card>
            )}
          </>
        )}
      </div>
    </div>
  );
}
