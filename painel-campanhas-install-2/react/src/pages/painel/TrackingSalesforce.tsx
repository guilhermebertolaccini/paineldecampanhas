import { useState, useCallback, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  getSalesforceTracking,
  type SalesforceTrackingParams,
  type SalesforceTrackingRecord,
} from "@/lib/api";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Search,
  Download,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  Database,
  Phone,
  Calendar,
  RefreshCw,
  X,
} from "lucide-react";

const STATUS_COLORS: Record<string, "default" | "success" | "destructive" | "warning" | "info" | "secondary"> = {
  Delivered: "success",
  Sent: "info",
  Undelivered: "destructive",
  Rejected: "destructive",
  Pending: "warning",
  Bounced: "destructive",
  Queued: "secondary",
};

function getStatusVariant(status: string) {
  if (!status) return "secondary";
  for (const [key, variant] of Object.entries(STATUS_COLORS)) {
    if (status.toLowerCase().includes(key.toLowerCase())) return variant;
  }
  return "secondary";
}

function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) return "—";
  try {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleString("pt-BR", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return dateStr;
  }
}

function formatPhone(phone: string | null | undefined): string {
  if (!phone) return "—";
  const clean = phone.replace(/\D/g, "");
  if (clean.length === 13) return `+${clean.slice(0, 2)} (${clean.slice(2, 4)}) ${clean.slice(4, 9)}-${clean.slice(9)}`;
  if (clean.length === 11) return `(${clean.slice(0, 2)}) ${clean.slice(2, 7)}-${clean.slice(7)}`;
  return phone;
}

const PER_PAGE_OPTIONS = [25, 50, 100];

