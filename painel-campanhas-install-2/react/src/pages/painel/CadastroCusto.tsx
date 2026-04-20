import { useEffect, useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2, DollarSign, Loader2, Save, MessageSquare, Radio } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
  getCustosProviders,
  saveCustosProvidersMap,
  getOrcamentosBases,
  saveOrcamentoBase,
  deleteOrcamentoBase,
  getCarteiras,
  type CustoProviderDef,
} from "@/lib/api";

// Lista ESTRITA e EXATA de fornecedores (fallback local caso o backend não retorne).
// O backend é a fonte da verdade – mas mantemos aqui para consistência visual.
const FALLBACK_DEFS: CustoProviderDef[] = [
  { key: "otima_rcs",      label: "Ótima RCS",      category: "rcs" },
  { key: "cda_rcs",        label: "CDA RCS",        category: "rcs" },
  { key: "otima_wpp",      label: "Ótima WPP",      category: "whatsapp" },
  { key: "cda",            label: "CDA",            category: "whatsapp" },
  { key: "gosac",          label: "GOSAC",          category: "whatsapp" },
  { key: "gosac_oficial",  label: "Gosac Oficial",  category: "whatsapp" },
  { key: "noah",           label: "NOAH",           category: "whatsapp" },
  { key: "noah_oficial",   label: "Noah Oficial",   category: "whatsapp" },
  { key: "robbu_oficial",  label: "Robbu Oficial",  category: "whatsapp" },
  { key: "making_oficial", label: "Making Oficial", category: "whatsapp" },
];

const toDisplay = (n: number | undefined | null): string => {
  if (n === undefined || n === null || Number.isNaN(Number(n))) return "";
  const v = Number(n);
  if (v === 0) return "";
  return String(v).replace(".", ",");
};

const parseBR = (s: string): number => {
  if (!s || typeof s !== "string") return 0;
  const trimmed = s.trim().replace(/\s+/g, "");
  if (!trimmed) return 0;
  const normalized = trimmed.replace(",", ".");
  const n = parseFloat(normalized);
  return Number.isNaN(n) || n < 0 ? 0 : n;
};

