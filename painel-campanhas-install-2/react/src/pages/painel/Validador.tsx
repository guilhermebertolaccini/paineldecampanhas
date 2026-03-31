import { useCallback, useEffect, useRef, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { useToast } from "@/hooks/use-toast";
import {
  fetchValidadorMetricas,
  nestValidatorUpload,
  nestValidatorHistory,
  nestValidatorDownloadBlob,
} from "@/lib/api";
import { Upload, FileSpreadsheet, Loader2, Download, AlertCircle, BarChart3, ChevronDown, History, FileDown } from "lucide-react";
import { cn } from "@/lib/utils";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

function localTodayISO(): string {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

type MetricRow = {
  usuario_id: number;
  usuario_nome: string;
  total_enviado: number;
  total_validos: number;
  taxa_qualidade_pct: number;
};

type HistoricoItem = {
  id: string;
  nome_arquivo: string;
  total_linhas: number;
  linhas_validas: number;
  linhas_invalidas: number;
  data_criacao: string;
};

function formatHistoricoDataUtc(isoMysql: string): string {
  if (!isoMysql) return "—";
  const normalized = isoMysql.includes("T") ? isoMysql : isoMysql.replace(" ", "T") + "Z";
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return isoMysql;
  return d.toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });
}

export default function Validador() {
  const { toast } = useToast();
  const busyRef = useRef(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [statusText, setStatusText] = useState("");
  const [lastResult, setLastResult] = useState<{
    id: string;
    nomeArquivo: string;
    totalLinhas: number;
    linhasValidas: number;
    linhasInvalidas: number;
  } | null>(null);

  const isAdmin = !!(typeof window !== "undefined" && (window as any).pcAjax?.currentUser?.isAdmin);

  const [metricsOpen, setMetricsOpen] = useState(isAdmin);
  const [dataInicio, setDataInicio] = useState(localTodayISO);
  const [dataFim, setDataFim] = useState(localTodayISO);
  const [metricsLoading, setMetricsLoading] = useState(false);
  const [metricsRows, setMetricsRows] = useState<MetricRow[]>([]);
  const [metricsTz, setMetricsTz] = useState<string>("");
  const [metricsError, setMetricsError] = useState<string | null>(null);

  const [historicoItems, setHistoricoItems] = useState<HistoricoItem[]>([]);
  const [historicoLoading, setHistoricoLoading] = useState(false);
  const [historicoError, setHistoricoError] = useState<string | null>(null);

  const loadHistorico = useCallback(async () => {
    const uid = Number((window as any).pcAjax?.currentUser?.id);
    if (!uid) {
      setHistoricoItems([]);
      return;
    }
    setHistoricoLoading(true);
    setHistoricoError(null);
    try {
      const data = await nestValidatorHistory(uid);
      setHistoricoItems(data.itens);
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      setHistoricoError(msg);
      setHistoricoItems([]);
    } finally {
      setHistoricoLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadHistorico();
  }, [loadHistorico]);

  const loadMetrics = useCallback(async () => {
    if (!isAdmin) return;
    setMetricsLoading(true);
    setMetricsError(null);
    try {
      const data = await fetchValidadorMetricas(dataInicio, dataFim);
      setMetricsRows(data.linhas);
      setMetricsTz(data.periodo.timezone || "");
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      setMetricsError(msg);
      setMetricsRows([]);
    } finally {
      setMetricsLoading(false);
    }
  }, [isAdmin, dataInicio, dataFim]);

  // Carga inicial com o período padrão (hoje); mudanças de data exigem "Atualizar".
  useEffect(() => {
    if (!isAdmin) return;
    void loadMetrics();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- só ao montar / quando vira admin
  }, [isAdmin]);

  const resetOutput = () => {
    setLastResult(null);
  };

  const triggerBlobDownload = (blob: Blob, filename: string) => {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.rel = "nofollow";
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  const downloadFromNest = useCallback(
    async (rowId: string, nomeArquivo: string, type: "original" | "validated") => {
      const uid = Number((window as any).pcAjax?.currentUser?.id);
      if (!uid) {
        toast({ title: "Sessão", description: "Usuário não identificado.", variant: "destructive" });
        return;
      }
      try {
        const blob = await nestValidatorDownloadBlob(uid, rowId, type);
        const name =
          type === "original"
            ? nomeArquivo.endsWith(".csv") || nomeArquivo.endsWith(".txt")
              ? nomeArquivo
              : `${nomeArquivo}.csv`
            : `${nomeArquivo.replace(/\.(csv|txt)$/i, "")}-validado.csv`;
        triggerBlobDownload(blob, name);
      } catch (e: unknown) {
        const msg = e instanceof Error ? e.message : String(e);
        toast({ title: "Download", description: msg, variant: "destructive" });
      }
    },
    [toast]
  );

  const runPipeline = useCallback(
    async (file: File) => {
      if (busyRef.current) return;
      const lower = file.name.toLowerCase();
      if (!lower.endsWith(".csv") && !lower.endsWith(".txt")) {
        toast({
          title: "Formato inválido",
          description: "Envie arquivos .csv ou .txt",
          variant: "destructive",
        });
        return;
      }
      const wpUserId = Number((window as any).pcAjax?.currentUser?.id);
      if (!wpUserId) {
        toast({
          title: "Sessão",
          description: "Não foi possível identificar seu usuário. Faça login novamente.",
          variant: "destructive",
        });
        return;
      }
      busyRef.current = true;
      setProcessing(true);
      resetOutput();
      setStatusText("Enviando e processando no microserviço… pode levar vários minutos.");

      try {
        const up = await nestValidatorUpload(file, wpUserId);
        setLastResult({
          id: up.id,
          nomeArquivo: up.nomeArquivo,
          totalLinhas: up.totalLinhas,
          linhasValidas: up.linhasValidas,
          linhasInvalidas: up.linhasInvalidas,
        });
        setStatusText(
          `Concluído: ${up.totalLinhas.toLocaleString("pt-BR")} linhas — ${up.linhasValidas.toLocaleString("pt-BR")} com WPP.`
        );
        toast({ title: "Validação concluída" });
        void loadHistorico();
        if (isAdmin) {
          void loadMetrics();
        }
      } catch (e: unknown) {
        const msg = e instanceof Error ? e.message : String(e);
        toast({
          title: "Erro na validação",
          description: msg,
          variant: "destructive",
        });
        setStatusText("");
        resetOutput();
      } finally {
        setProcessing(false);
        busyRef.current = false;
      }
    },
    [toast, isAdmin, loadMetrics, loadHistorico]
  );

  const onFile = (f: File | null | undefined) => {
    if (!f) return;
    void runPipeline(f);
  };

  return (
    <div className="space-y-8 max-w-5xl mx-auto">
      <PageHeader
        title="Validador WhatsApp"
        description="Processamento no microserviço NestJS (streams + Evolution API). Histórico e arquivos ficam no servidor Node por 15 dias."
      />

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg">
            <FileSpreadsheet className="h-5 w-5 text-primary" />
            Upload do CSV
          </CardTitle>
          <CardDescription>
            Coluna obrigatória <strong>TELEFONE</strong>. Formatos <strong>.csv</strong> ou <strong>.txt</strong>. Configure URL e API Key do microserviço em{" "}
            <strong>API Manager</strong> e variáveis <code className="text-xs">EVOLUTION_API_URL</code> /{" "}
            <code className="text-xs">EVOLUTION_API_TOKEN</code> no Nest.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <input
            ref={inputRef}
            type="file"
            accept=".csv,.txt,text/csv,text/plain"
            className="hidden"
            disabled={processing}
            onChange={(e) => {
              onFile(e.target.files?.[0]);
              e.target.value = "";
            }}
          />

          <div
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                if (!processing) inputRef.current?.click();
              }
            }}
            onDragOver={(e) => {
              e.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => {
              e.preventDefault();
              setDragOver(false);
              if (processing) return;
              const f = e.dataTransfer.files?.[0];
              if (f) onFile(f);
            }}
            className={cn(
              "border-2 border-dashed rounded-xl p-10 text-center transition-colors cursor-pointer",
              dragOver ? "border-primary bg-primary/5" : "border-muted-foreground/25 hover:border-primary/50",
              processing && "opacity-60 pointer-events-none"
            )}
            onClick={() => !processing && inputRef.current?.click()}
          >
            <Upload className="h-10 w-10 mx-auto text-muted-foreground mb-3" />
            <p className="text-sm text-muted-foreground">
              Arraste o CSV aqui ou clique para selecionar
            </p>
          </div>

          <Button type="button" disabled={processing} onClick={() => inputRef.current?.click()} className="w-full sm:w-auto">
            {processing ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Processando…
              </>
            ) : (
              <>
                <Upload className="mr-2 h-4 w-4" />
                Selecionar arquivo
              </>
            )}
          </Button>

          {processing && (
            <div className="space-y-2 pt-2">
              <Progress value={33} className="h-2 animate-pulse" />
              <p className="text-sm text-muted-foreground flex items-start gap-2">
                <Loader2 className="h-4 w-4 shrink-0 animate-spin mt-0.5" />
                {statusText}
              </p>
            </div>
          )}

          {!processing && lastResult && (
            <div className="flex flex-col gap-2 pt-4 border-t">
              <p className="text-sm text-muted-foreground">{statusText}</p>
              <div className="flex flex-col sm:flex-row gap-3">
                <Button
                  type="button"
                  variant="outline"
                  className="gap-2"
                  onClick={() => void downloadFromNest(lastResult.id, lastResult.nomeArquivo, "original")}
                >
                  <FileDown className="h-4 w-4" />
                  Baixar original
                </Button>
                <Button
                  type="button"
                  variant="default"
                  className="gap-2"
                  onClick={() => void downloadFromNest(lastResult.id, lastResult.nomeArquivo, "validated")}
                >
                  <Download className="h-4 w-4" />
                  Baixar validado
                </Button>
              </div>
            </div>
          )}

          <div className="flex gap-2 text-xs text-muted-foreground pt-2">
            <AlertCircle className="h-4 w-4 shrink-0" />
            <span>
              O processamento ocorre no NestJS; a Evolution API é chamada pelo servidor Node (não pelo WordPress).
            </span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg">
            <History className="h-5 w-5 text-primary" />
            Histórico de validações (últimos 15 dias — NestJS)
          </CardTitle>
          <CardDescription>
            Lista servida por <code className="text-xs bg-muted px-1 rounded">GET /validator/history</code>. Limpeza diária às 02:00 no servidor Node.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex justify-end">
            <Button type="button" variant="outline" size="sm" onClick={() => void loadHistorico()} disabled={historicoLoading}>
              {historicoLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
              Atualizar lista
            </Button>
          </div>
          {historicoError && <p className="text-sm text-destructive">{historicoError}</p>}
          <div className="rounded-md border overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Arquivo</TableHead>
                  <TableHead>Data</TableHead>
                  <TableHead className="min-w-[200px]">Métricas</TableHead>
                  <TableHead className="text-right w-[200px]">Downloads</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {historicoLoading && historicoItems.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                      <Loader2 className="h-5 w-5 animate-spin inline-block mr-2 align-middle" />
                      Carregando histórico…
                    </TableCell>
                  </TableRow>
                ) : historicoItems.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                      Nenhuma validação nos últimos 15 dias.
                    </TableCell>
                  </TableRow>
                ) : (
                  historicoItems.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="font-medium max-w-[240px] truncate" title={row.nome_arquivo}>
                        {row.nome_arquivo || "—"}
                      </TableCell>
                      <TableCell className="text-muted-foreground text-sm whitespace-nowrap">
                        {formatHistoricoDataUtc(row.data_criacao)}
                      </TableCell>
                      <TableCell className="text-sm">
                        <span className="tabular-nums">{row.total_linhas.toLocaleString("pt-BR")}</span> linhas —{" "}
                        <span className="text-emerald-600 dark:text-emerald-400 font-medium tabular-nums">
                          {row.linhas_validas.toLocaleString("pt-BR")}
                        </span>{" "}
                        WPP /{" "}
                        <span className="text-muted-foreground tabular-nums">
                          {row.linhas_invalidas.toLocaleString("pt-BR")}
                        </span>{" "}
                        sem WPP
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex flex-col sm:flex-row gap-2 justify-end">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-1 h-8"
                            onClick={() => void downloadFromNest(row.id, row.nome_arquivo, "original")}
                          >
                            <FileDown className="h-3.5 w-3.5" />
                            Original
                          </Button>
                          <Button
                            type="button"
                            variant="default"
                            size="sm"
                            className="gap-1 h-8"
                            onClick={() => void downloadFromNest(row.id, row.nome_arquivo, "validated")}
                          >
                            <Download className="h-3.5 w-3.5" />
                            Validado
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      {isAdmin && (
        <Collapsible open={metricsOpen} onOpenChange={setMetricsOpen}>
          <Card className="border-muted">
            <CardHeader className="pb-2">
              <CollapsibleTrigger asChild>
                <button
                  type="button"
                  className="flex w-full items-center justify-between gap-2 text-left rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <div>
                    <CardTitle className="flex items-center gap-2 text-lg">
                      <BarChart3 className="h-5 w-5 text-primary" />
                      Métricas de uso
                    </CardTitle>
                    <CardDescription className="mt-1">
                      Volume processado e taxa de WhatsApp ativo por usuário (administradores).
                    </CardDescription>
                  </div>
                  <ChevronDown
                    className={cn("h-5 w-5 shrink-0 text-muted-foreground transition-transform", metricsOpen && "rotate-180")}
                  />
                </button>
              </CollapsibleTrigger>
            </CardHeader>
            <CollapsibleContent>
              <CardContent className="space-y-4 pt-0">
                <div className="flex flex-col sm:flex-row flex-wrap gap-4 items-end">
                  <div className="space-y-1.5">
                    <Label htmlFor="val-dt-ini">Data inicial</Label>
                    <Input
                      id="val-dt-ini"
                      type="date"
                      value={dataInicio}
                      onChange={(e) => setDataInicio(e.target.value)}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="val-dt-fim">Data final</Label>
                    <Input
                      id="val-dt-fim"
                      type="date"
                      value={dataFim}
                      onChange={(e) => setDataFim(e.target.value)}
                    />
                  </div>
                  <Button type="button" variant="secondary" onClick={() => void loadMetrics()} disabled={metricsLoading}>
                    {metricsLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    Atualizar
                  </Button>
                </div>
                {metricsTz && (
                  <p className="text-xs text-muted-foreground">
                    Filtro aplicado no fuso do site WordPress: <strong>{metricsTz}</strong>
                  </p>
                )}
                {metricsError && (
                  <p className="text-sm text-destructive">{metricsError}</p>
                )}
                <div className="rounded-md border overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Usuário</TableHead>
                        <TableHead className="text-right tabular-nums">Total enviado</TableHead>
                        <TableHead className="text-right tabular-nums">Total válidos (WPP)</TableHead>
                        <TableHead className="text-right tabular-nums">Taxa (%)</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {metricsRows.length === 0 && !metricsLoading ? (
                        <TableRow>
                          <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                            Nenhum registro no período.
                          </TableCell>
                        </TableRow>
                      ) : (
                        metricsRows.map((row) => (
                          <TableRow key={row.usuario_id}>
                            <TableCell className="font-medium">{row.usuario_nome || `ID ${row.usuario_id}`}</TableCell>
                            <TableCell className="text-right tabular-nums font-semibold">
                              {row.total_enviado.toLocaleString("pt-BR")}
                            </TableCell>
                            <TableCell className="text-right tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">
                              {row.total_validos.toLocaleString("pt-BR")}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                              {row.taxa_qualidade_pct.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </div>
                <p className="text-xs text-muted-foreground">
                  API: <code className="text-xs bg-muted px-1 rounded">GET /wp-json/api/v1/validador/metricas?data_inicio=YYYY-MM-DD&amp;data_fim=YYYY-MM-DD</code>
                </p>
              </CardContent>
            </CollapsibleContent>
          </Card>
        </Collapsible>
      )}
    </div>
  );
}
