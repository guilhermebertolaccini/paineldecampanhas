import { useState, useCallback } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  getEnviosPendentes, getEventosEnvios, getEventosIndicadores,
  getEventosTempos, getReportSummary,
} from "@/lib/api";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import {
  Search, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight,
  BarChart3, Calendar, RefreshCw, Send, AlertTriangle,
  CheckCircle, Clock, TrendingUp, Gauge, Timer,
} from "lucide-react";

// ─── Helpers ─────────────────────────────────────────────

const STATUS_MAP: Record<string, { variant: "success" | "destructive" | "warning" | "info" | "secondary"; label: string }> = {
  enviado: { variant: "success", label: "Enviado" },
  mkc_executado: { variant: "success", label: "MKC Executado" },
  pendente: { variant: "warning", label: "Pendente" },
  processando: { variant: "info", label: "Processando" },
  pendente_aprovacao: { variant: "warning", label: "Pend. Aprovação" },
  agendado_mkc: { variant: "info", label: "Agendado MKC" },
  erro: { variant: "destructive", label: "Erro" },
  erro_envio: { variant: "destructive", label: "Erro Envio" },
  negado: { variant: "destructive", label: "Negado" },
  concluido: { variant: "success", label: "Concluído" },
};

function StatusBadge({ status }: { status: string }) {
  const m = STATUS_MAP[status?.toLowerCase()];
  return <Badge variant={m?.variant ?? "secondary"}>{m?.label ?? (status || "—")}</Badge>;
}