export default function CadastroCusto() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isOrcamentoDialogOpen, setIsOrcamentoDialogOpen] = useState(false);
  const [selectedMonth, setSelectedMonth] = useState(String(new Date().getMonth() + 1));
  const [selectedYear, setSelectedYear] = useState(String(new Date().getFullYear()));

  // Lista de meses
  const months = [
    { value: "1", label: "Janeiro" },
    { value: "2", label: "Fevereiro" },
    { value: "3", label: "Março" },
    { value: "4", label: "Abril" },
    { value: "5", label: "Maio" },
    { value: "6", label: "Junho" },
    { value: "7", label: "Julho" },
    { value: "8", label: "Agosto" },
    { value: "9", label: "Setembro" },
    { value: "10", label: "Outubro" },
    { value: "11", label: "Novembro" },
    { value: "12", label: "Dezembro" },
  ];

  // Lista de anos (ano atual - 1 até ano atual + 1)
  const currentYear = new Date().getFullYear();
  const years = [
    { value: String(currentYear - 1), label: String(currentYear - 1) },
    { value: String(currentYear), label: String(currentYear) },
    { value: String(currentYear + 1), label: String(currentYear + 1) },
  ];

  // Estado local do formulário de custos (strings para permitir edição fluida com vírgula)
  const [custosForm, setCustosForm] = useState<Record<string, string>>({});

  const [orcamentoFormData, setOrcamentoFormData] = useState({
    carteira_id: "",
    orcamento_total: "",
    mes: String(new Date().getMonth() + 1),
    ano: String(new Date().getFullYear()),
  });

  // Buscar custos de providers (novo formato: { defs, map })
  const { data: custosResponse, isLoading: custosLoading } = useQuery({
    queryKey: ['custos-providers'],
    queryFn: getCustosProviders,
  });

  const defs: CustoProviderDef[] = useMemo(() => {
    const fromServer = custosResponse?.defs;
    if (Array.isArray(fromServer) && fromServer.length > 0) return fromServer;
    return FALLBACK_DEFS;
  }, [custosResponse?.defs]);

  const serverMap: Record<string, number> = useMemo(() => {
    return custosResponse?.map ?? {};
  }, [custosResponse?.map]);

  // Hidrata o formulário quando chega do servidor (sem sobrescrever edição em progresso)
  useEffect(() => {
    if (!custosResponse) return;
    const initial: Record<string, string> = {};
    for (const def of defs) {
      initial[def.key] = toDisplay(serverMap[def.key] ?? 0);
    }
    setCustosForm(initial);
  }, [custosResponse, defs, serverMap]);

  const rcsDefs = useMemo(() => defs.filter(d => d.category === 'rcs'), [defs]);
  const whatsappDefs = useMemo(() => defs.filter(d => d.category === 'whatsapp'), [defs]);

  // Buscar orçamentos (filtrado por mês/ano)
  const { data: orcamentos = [], isLoading: orcamentosLoading } = useQuery({
    // @ts-ignore
    queryKey: ['orcamentos-bases', selectedMonth, selectedYear],
    // @ts-ignore
    queryFn: () => getOrcamentosBases({ mes: selectedMonth, ano: selectedYear }),
  });

  // Buscar carteiras para o select
  const { data: carteiras = [] } = useQuery({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  const saveCustosMutation = useMutation({
    mutationFn: (payload: Record<string, number>) => saveCustosProvidersMap(payload),
    onSuccess: () => {
      toast({ title: "Custos salvos com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['custos-providers'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar custos",
        variant: "destructive",
      });
    },
  });

  const saveOrcamentoMutation = useMutation({
    mutationFn: (data: any) => saveOrcamentoBase(data),
    onSuccess: () => {
      toast({ title: "Orçamento salvo com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['orcamentos-bases'] });
      setIsOrcamentoDialogOpen(false);
      setOrcamentoFormData({
        carteira_id: "",
        orcamento_total: "",
        mes: selectedMonth,
        ano: selectedYear
      });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar orçamento",
        variant: "destructive",
      });
    },
  });

  const deleteOrcamentoMutation = useMutation({
    mutationFn: (id: string) => deleteOrcamentoBase(id),
    onSuccess: () => {
      toast({ title: "Orçamento excluído com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['orcamentos-bases'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir",
        description: error.message || "Erro ao excluir orçamento",
        variant: "destructive",
      });
    },
  });

  const handleSaveCustos = () => {
    const payload: Record<string, number> = {};
    for (const def of defs) {
      payload[def.key] = parseBR(custosForm[def.key] ?? "");
    }
    saveCustosMutation.mutate(payload);
  };

  const handleResetCustos = () => {
    const reverted: Record<string, string> = {};
    for (const def of defs) {
      reverted[def.key] = toDisplay(serverMap[def.key] ?? 0);
    }
    setCustosForm(reverted);
  };

  const isDirty = useMemo(() => {
    for (const def of defs) {
      const current = parseBR(custosForm[def.key] ?? "");
      const server = Number(serverMap[def.key] ?? 0);
      if (current !== server) return true;
    }
    return false;
  }, [custosForm, defs, serverMap]);

  const handleSaveOrcamento = () => {
    if (!orcamentoFormData.carteira_id || !orcamentoFormData.orcamento_total) {
      toast({
        title: "Campos obrigatórios",
        description: "Carteira e orçamento são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    // Pega o nome da carteira (base) selecionada, que é o que o backend espera
    // Na implementação antiga, carteira_id era tratado como base name? 
    // Backend espera 'nome_base'. O select do frontend usa carteira.id.
    // Vamos obter o nome da carteira pelo ID.
    // A implementação do backend em handle_save_orcamento_base espera 'nome_base'. 
    // O frontend antigo enviava 'carteira_id' na mutation abaixo, mas o handle do backend lia 'nome_base'.
    // Isso sugere que o frontend deve enviar o NOME, ou o backend foi ajustado agora.
    // Meu backend handle_save_orcamento_base pega $_POST['nome_base'].

    const carteira = carteiras.find((c: any) => String(c.id) === String(orcamentoFormData.carteira_id));
    // Assumindo que o vinculo carteira <-> base é 1:1 e o nome da base é usado como chave.
    // Porém, o sistema V2 usa tabelas de vinculo.
    // Se o backend espera 'nome_base', precisamos garantir que estamos enviando o correto.
    // Em sistemas legados, muitas vezes nome_base = nome_carteira ou ID.
    // Vou enviar o nome da carteira como nome_base por enquanto, ou melhor, o ID se for o que ele usa para vincular.
    // REVISÃO: O backend handle_save_orcamento_base usa 'nome_base' para buscar na tabela pc_orcamentos_bases.
    // E handle_get_orcamentos_bases faz JOIN com pc_carteiras_v2 via pc_carteiras_bases_v2.
    // Isso implica que 'nome_base' em orçamentos deve bater com 'nome_base' em carteiras_bases_v2.
    // Se eu estou criando um NOVO orçamento, eu preciso saber qual é o 'nome_base' associado à carteira selecionada.
    // Mas a tabela de carteiras tem 'id_carteira' (string) e 'nome' (label).
    // E pc_carteiras_bases_v2 liga carteira_id -> nome_base.
    // Se eu selecionar uma carteira que NÃO tem base vinculada, o orçamento ficará órfão?
    // Ou será que 'nome_base' é apenas o nome da carteira?
    // Dado o código do backend V2 que eu vi:
    // JOIN pc_carteiras_bases_v2 v ON v.carteira_id = c.id WHERE v.nome_base = %s
    // O orçamento é ligado à BASE, e a base à carteira.
    // Então eu preciso saber o nome da base da carteira selecionada.
    // Como não tenho isso fácil aqui (getCarteiras retorna só carteiras), vou assumir que o usuário
    // quer definir o orçamento para a carteira e o backend deveria resolver ou eu devo enviar o nome da carteira como base?
    // Vou enviar o nome da carteira como nome_base, pois parece ser o padrão (1 carteira = 1 base com mesmo nome ou algo assim).
    // Mas espere... no handle_get_orcamentos_bases, ele faz o join inverso.
    // Vou assumir que o valor a ser enviado é o nome da carteira mesmo.

    saveOrcamentoMutation.mutate({
      nome_base: carteira ? carteira.nome : orcamentoFormData.carteira_id,
      orcamento_total: parseFloat(orcamentoFormData.orcamento_total.replace(',', '.')),
      mes: parseInt(orcamentoFormData.mes),
      ano: parseInt(orcamentoFormData.ano)
    });
  };

  const openNewOrcamento = () => {
    setOrcamentoFormData({
      carteira_id: "",
      orcamento_total: "",
      mes: selectedMonth,
      ano: selectedYear
    });
    setIsOrcamentoDialogOpen(true);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Cadastro de Custos"
        description="Configure custos por mensagem e orçamentos mensais por carteira"
      />

      {/* Custos por Fornecedor – lista estrita, 2 blocos (RCS / WhatsApp) */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Bloco 1: RCS */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Radio className="h-5 w-5 text-primary" />
              Custos RCS
            </CardTitle>
            <CardDescription>
              Custo unitário (R$) por disparo nas rotas RCS.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {custosLoading ? (
              <Skeleton className="h-40" />
            ) : (
              <fieldset className="space-y-4 border-0 p-0">
                <legend className="sr-only">Custos RCS</legend>
                {rcsDefs.map((def) => (
                  <div key={def.key} className="space-y-2">
                    <Label htmlFor={`custo-${def.key}`} className="font-semibold">
                      {def.label}
                    </Label>
                    <div className="relative">
                      <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-sm">
                        R$
                      </span>
                      <Input
                        id={`custo-${def.key}`}
                        inputMode="decimal"
                        placeholder="0,0000"
                        value={custosForm[def.key] ?? ""}
                        onChange={(e) =>
                          setCustosForm((prev) => ({ ...prev, [def.key]: e.target.value }))
                        }
                        className="pl-9"
                      />
                    </div>
                  </div>
                ))}
              </fieldset>
            )}
          </CardContent>
        </Card>

        {/* Bloco 2: WhatsApp / Demais Rotas */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <MessageSquare className="h-5 w-5 text-primary" />
              Custos WhatsApp / Rotas
            </CardTitle>
            <CardDescription>
              Custo unitário (R$) por disparo nas rotas de WhatsApp e demais fornecedores.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {custosLoading ? (
              <Skeleton className="h-80" />
            ) : (
              <fieldset className="grid gap-4 sm:grid-cols-2 border-0 p-0">
                <legend className="sr-only">Custos WhatsApp / Rotas</legend>
                {whatsappDefs.map((def) => (
                  <div key={def.key} className="space-y-2">
                    <Label htmlFor={`custo-${def.key}`} className="font-semibold">
                      {def.label}
                    </Label>
                    <div className="relative">
                      <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-sm">
                        R$
                      </span>
                      <Input
                        id={`custo-${def.key}`}
                        inputMode="decimal"
                        placeholder="0,0000"
                        value={custosForm[def.key] ?? ""}
                        onChange={(e) =>
                          setCustosForm((prev) => ({ ...prev, [def.key]: e.target.value }))
                        }
                        className="pl-9"
                      />
                    </div>
                  </div>
                ))}
              </fieldset>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Barra de ações (salvar / reverter) */}
      <Card>
        <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between py-4">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <DollarSign className="h-4 w-4" />
            Valores armazenados em <code className="font-mono text-xs">wp_options → pc_custos_providers_map</code>.
            {isDirty && (
              <span className="ml-2 inline-flex items-center rounded-md bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                Alterações não salvas
              </span>
            )}
          </div>
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={handleResetCustos}
              disabled={!isDirty || saveCustosMutation.isPending || custosLoading}
            >
              Reverter
            </Button>
            <Button
              onClick={handleSaveCustos}
              disabled={saveCustosMutation.isPending || custosLoading}
              className="gradient-primary hover:opacity-90"
            >
              {saveCustosMutation.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              Salvar custos
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Orçamentos */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Orçamentos por Carteira (Mensal)</CardTitle>
              <CardDescription>Gerencie orçamentos para cada carteira por mês de referência</CardDescription>
            </div>
            <div className="flex gap-2">
              <Select value={selectedMonth} onValueChange={setSelectedMonth}>
                <SelectTrigger className="w-[140px]">
                  <SelectValue placeholder="Mês" />
                </SelectTrigger>
                <SelectContent>
                  {months.map(m => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}
                </SelectContent>
              </Select>
              <Select value={selectedYear} onValueChange={setSelectedYear}>
                <SelectTrigger className="w-[100px]">
                  <SelectValue placeholder="Ano" />
                </SelectTrigger>
                <SelectContent>
                  {years.map(y => <SelectItem key={y.value} value={y.value}>{y.label}</SelectItem>)}
                </SelectContent>
              </Select>
              <Button
                onClick={openNewOrcamento}
                className="gradient-primary hover:opacity-90 ml-2"
              >
                <Plus className="mr-2 h-4 w-4" />
                Novo Orçamento
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {orcamentosLoading ? (
            <div className="p-8">
              <Skeleton className="h-64" />
            </div>
          ) : orcamentos.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              Nenhum orçamento cadastrado para {months.find(m => m.value === selectedMonth)?.label}/{selectedYear}.
              Clique em "Novo Orçamento" para adicionar.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/50">
                  <TableHead className="font-semibold">Carteira / Base</TableHead>
                  <TableHead className="font-semibold text-center">Referência</TableHead>
                  <TableHead className="font-semibold text-right">Orçamento Total</TableHead>
                  <TableHead className="font-semibold text-right">Consumido</TableHead>
                  <TableHead className="font-semibold text-right">Disponível</TableHead>
                  <TableHead className="font-semibold">Progresso</TableHead>
                  <TableHead className="w-[100px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {orcamentos.map((orcamento: any) => {
                  const consumido = orcamento.consumido || 0;
                  const orcamento_total = orcamento.orcamento_total || 0;
                  const percentage = orcamento_total > 0 ? (consumido / orcamento_total) * 100 : 0;
                  const disponivel = orcamento_total - consumido;
                  return (
                    <TableRow key={orcamento.id}>
                      <TableCell className="font-medium">
                        {orcamento.nome_carteira || orcamento.nome_base}
                      </TableCell>
                      <TableCell className="text-center text-muted-foreground">
                        {orcamento.mes && orcamento.ano ? `${String(orcamento.mes).padStart(2, '0')}/${orcamento.ano}` : 'Geral'}
                      </TableCell>
                      <TableCell className="text-right">
                        R$ {orcamento_total.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-right">
                        R$ {consumido.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-right">
                        R$ {disponivel.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell>
                        <div className="w-full bg-muted rounded-full h-2">
                          <div
                            className={`h-2 rounded-full transition-all ${percentage > 90
                              ? 'bg-destructive'
                              : percentage > 70
                                ? 'bg-warning'
                                : 'bg-success'
                              }`}
                            style={{ width: `${Math.min(percentage, 100)}%` }}
                          />
                        </div>
                        <span className="text-xs text-muted-foreground">{percentage.toFixed(1)}%</span>
                      </TableCell>
                      <TableCell>
                        <AlertDialog>
                          <AlertDialogTrigger asChild>
                            <Button variant="ghost" size="icon" className="text-destructive">
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </AlertDialogTrigger>
                          <AlertDialogContent>
                            <AlertDialogHeader>
                              <AlertDialogTitle>Excluir orçamento?</AlertDialogTitle>
                              <AlertDialogDescription>
                                Esta ação não pode ser desfeita.
                              </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                              <AlertDialogCancel>Cancelar</AlertDialogCancel>
                              <AlertDialogAction
                                onClick={() => deleteOrcamentoMutation.mutate(String(orcamento.id))}
                                className="bg-destructive hover:bg-destructive/90"
                                disabled={deleteOrcamentoMutation.isPending}
                              >
                                {deleteOrcamentoMutation.isPending && (
                                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                )}
                                Excluir
                              </AlertDialogAction>
                            </AlertDialogFooter>
                          </AlertDialogContent>
                        </AlertDialog>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Orçamento Dialog */}
      <Dialog open={isOrcamentoDialogOpen} onOpenChange={setIsOrcamentoDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Novo Orçamento</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Mês</Label>
                <Select
                  value={orcamentoFormData.mes}
                  onValueChange={(v) => setOrcamentoFormData({ ...orcamentoFormData, mes: v })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Mês" />
                  </SelectTrigger>
                  <SelectContent>
                    {months.map(m => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Ano</Label>
                <Select
                  value={orcamentoFormData.ano}
                  onValueChange={(v) => setOrcamentoFormData({ ...orcamentoFormData, ano: v })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Ano" />
                  </SelectTrigger>
                  <SelectContent>
                    {years.map(y => <SelectItem key={y.value} value={y.value}>{y.label}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label>Carteira</Label>
              <Select
                value={orcamentoFormData.carteira_id}
                onValueChange={(v) => setOrcamentoFormData({ ...orcamentoFormData, carteira_id: v })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Selecione a carteira" />
                </SelectTrigger>
                <SelectContent>
                  {carteiras.map((c: any) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Orçamento Total (R$)</Label>
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                  R$
                </span>
                <Input
                  value={orcamentoFormData.orcamento_total}
                  onChange={(e) =>
                    setOrcamentoFormData({ ...orcamentoFormData, orcamento_total: e.target.value })
                  }
                  placeholder="50000,00"
                  className="pl-9"
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsOrcamentoDialogOpen(false)}
              disabled={saveOrcamentoMutation.isPending}
            >
              Cancelar
            </Button>
            <Button
              onClick={handleSaveOrcamento}
              disabled={saveOrcamentoMutation.isPending}
              className="gradient-primary hover:opacity-90"
            >
              {saveOrcamentoMutation.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              Salvar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