export default function TrackingSalesforce() {
  const [params, setParams] = useState<SalesforceTrackingParams>({
    page: 1,
    per_page: 50,
    search: "",
    status_filter: "",
    date_from: "",
    date_to: "",
  });
  const [searchInput, setSearchInput] = useState("");

  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ["salesforce-tracking", params],
    queryFn: () => getSalesforceTracking(params),
    placeholderData: (prev) => prev,
  });

  const records = data?.records ?? [];
  const totalCount = data?.total_count ?? 0;
  const totalPages = data?.total_pages ?? 0;
  const currentPage = data?.page ?? 1;
  const statuses = data?.statuses ?? [];

  const handleSearch = useCallback(() => {
    setParams((p) => ({ ...p, search: searchInput, page: 1 }));
  }, [searchInput]);

  const handleSearchKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === "Enter") handleSearch();
    },
    [handleSearch]
  );

  const handleClearFilters = useCallback(() => {
    setSearchInput("");
    setParams({ page: 1, per_page: params.per_page, search: "", status_filter: "", date_from: "", date_to: "" });
  }, [params.per_page]);

  const handleExportCsv = useCallback(() => {
    const pcAjax = (window as any).pcAjax;
    if (!pcAjax?.adminPostUrl || !pcAjax?.csvNonce) {
      alert("Erro: configuração de download não disponível. Recarregue a página.");
      return;
    }

    const form = document.createElement("form");
    form.method = "POST";
    form.action = pcAjax.adminPostUrl;
    form.target = "_blank";

    const fields: Record<string, string> = {
      action: "pc_download_salesforce_csv_file",
      _wpnonce: pcAjax.csvNonce,
      max_rows: "50000",
    };
    if (params.search) fields.search = params.search;
    if (params.status_filter) fields.status_filter = params.status_filter;
    if (params.date_from) fields.date_from = params.date_from;
    if (params.date_to) fields.date_to = params.date_to;

    Object.entries(fields).forEach(([key, val]) => {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = key;
      input.value = val;
      form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
  }, [params]);

  const goToPage = useCallback((page: number) => {
    setParams((p) => ({ ...p, page: Math.max(1, Math.min(page, totalPages || 1)) }));
  }, [totalPages]);

  const hasActiveFilters = !!(params.search || params.status_filter || params.date_from || params.date_to);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
            <Database className="h-6 w-6 text-primary" />
            Tracking Salesforce
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            Monitoramento de eventos da Salesforce Marketing Cloud
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isFetching}>
            <RefreshCw className={`h-4 w-4 mr-1 ${isFetching ? "animate-spin" : ""}`} />
            Atualizar
          </Button>
          <Button variant="default" size="sm" onClick={handleExportCsv} disabled={totalCount === 0}>
            <Download className="h-4 w-4 mr-1" />
            Exportar CSV
          </Button>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-muted-foreground font-medium">Total de Registros</p>
            <p className="text-2xl font-bold text-foreground">
              {isLoading ? <Skeleton className="h-8 w-20" /> : totalCount.toLocaleString("pt-BR")}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-muted-foreground font-medium">Página Atual</p>
            <p className="text-2xl font-bold text-foreground">
              {isLoading ? <Skeleton className="h-8 w-20" /> : `${currentPage} / ${totalPages}`}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-muted-foreground font-medium">Registros na Página</p>
            <p className="text-2xl font-bold text-foreground">
              {isLoading ? <Skeleton className="h-8 w-20" /> : records.length}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-muted-foreground font-medium">Status Distintos</p>
            <p className="text-2xl font-bold text-foreground">
              {isLoading ? <Skeleton className="h-8 w-20" /> : statuses.length}
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Filters */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filtros</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
            <div className="lg:col-span-2 flex gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar telefone, nome, CPF, contactkey..."
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  onKeyDown={handleSearchKeyDown}
                  className="pl-9"
                />
              </div>
              <Button size="icon" variant="default" onClick={handleSearch}>
                <Search className="h-4 w-4" />
              </Button>
            </div>
            <Select
              value={params.status_filter || "all"}
              onValueChange={(v) => setParams((p) => ({ ...p, status_filter: v === "all" ? "" : v, page: 1 }))}
            >
              <SelectTrigger>
                <SelectValue placeholder="Filtrar por status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos os Status</SelectItem>
                {statuses.map((s) => (
                  <SelectItem key={s} value={s}>{s}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <div className="flex items-center gap-1">
              <Calendar className="h-4 w-4 text-muted-foreground shrink-0" />
              <Input
                type="date"
                value={params.date_from || ""}
                onChange={(e) => setParams((p) => ({ ...p, date_from: e.target.value, page: 1 }))}
                className="text-xs"
              />
            </div>
            <div className="flex items-center gap-1">
              <span className="text-xs text-muted-foreground shrink-0">até</span>
              <Input
                type="date"
                value={params.date_to || ""}
                onChange={(e) => setParams((p) => ({ ...p, date_to: e.target.value, page: 1 }))}
                className="text-xs"
              />
              {hasActiveFilters && (
                <Button variant="ghost" size="icon" onClick={handleClearFilters} className="shrink-0">
                  <X className="h-4 w-4" />
                </Button>
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Data Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[80px]">ID</TableHead>
                  <TableHead>Telefone</TableHead>
                  <TableHead>Nome</TableHead>
                  <TableHead>CPF/CNPJ</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Tipo Tracking</TableHead>
                  <TableHead>Atividade</TableHead>
                  <TableHead>Canal</TableHead>
                  <TableHead>Operação</TableHead>
                  <TableHead>Motivo</TableHead>
                  <TableHead className="w-[150px]">Data Evento</TableHead>
                  <TableHead className="w-[150px]">Criado Em</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading ? (
                  Array.from({ length: 10 }).map((_, i) => (
                    <TableRow key={i}>
                      {Array.from({ length: 12 }).map((_, j) => (
                        <TableCell key={j}><Skeleton className="h-4 w-full" /></TableCell>
                      ))}
                    </TableRow>
                  ))
                ) : records.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={12} className="h-32 text-center">
                      <div className="flex flex-col items-center gap-2 text-muted-foreground">
                        <Database className="h-8 w-8 opacity-50" />
                        <p className="font-medium">Nenhum registro encontrado</p>
                        {hasActiveFilters && (
                          <Button variant="link" size="sm" onClick={handleClearFilters}>
                            Limpar filtros
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  records.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="font-mono text-xs">{row.id}</TableCell>
                      <TableCell>
                        <span className="flex items-center gap-1">
                          <Phone className="h-3 w-3 text-muted-foreground shrink-0" />
                          <span className="text-xs font-mono">{formatPhone(row.mobilenumber)}</span>
                        </span>
                      </TableCell>
                      <TableCell className="text-sm max-w-[150px] truncate" title={row.name || ""}>
                        {row.name || "—"}
                      </TableCell>
                      <TableCell className="font-mono text-xs">{row.cpf_cnpj__c || "—"}</TableCell>
                      <TableCell>
                        <Badge variant={getStatusVariant(row.status)}>{row.status || "—"}</Badge>
                      </TableCell>
                      <TableCell className="text-xs">{row.trackingtype || "—"}</TableCell>
                      <TableCell className="text-xs max-w-[120px] truncate" title={row.activityname || ""}>
                        {row.activityname || "—"}
                      </TableCell>
                      <TableCell className="text-xs">{row.channeltype || "—"}</TableCell>
                      <TableCell className="text-xs">{row.operacao__c || "—"}</TableCell>
                      <TableCell className="text-xs max-w-[150px] truncate" title={row.reason || ""}>
                        {row.reason || "—"}
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">{formatDate(row.eventdateutc)}</TableCell>
                      <TableCell className="text-xs whitespace-nowrap">{formatDate(row.criado_em)}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          {/* Pagination */}
          {totalPages > 0 && (
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 border-t px-4 py-3">
              <div className="flex items-center gap-3 text-sm text-muted-foreground">
                <span>
                  Mostrando {((currentPage - 1) * (params.per_page || 50)) + 1}–
                  {Math.min(currentPage * (params.per_page || 50), totalCount)} de{" "}
                  {totalCount.toLocaleString("pt-BR")} registros
                </span>
                <Select
                  value={String(params.per_page || 50)}
                  onValueChange={(v) => setParams((p) => ({ ...p, per_page: parseInt(v), page: 1 }))}
                >
                  <SelectTrigger className="w-[110px] h-8">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PER_PAGE_OPTIONS.map((n) => (
                      <SelectItem key={n} value={String(n)}>{n} por página</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-1">
                <Button
                  variant="outline" size="icon" className="h-8 w-8"
                  onClick={() => goToPage(1)} disabled={currentPage <= 1}
                >
                  <ChevronsLeft className="h-4 w-4" />
                </Button>
                <Button
                  variant="outline" size="icon" className="h-8 w-8"
                  onClick={() => goToPage(currentPage - 1)} disabled={currentPage <= 1}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <div className="flex items-center gap-1 px-2">
                  <span className="text-sm font-medium">{currentPage}</span>
                  <span className="text-sm text-muted-foreground">de</span>
                  <span className="text-sm font-medium">{totalPages}</span>
                </div>
                <Button
                  variant="outline" size="icon" className="h-8 w-8"
                  onClick={() => goToPage(currentPage + 1)} disabled={currentPage >= totalPages}
                >
                  <ChevronRight className="h-4 w-4" />
                </Button>
                <Button
                  variant="outline" size="icon" className="h-8 w-8"
                  onClick={() => goToPage(totalPages)} disabled={currentPage >= totalPages}
                >
                  <ChevronsRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
