import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Edit2, Trash2, DollarSign, Loader2 } from "lucide-react";
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
  saveCustoProvider,
  deleteCustoProvider,
  getOrcamentosBases,
  saveOrcamentoBase,
  deleteOrcamentoBase,
  getCarteiras,
} from "@/lib/api";

const providers = ["CDA", "GOSAC", "NOAH", "RCS", "SALESFORCE"];

export default function CadastroCusto() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isProviderDialogOpen, setIsProviderDialogOpen] = useState(false);
  const [isOrcamentoDialogOpen, setIsOrcamentoDialogOpen] = useState(false);
  const [editingProvider, setEditingProvider] = useState<any>(null);
  const [formData, setFormData] = useState({
    provider: "",
    custo_por_disparo: "",
  });
  const [orcamentoFormData, setOrcamentoFormData] = useState({
    carteira_id: "",
    orcamento_total: "",
  });

  // Buscar custos de providers
  const { data: custosProviders = [], isLoading: custosLoading } = useQuery({
    queryKey: ['custos-providers'],
    queryFn: getCustosProviders,
  });

  // Buscar orçamentos
  const { data: orcamentos = [], isLoading: orcamentosLoading } = useQuery({
    queryKey: ['orcamentos-bases'],
    queryFn: getOrcamentosBases,
  });

  // Buscar carteiras para o select
  const { data: carteiras = [] } = useQuery({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  const saveProviderMutation = useMutation({
    mutationFn: (data: any) => saveCustoProvider(data),
    onSuccess: () => {
      toast({ title: "Custo do provider salvo com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['custos-providers'] });
      setIsProviderDialogOpen(false);
      setFormData({ provider: "", custo_por_disparo: "" });
      setEditingProvider(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar custo",
        variant: "destructive",
      });
    },
  });

  const deleteProviderMutation = useMutation({
    mutationFn: (id: string) => deleteCustoProvider(id),
    onSuccess: () => {
      toast({ title: "Custo excluído com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['custos-providers'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir",
        description: error.message || "Erro ao excluir custo",
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
      setOrcamentoFormData({ carteira_id: "", orcamento_total: "" });
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

  const handleSaveProvider = () => {
    if (!formData.provider || !formData.custo_por_disparo) {
      toast({
        title: "Campos obrigatórios",
        description: "Provider e custo são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    saveProviderMutation.mutate({
      provider: formData.provider.toUpperCase(),
      custo_por_disparo: parseFloat(formData.custo_por_disparo.replace(',', '.')),
    });
  };

  const handleSaveOrcamento = () => {
    if (!orcamentoFormData.carteira_id || !orcamentoFormData.orcamento_total) {
      toast({
        title: "Campos obrigatórios",
        description: "Carteira e orçamento são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    saveOrcamentoMutation.mutate({
      carteira_id: parseInt(orcamentoFormData.carteira_id),
      orcamento_total: parseFloat(orcamentoFormData.orcamento_total.replace(',', '.')),
    });
  };

  const openEditProvider = (custo: any) => {
    setEditingProvider(custo);
    setFormData({
      provider: custo.provider || "",
      custo_por_disparo: String(custo.custo_por_disparo || "").replace('.', ','),
    });
    setIsProviderDialogOpen(true);
  };

  const openNewProvider = () => {
    setEditingProvider(null);
    setFormData({ provider: "", custo_por_disparo: "" });
    setIsProviderDialogOpen(true);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Cadastro de Custos"
        description="Configure custos por mensagem e orçamentos por carteira"
      />

      {/* Custo por Provedor */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2">
                <DollarSign className="h-5 w-5 text-primary" />
                Custo por Mensagem (Fornecedor)
              </CardTitle>
              <CardDescription>Configure o custo unitário por fornecedor</CardDescription>
            </div>
            <Button onClick={openNewProvider} className="gradient-primary hover:opacity-90">
              <Plus className="mr-2 h-4 w-4" />
              Novo Custo
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {custosLoading ? (
            <Skeleton className="h-32" />
          ) : custosProviders.length === 0 ? (
            <p className="text-center text-muted-foreground py-8">
              Nenhum custo cadastrado. Clique em "Novo Custo" para adicionar.
            </p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-3">
              {custosProviders.map((custo: any) => (
                <div key={custo.id} className="space-y-2 p-4 border rounded-lg">
                  <div className="flex items-center justify-between">
                    <Label className="font-semibold">{custo.provider}</Label>
                    <div className="flex gap-2">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => openEditProvider(custo)}
                      >
                        <Edit2 className="h-4 w-4" />
                      </Button>
                      <AlertDialog>
                        <AlertDialogTrigger asChild>
                          <Button variant="ghost" size="icon" className="h-8 w-8 text-destructive">
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                          <AlertDialogHeader>
                            <AlertDialogTitle>Excluir custo?</AlertDialogTitle>
                            <AlertDialogDescription>
                              Esta ação não pode ser desfeita.
                            </AlertDialogDescription>
                          </AlertDialogHeader>
                          <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                              onClick={() => deleteProviderMutation.mutate(String(custo.id))}
                              className="bg-destructive hover:bg-destructive/90"
                              disabled={deleteProviderMutation.isPending}
                            >
                              {deleteProviderMutation.isPending && (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                              )}
                              Excluir
                            </AlertDialogAction>
                          </AlertDialogFooter>
                        </AlertDialogContent>
                      </AlertDialog>
                    </div>
                  </div>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                      R$
                    </span>
                    <Input
                      value={String(custo.custo_por_disparo || 0).replace('.', ',')}
                      readOnly
                      className="pl-9"
                    />
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Orçamentos */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Orçamentos por Carteira</CardTitle>
              <CardDescription>Configure orçamentos para cada carteira</CardDescription>
            </div>
            <Button
              onClick={() => setIsOrcamentoDialogOpen(true)}
              className="gradient-primary hover:opacity-90"
            >
              <Plus className="mr-2 h-4 w-4" />
              Novo Orçamento
            </Button>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {orcamentosLoading ? (
            <div className="p-8">
              <Skeleton className="h-64" />
            </div>
          ) : orcamentos.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              Nenhum orçamento cadastrado. Clique em "Novo Orçamento" para adicionar.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/50">
                  <TableHead className="font-semibold">Carteira</TableHead>
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
                        {orcamento.nome_carteira || `Carteira #${orcamento.carteira_id}`}
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
                            className={`h-2 rounded-full transition-all ${
                              percentage > 90
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

      {/* Provider Dialog */}
      <Dialog open={isProviderDialogOpen} onOpenChange={setIsProviderDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingProvider ? "Editar Custo" : "Novo Custo por Provider"}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label>Provider</Label>
              <Select
                value={formData.provider}
                onValueChange={(v) => setFormData({ ...formData, provider: v })}
                disabled={!!editingProvider}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Selecione o provider" />
                </SelectTrigger>
                <SelectContent>
                  {providers.map((p) => (
                    <SelectItem key={p} value={p}>
                      {p}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Custo por Disparo (R$)</Label>
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                  R$
                </span>
                <Input
                  value={formData.custo_por_disparo}
                  onChange={(e) =>
                    setFormData({ ...formData, custo_por_disparo: e.target.value })
                  }
                  placeholder="0,050"
                  className="pl-9"
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsProviderDialogOpen(false)}
              disabled={saveProviderMutation.isPending}
            >
              Cancelar
            </Button>
            <Button
              onClick={handleSaveProvider}
              disabled={saveProviderMutation.isPending}
              className="gradient-primary hover:opacity-90"
            >
              {saveProviderMutation.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              Salvar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Orçamento Dialog */}
      <Dialog open={isOrcamentoDialogOpen} onOpenChange={setIsOrcamentoDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Novo Orçamento</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
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
