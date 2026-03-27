import { useCallback, useEffect, useRef, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { useToast } from "@/hooks/use-toast";
import { waValidatorUpload, waValidatorStep, fetchValidadorMetricas } from "@/lib/api";
import { Upload, FileSpreadsheet, Loader2, Download, AlertCircle, BarChart3, ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

function getAdminPostUrl(): string {
  const pc = (window as any).pcAjax;
  const u = pc?.adminPostUrl || pc?.siteUrl + "/wp-admin/admin-post.php";
  return typeof u === "string" ? u : "/wp-admin/admin-post.php";
}

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

export default function Validador() {
  const { toast } = useToast();
  const busyRef = useRef(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [progress, setProgress] = useState(0);
  const [statusText, setStatusText] = useState("");
  const [jobId, setJobId] = useState<string | null>(null);
  const [downloadNonce, setDownloadNonce] = useState<string | null>(null);
  const [counts, setCounts] = useState<{ processed: number; total: number } | null>(null);

  const isAdmin = !!(typeof window !== "undefined" && (window as any).pcAjax?.currentUser?.isAdmin);

  const [metricsOpen, setMetricsOpen] = useState(isAdmin);
  const [dataInicio, setDataInicio] = useState(localTodayISO);
  const [dataFim, setDataFim] = useState(localTodayISO);
  const [metricsLoading, setMetricsLoading] = useState(false);
  const [metricsRows, setMetricsRows] = useState<MetricRow[]>([]);
  const [metricsTz, setMetricsTz] = useState<string>("");
  const [metricsError, setMetricsError] = useState<string | null>(null);

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
    setJobId(null);
    setDownloadNonce(null);
    setProgress(0);
    setCounts(null);
  };

  const runPipeline = useCallback(
    async (file: File) => {
      if (busyRef.current) return;
      if (!file.name.toLowerCase().endsWith(".csv")) {
        toast({
          title: "Formato inválido",
          description: "Envie apenas arquivos .csv",
          variant: "destructive",
        });
        return;
      }
      busyRef.current = true;
      setProcessing(true);
      resetOutput();
      setStatusText("Enviando arquivo…");

      try {
        const up = await waValidatorUpload(file);
        setJobId(up.job_id);
        setStatusText("Processando contatos na Evolution API… Isso pode levar vários minutos.");

        let done = false;
        let lastNonce = up.download_nonce ?? "";

        while (!done) {
          const step = await waValidatorStep(up.job_id);
          setProgress(step.progress);
          setCounts({ processed: step.processed, total: step.total });
          if (step.download_nonce) {
            lastNonce = step.download_nonce;
          }
          done = step.done;
          if (!done) {
            await new Promise((r) => setTimeout(r, 400));
          }
        }

        setDownloadNonce(lastNonce || null);
        setProgress(100);
        setStatusText("Concluído. Baixe o CSV validado abaixo.");
        toast({ title: "Validação concluída" });
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
    [toast, isAdmin, loadMetrics]
  );

  const onFile = (f: File | null | undefined) => {
    if (!f) return;
    void runPipeline(f);
  };

  const downloadHref =
    jobId && downloadNonce
      ? `${getAdminPostUrl()}?action=pc_wa_validator_download&job_id=${encodeURIComponent(
          jobId
        )}&_wpnonce=${encodeURIComponent(downloadNonce)}`
      : "";

  return (
    <div className="space-y-8 max-w-5xl mx-auto">
      <PageHeader title="Validador WhatsApp" description="Valide telefones em lote via Evolution API e exporte CSV com coluna WPP." />

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg">
            <FileSpreadsheet className="h-5 w-5 text-primary" />
            Upload do CSV
          </CardTitle>
          <CardDescription>
            O arquivo deve conter uma única coluna com cabeçalho <strong>TELEFONE</strong>. Apenas extensão .csv.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <input
            ref={inputRef}
            type="file"
            accept=".csv,text/csv"
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
                Selecionar CSV
              </>
            )}
          </Button>

          {processing && (
            <div className="space-y-2 pt-2">
              <Progress value={progress} className="h-2" />
              <p className="text-sm text-muted-foreground flex items-start gap-2">
                <Loader2 className="h-4 w-4 shrink-0 animate-spin mt-0.5" />
                {statusText}
              </p>
              {counts && (
                <p className="text-xs text-muted-foreground">
                  {counts.processed.toLocaleString("pt-BR")} / {counts.total.toLocaleString("pt-BR")} números
                </p>
              )}
            </div>
          )}

          {!processing && downloadHref && (
            <div className="flex flex-col sm:flex-row gap-3 pt-4 border-t">
              <Button asChild variant="default" className="gap-2">
                <a href={downloadHref} rel="nofollow">
                  <Download className="h-4 w-4" />
                  Baixar CSV validado
                </a>
              </Button>
            </div>
          )}

          <div className="flex gap-2 text-xs text-muted-foreground pt-2">
            <AlertCircle className="h-4 w-4 shrink-0" />
            <span>
              Configure a Evolution API em <strong>API Manager</strong> antes de usar. O token não é exibido no navegador após salvo.
            </span>
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