function fmt(d: string | null) {
  if (!d) return "—";
  try {
    return new Date(d).toLocaleString("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
  } catch { return d; }
}

function fmtNum(n: number | string) {
  return Number(n || 0).toLocaleString("pt-BR");
}

function isDateValue(val: string) {
  if (!val || val.length < 10) return false;
  return /^\d{4}-\d{2}-\d{2}/.test(val);
}

function isStatusCol(col: string) {
  return /^status/i.test(col);
}

function renderCell(col: string, val: any) {
  if (val === null || val === undefined || val === "") return "—";
  const s = String(val);
  if (isStatusCol(col)) return <StatusBadge status={s} />;
  if (isDateValue(s)) return fmt(s);
  return s;
}

const firstOfMonth = () => {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-01`;
};
const today = () => {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
};

const PP = [25, 50, 100];

// ─── Pagination ──────────────────────────────────────────

function Pager({ page, totalPages, total, perPage, onPage, onPerPage }: {
  page: number; totalPages: number; total: number; perPage: number;
  onPage: (p: number) => void; onPerPage: (pp: number) => void;
}) {
  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 border-t px-4 py-3">
      <div className="flex items-center gap-3 text-sm text-muted-foreground">
        <span>{fmtNum(((page - 1) * perPage) + 1)}–{fmtNum(Math.min(page * perPage, total))} de {fmtNum(total)}</span>
        <Select value={String(perPage)} onValueChange={(v) => onPerPage(parseInt(v))}>
          <SelectTrigger className="w-[110px] h-8"><SelectValue /></SelectTrigger>
          <SelectContent>{PP.map((n) => <SelectItem key={n} value={String(n)}>{n} / pág</SelectItem>)}</SelectContent>
        </Select>
      </div>
      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => onPage(1)} disabled={page <= 1}><ChevronsLeft className="h-4 w-4" /></Button>
        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => onPage(page - 1)} disabled={page <= 1}><ChevronLeft className="h-4 w-4" /></Button>
        <span className="text-sm px-2">{page} <span className="text-muted-foreground">de</span> {totalPages}</span>
        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => onPage(page + 1)} disabled={page >= totalPages}><ChevronRight className="h-4 w-4" /></Button>
        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => onPage(totalPages)} disabled={page >= totalPages}><ChevronsRight className="h-4 w-4" /></Button>
      </div>
    </div>
  );
}

// ─── Generic Dynamic Table Tab ───────────────────────────

function DynamicTableTab({ queryKey, fetchFn, label }: {
  queryKey: string;
  fetchFn: (params: Record<string, any>) => Promise<any>;
  label: string;
}) {
  const [p, setP] = useState<Record<string, any>>({ page: 1, per_page: 50, search: "", col_filter: "", col_filter_val: "", date_from: "", date_to: "" });
  const [si, setSi] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: [queryKey, p],
    queryFn: () => fetchFn(p),
    placeholderData: (prev: any) => prev,
  });

  const rows: any[] = data?.records ?? [];
  const total: number = data?.total_count ?? 0;
  const tp: number = data?.total_pages ?? 0;
  const exists: boolean = data?.table_exists !== false;
  const columns: string[] = data?.columns ?? [];
  const filterOptions: Record<string, string[]> = data?.filter_options ?? {};
  const dateColumns: string[] = data?.date_columns ?? [];

  const filterableCols = Object.keys(filterOptions).filter((k) => filterOptions[k].length > 0 && filterOptions[k].length <= 100);

  const go = useCallback(() => setP((x: any) => ({ ...x, search: si, page: 1 })), [si]);

  const displayCols = columns.length > 0 ? columns : (rows.length > 0 ? Object.keys(rows[0]) : []);

  if (!exists && !isLoading) {
    return (
      <Card><CardContent className="p-8 text-center">
        <AlertTriangle className="h-10 w-10 text-amber-500 mx-auto mb-3" />
        <p className="text-lg font-medium">Tabela {label} não encontrada</p>
        <p className="text-sm text-muted-foreground mt-1">A tabela não existe no banco de dados.</p>
      </CardContent></Card>
    );
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3">
        <div className="lg:col-span-2 flex gap-2">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input placeholder="Buscar..." value={si} onChange={(e) => setSi(e.target.value)} onKeyDown={(e) => e.key === "Enter" && go()} className="pl-9" />
          </div>
          <Button size="icon" onClick={go}><Search className="h-4 w-4" /></Button>
        </div>

        {filterableCols.length > 0 && (
          <>
            <Select value={p.col_filter || "none"} onValueChange={(v) => setP((x: any) => ({ ...x, col_filter: v === "none" ? "" : v, col_filter_val: "", page: 1 }))}>
              <SelectTrigger><SelectValue placeholder="Filtrar coluna" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="none">Sem filtro</SelectItem>
                {filterableCols.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
              </SelectContent>
            </Select>

            {p.col_filter && filterOptions[p.col_filter] && (
              <Select value={p.col_filter_val || "all"} onValueChange={(v) => setP((x: any) => ({ ...x, col_filter_val: v === "all" ? "" : v, page: 1 }))}>
                <SelectTrigger><SelectValue placeholder="Valor" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  {filterOptions[p.col_filter].map((v: string) => <SelectItem key={v} value={v}>{v}</SelectItem>)}
                </SelectContent>
              </Select>
            )}
          </>
        )}

        {dateColumns.length > 0 && (
          <>
            <Input type="date" value={p.date_from} onChange={(e) => setP((x: any) => ({ ...x, date_from: e.target.value, page: 1 }))} className="text-xs" />
            <Input type="date" value={p.date_to} onChange={(e) => setP((x: any) => ({ ...x, date_to: e.target.value, page: 1 }))} className="text-xs" />
          </>
        )}
      </div>

      {dateColumns.length > 0 && (
        <p className="text-xs text-muted-foreground">Filtro de data aplica na coluna: <code className="bg-muted px-1 rounded">{dateColumns[0]}</code></p>
      )}

      <Card><CardContent className="p-0"><div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              {displayCols.map((c) => <TableHead key={c} className="whitespace-nowrap text-xs">{c}</TableHead>)}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? Array.from({ length: 6 }).map((_, i) => (
              <TableRow key={i}>{displayCols.map((_, j) => <TableCell key={j}><Skeleton className="h-4 w-full" /></TableCell>)}</TableRow>
            )) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={displayCols.length || 5} className="h-24 text-center text-muted-foreground">Nenhum registro encontrado</TableCell>
              </TableRow>
            ) : rows.map((r: any, i: number) => (
              <TableRow key={r.id ?? i}>
                {displayCols.map((c) => (
                  <TableCell key={c} className="text-xs max-w-[200px] truncate" title={String(r[c] ?? "")}>
                    {renderCell(c, r[c])}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {tp > 0 && (
        <Pager page={p.page} totalPages={tp} total={total} perPage={p.per_page}
          onPage={(pg) => setP((x: any) => ({ ...x, page: pg }))}
          onPerPage={(pp) => setP((x: any) => ({ ...x, per_page: pp, page: 1 }))} />
      )}
      </CardContent></Card>
    </div>
  );
}

// ─── Tab: Envios Pendentes ───────────────────────────────

function TabEnvios() {
  const [p, setP] = useState<Record<string, any>>({ page: 1, per_page: 50, search: "", status_filter: "", fornecedor_filter: "", date_from: firstOfMonth(), date_to: today(), agendamento_filter: "" });
  const [si, setSi] = useState("");

  const { data, isLoading } = useQuery({ queryKey: ["rpt-envios", p], queryFn: () => getEnviosPendentes(p), placeholderData: (prev: any) => prev });
  const rows = data?.records ?? [];
  const total = data?.total_count ?? 0;
  const tp = data?.total_pages ?? 0;
  const statuses = data?.statuses ?? [];
  const fornecedores = data?.fornecedores ?? [];

  const go = useCallback(() => setP((x: any) => ({ ...x, search: si, page: 1 })), [si]);

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3">
        <div className="lg:col-span-2 flex gap-2">
          <div className="relative flex-1"><Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input placeholder="Telefone, nome, CPF..." value={si} onChange={(e) => setSi(e.target.value)} onKeyDown={(e) => e.key === "Enter" && go()} className="pl-9" /></div>
          <Button size="icon" onClick={go}><Search className="h-4 w-4" /></Button>
        </div>
        <Select value={p.status_filter || "all"} onValueChange={(v) => setP((x: any) => ({ ...x, status_filter: v === "all" ? "" : v, page: 1 }))}>
          <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
          <SelectContent><SelectItem value="all">Todos Status</SelectItem>{statuses.map((s: string) => <SelectItem key={s} value={s}>{s}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={p.fornecedor_filter || "all"} onValueChange={(v) => setP((x: any) => ({ ...x, fornecedor_filter: v === "all" ? "" : v, page: 1 }))}>
          <SelectTrigger><SelectValue placeholder="Fornecedor" /></SelectTrigger>
          <SelectContent><SelectItem value="all">Todos Fornecedores</SelectItem>{fornecedores.map((f: string) => <SelectItem key={f} value={f}>{f}</SelectItem>)}</SelectContent>
        </Select>
        <Input type="date" value={p.date_from} onChange={(e) => setP((x: any) => ({ ...x, date_from: e.target.value, page: 1 }))} className="text-xs" />
        <Input type="date" value={p.date_to} onChange={(e) => setP((x: any) => ({ ...x, date_to: e.target.value, page: 1 }))} className="text-xs" />
      </div>
      <Card><CardContent className="p-0"><div className="overflow-x-auto">
        <Table>
          <TableHeader><TableRow>
            <TableHead className="w-[70px]">ID</TableHead><TableHead>Telefone</TableHead><TableHead>Nome</TableHead>
            <TableHead>Status</TableHead><TableHead>Fornecedor</TableHead><TableHead>Agendamento</TableHead>
            <TableHead>Ambiente</TableHead><TableHead>Cadastro</TableHead><TableHead>Disparo</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {isLoading ? Array.from({ length: 6 }).map((_, i) => (
              <TableRow key={i}>{Array.from({ length: 9 }).map((_, j) => <TableCell key={j}><Skeleton className="h-4 w-full" /></TableCell>)}</TableRow>
            )) : rows.length === 0 ? (
              <TableRow><TableCell colSpan={9} className="h-24 text-center text-muted-foreground">Nenhum registro encontrado</TableCell></TableRow>
            ) : rows.map((r: any) => (
              <TableRow key={r.id}>
                <TableCell className="font-mono text-xs">{r.id}</TableCell>
                <TableCell className="font-mono text-xs">{r.telefone || "—"}</TableCell>
                <TableCell className="text-sm max-w-[140px] truncate">{r.nome || "—"}</TableCell>
                <TableCell><StatusBadge status={r.status} /></TableCell>
                <TableCell className="text-xs">{r.fornecedor || "—"}</TableCell>
                <TableCell className="font-mono text-xs">{r.agendamento_id || "—"}</TableCell>
                <TableCell className="text-xs">{r.idgis_ambiente || "—"}</TableCell>
                <TableCell className="text-xs whitespace-nowrap">{fmt(r.data_cadastro)}</TableCell>
                <TableCell className="text-xs whitespace-nowrap">{fmt(r.data_disparo)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table></div>
        {tp > 0 && <Pager page={p.page} totalPages={tp} total={total} perPage={p.per_page} onPage={(pg) => setP((x: any) => ({ ...x, page: pg }))} onPerPage={(pp) => setP((x: any) => ({ ...x, per_page: pp, page: 1 }))} />}
      </CardContent></Card>
    </div>
  );
}

// ─── Tab: Resumo ─────────────────────────────────────────

function TabResumo() {
  const [dateFrom, setDateFrom] = useState(firstOfMonth());
  const [dateTo, setDateTo] = useState(today());

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["report-summary", dateFrom, dateTo],
    queryFn: () => getReportSummary({ date_from: dateFrom, date_to: dateTo }),
  });

  const byStatus = data?.by_status ?? [];
  const byProvider = data?.by_provider ?? [];
  const daily = data?.daily ?? [];
  const totalRecords = data?.total_records ?? 0;

  const totalEnviados = byStatus.find((s: any) => s.status === "enviado")?.total ?? 0;
  const totalErros = byStatus.filter((s: any) => ["erro", "erro_envio", "negado"].includes(s.status)).reduce((a: number, s: any) => a + Number(s.total), 0);
  const totalPendentes = byStatus.filter((s: any) => ["pendente", "pendente_aprovacao", "processando"].includes(s.status)).reduce((a: number, s: any) => a + Number(s.total), 0);

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3 flex-wrap">
        <Calendar className="h-4 w-4 text-muted-foreground" />
        <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
        <span className="text-sm text-muted-foreground">até</span>
        <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
        <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isLoading}>
          <RefreshCw className={`h-4 w-4 mr-1 ${isLoading ? "animate-spin" : ""}`} /> Atualizar
        </Button>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { icon: Send, label: "Total Disparos", value: totalRecords, color: "text-primary" },
          { icon: CheckCircle, label: "Enviados", value: totalEnviados, color: "text-green-600" },
          { icon: AlertTriangle, label: "Erros", value: totalErros, color: "text-red-600" },
          { icon: Clock, label: "Pendentes", value: totalPendentes, color: "text-amber-600" },
        ].map(({ icon: Icon, label, value, color }) => (
          <Card key={label}><CardContent className="p-4">
            <div className="flex items-center gap-2 mb-1"><Icon className={`h-4 w-4 ${color}`} /><span className="text-xs text-muted-foreground font-medium">{label}</span></div>
            <p className={`text-2xl font-bold ${color}`}>{isLoading ? <Skeleton className="h-8 w-20" /> : fmtNum(value)}</p>
          </CardContent></Card>
        ))}
      </div>

      <div className="grid md:grid-cols-2 gap-6">
        <Card>
          <CardHeader><CardTitle className="text-base">Por Status</CardTitle></CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-40" /> : byStatus.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">Sem dados</p>
            ) : (
              <div className="space-y-2">{byStatus.map((s: any) => {
                const pct = totalRecords > 0 ? (Number(s.total) / totalRecords * 100) : 0;
                return (
                  <div key={s.status} className="flex items-center gap-3">
                    <div className="w-32 text-xs truncate"><StatusBadge status={s.status} /></div>
                    <div className="flex-1 bg-muted rounded-full h-2 overflow-hidden"><div className="h-full bg-primary rounded-full transition-all" style={{ width: `${pct}%` }} /></div>
                    <span className="text-xs font-mono w-16 text-right">{fmtNum(s.total)}</span>
                  </div>
                );
              })}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className="text-base">Por Fornecedor</CardTitle></CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-40" /> : byProvider.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">Sem dados</p>
            ) : (
              <div className="space-y-3">{byProvider.map((pv: any) => (
                <div key={pv.fornecedor} className="flex items-center justify-between border-b pb-2 last:border-0">
                  <div>
                    <p className="text-sm font-medium">{pv.fornecedor || "N/A"}</p>
                    <p className="text-xs text-muted-foreground">{fmtNum(pv.total)} disparos</p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="text-green-600">{fmtNum(pv.enviados)} ok</span>
                    <span className="text-red-600">{fmtNum(pv.erros)} err</span>
                  </div>
                </div>
              ))}</div>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base flex items-center gap-2"><TrendingUp className="h-4 w-4" /> Evolução Diária (últimos 30 dias)</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? <Skeleton className="h-48" /> : daily.length === 0 ? (
            <p className="text-sm text-muted-foreground text-center py-6">Sem dados no período</p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader><TableRow>
                  <TableHead>Data</TableHead><TableHead className="text-right">Total</TableHead><TableHead className="text-right">Enviados</TableHead><TableHead className="text-right">Taxa</TableHead><TableHead>Barra</TableHead>
                </TableRow></TableHeader>
                <TableBody>{daily.map((d: any) => {
                  const taxa = Number(d.total) > 0 ? (Number(d.enviados) / Number(d.total) * 100) : 0;
                  return (
                    <TableRow key={d.dia}>
                      <TableCell className="text-sm">{d.dia}</TableCell>
                      <TableCell className="text-right font-mono text-sm">{fmtNum(d.total)}</TableCell>
                      <TableCell className="text-right font-mono text-sm text-green-600">{fmtNum(d.enviados)}</TableCell>
                      <TableCell className="text-right text-sm">{taxa.toFixed(1)}%</TableCell>
                      <TableCell><div className="w-32 bg-muted rounded-full h-2"><div className="h-full bg-green-500 rounded-full" style={{ width: `${taxa}%` }} /></div></TableCell>
                    </TableRow>
                  );
                })}</TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

// ─── Main ────────────────────────────────────────────────

export default function RelatoriosDetalhados() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
          <BarChart3 className="h-6 w-6 text-primary" /> Relatórios Detalhados
        </h1>
        <p className="text-sm text-muted-foreground mt-1">Dados detalhados de disparos, eventos, indicadores e tempos</p>
      </div>

      <Tabs defaultValue="summary" className="space-y-4">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="summary" className="flex items-center gap-1.5 text-xs sm:text-sm"><TrendingUp className="h-4 w-4" /><span className="hidden sm:inline">Resumo</span></TabsTrigger>
          <TabsTrigger value="envios" className="flex items-center gap-1.5 text-xs sm:text-sm"><Send className="h-4 w-4" /><span className="hidden sm:inline">Envios</span></TabsTrigger>
          <TabsTrigger value="eventos" className="flex items-center gap-1.5 text-xs sm:text-sm"><BarChart3 className="h-4 w-4" /><span className="hidden sm:inline">Eventos</span></TabsTrigger>
          <TabsTrigger value="indicadores" className="flex items-center gap-1.5 text-xs sm:text-sm"><Gauge className="h-4 w-4" /><span className="hidden sm:inline">Indicadores</span></TabsTrigger>
          <TabsTrigger value="tempos" className="flex items-center gap-1.5 text-xs sm:text-sm"><Timer className="h-4 w-4" /><span className="hidden sm:inline">Tempos</span></TabsTrigger>
        </TabsList>

        <TabsContent value="summary"><TabResumo /></TabsContent>
        <TabsContent value="envios"><TabEnvios /></TabsContent>
        <TabsContent value="eventos">
          <DynamicTableTab queryKey="rpt-ev-envios" fetchFn={getEventosEnvios} label="wp_eventos_envios" />
        </TabsContent>
        <TabsContent value="indicadores">
          <DynamicTableTab queryKey="rpt-indicadores" fetchFn={getEventosIndicadores} label="wp_eventos_indicadores" />
        </TabsContent>
        <TabsContent value="tempos">
          <DynamicTableTab queryKey="rpt-tempos" fetchFn={getEventosTempos} label="wp_eventos_tempos" />
        </TabsContent>
      </Tabs>
    </div>
  );
}
