import { useState, useEffect, useMemo } from "react";
import { useQuery, useMutation, useQueryClient, useQueries } from "@tanstack/react-query";
import { Plus, Edit2, Trash2, Database, Link2, CheckCircle, Loader2 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
import { Checkbox } from "@/components/ui/checkbox";
import { useToast } from "@/hooks/use-toast";
import {
  getCarteiras,
  getCarteira,
  createCarteira,
  updateCarteira,
  deleteCarteira,
  getBasesCarteira,
  vincularBaseCarteira,
  getAvailableBases,
} from "@/lib/api";

interface Carteira {
  id: string;
  nome: string;
  id_carteira: string;
  id_ruler?: string;
  descricao?: string;
  ativo: number;
  criado_em?: string;
}

export default function Configuracoes() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [isBasesDialogOpen, setIsBasesDialogOpen] = useState(false);
  const [selectedCarteiraId, setSelectedCarteiraId] = useState<string>("");
  const [editingCarteira, setEditingCarteira] = useState<Carteira | null>(null);
  const [formData, setFormData] = useState({
    nome: "",
    id_carteira: "",
    id_ruler: "",
    descricao: ""
  });
  const [selectedBases, setSelectedBases] = useState<string[]>([]);
  const [searchBase, setSearchBase] = useState("");

  // Buscar carteiras
  const { data: carteiras = [], isLoading } = useQuery({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  /* 
  // Removido para evitar loop infinito (Error #185)
  // Buscar bases vinculadas para todas as carteiras
  const basesQueries = useQueries({
    queries: carteiras.map((carteira: any) => ({
      queryKey: ['bases-carteira-list', String(carteira.id)],
      queryFn: async () => {
        console.log('🔵 [Config] Buscando bases para carteira:', carteira.id, carteira.nome);
        const result = await getBasesCarteira(String(carteira.id));
        console.log('🔵 [Config] Bases retornadas para carteira', carteira.id, ':', result);
        return Array.isArray(result) ? result : [];
      },
      enabled: !!carteira.id,
      staleTime: 30000, // Cache por 30 segundos
    })),
  });

  // Mapear bases vinculadas por carteira ID
  const basesPorCarteira = useMemo(() => {
    const map: Record<string, string[]> = {};
    carteiras.forEach((carteira: any, index: number) => {
      const queryResult = basesQueries[index];
      if (!queryResult) return;

      const bases = queryResult.data;
      const basesArray = Array.isArray(bases) ? bases : [];
      map[String(carteira.id)] = basesArray;
      if (basesArray.length > 0) {
        console.log('🟢 [Config] Mapeando bases para carteira', carteira.id, ':', basesArray);
      }
    });
    return map;
  }, [carteiras.map((c: any) => c.id).join(','), basesQueries.map(q => q.dataUpdatedAt).join(',')]);
  */

  // Buscar bases disponíveis
  const { data: bases = [] } = useQuery({
    queryKey: ['available-bases'],
    queryFn: async () => {
      const result = await getAvailableBases();
      return Array.isArray(result) ? result : [];
    },
  });

  // Buscar bases vinculadas quando abrir dialog
  const { data: basesCarteira = [] } = useQuery({
    queryKey: ['bases-carteira', selectedCarteiraId],
    queryFn: async () => {
      const result = await getBasesCarteira(selectedCarteiraId);
      return Array.isArray(result) ? result : [];
    },
    enabled: !!selectedCarteiraId && isBasesDialogOpen,
  });

  useEffect(() => {
    if (!isBasesDialogOpen) {
      return; // Não faz nada quando o dialog está fechado
    }

    // Backend agora retorna array simples de strings: ['base1', 'base2', ...]
    if (basesCarteira && Array.isArray(basesCarteira)) {
      // Filtra apenas strings válidas
      const vinculadas = basesCarteira
        .filter((b): b is string => typeof b === 'string' && b.trim().length > 0)
        .map(b => b.trim());

      // Só atualiza se realmente mudou para evitar loops
      setSelectedBases((prev) => {
        const prevSorted = [...prev].sort().join(',');
        const newSorted = [...vinculadas].sort().join(',');
        if (prevSorted === newSorted) {
          return prev; // Retorna o mesmo array se não mudou
        }
        console.log('🟢 [Config] Bases vinculadas carregadas:', vinculadas);
        return vinculadas;
      });
    }
  }, [basesCarteira, isBasesDialogOpen]);

  const createMutation = useMutation({
    mutationFn: (data: any) => createCarteira(data),
    onSuccess: () => {
      toast({ title: "Carteira criada com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['carteiras'] });
      queryClient.invalidateQueries({ queryKey: ['bases-carteira-list'] });
      setIsDialogOpen(false);
      setFormData({ nome: "", id_carteira: "", id_ruler: "", descricao: "" });
      setEditingCarteira(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao criar carteira",
        description: error.message || "Erro ao criar carteira",
        variant: "destructive",
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => updateCarteira(id, data),
    onSuccess: () => {
      toast({ title: "Carteira atualizada com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['carteiras'] });
      queryClient.invalidateQueries({ queryKey: ['bases-carteira-list'] });
      setIsDialogOpen(false);
      setFormData({ nome: "", id_carteira: "", id_ruler: "", descricao: "" });
      setEditingCarteira(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao atualizar carteira",
        description: error.message || "Erro ao atualizar carteira",
        variant: "destructive",
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteCarteira(id),
    onSuccess: () => {
      toast({ title: "Carteira excluída com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['carteiras'] });
      queryClient.invalidateQueries({ queryKey: ['bases-carteira-list'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir carteira",
        description: error.message || "Erro ao excluir carteira",
        variant: "destructive",
      });
    },
  });

  const handleToggleBase = (base: string) => {
    setSelectedBases((prev) =>
      prev.includes(base)
        ? prev.filter((b) => b !== base)
        : [...prev, base]
    );
  };

  const handleSave = () => {
    if (!formData.nome.trim() || !formData.id_carteira.trim()) {
      toast({
        title: "Campos obrigatórios",
        description: "Nome e ID da carteira são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    if (editingCarteira) {
      updateMutation.mutate({ id: editingCarteira.id, data: formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const vincularMutation = useMutation({
    mutationFn: ({ carteiraId, bases }: { carteiraId: string; bases: string[] }) => {
      console.log('🔵 [Configuracoes] Enviando requisição:', { carteiraId, bases });
      return vincularBaseCarteira(carteiraId, bases);
    },
    onSuccess: async (data: any, variables) => {
      console.log('✅ [Configuracoes] Vínculos salvos com sucesso:', data);
      console.log('✅ [Configuracoes] Carteira ID:', variables.carteiraId);
      console.log('✅ [Configuracoes] Count retornado:', data?.count);
      console.log('✅ [Configuracoes] Bases enviadas:', variables.bases);

      // Se o count for 0, significa que não salvou nada
      if (data?.count === 0) {
        console.error('🔴 [Configuracoes] ATENÇÃO: Nenhuma base foi salva! Count = 0');
        toast({
          title: "Atenção",
          description: "Nenhuma base foi vinculada. Verifique os logs do servidor.",
          variant: "destructive",
        });
        return;
      }

      toast({
        title: "Bases vinculadas com sucesso!",
        description: data?.count ? `${data.count} base(s) vinculada(s)` : undefined
      });

      // Fecha o dialog primeiro
      setIsBasesDialogOpen(false);

      // Aguarda um pouco para garantir que o backend salvou
      await new Promise(resolve => setTimeout(resolve, 300));

      // Invalida e refaz TODAS as queries de bases vinculadas
      const carteiraIdStr = String(variables.carteiraId);
      const carteiraIdNum = parseInt(variables.carteiraId, 10);

      // Invalida todas as queries relacionadas
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['bases-carteira', carteiraIdStr] }),
        queryClient.invalidateQueries({ queryKey: ['bases-carteira', carteiraIdNum] }),
        queryClient.invalidateQueries({ queryKey: ['bases-carteira-list', carteiraIdStr] }),
        queryClient.invalidateQueries({ queryKey: ['bases-carteira-list', carteiraIdNum] }),
        queryClient.invalidateQueries({
          predicate: (query) =>
            query.queryKey[0] === 'bases-carteira-list'
        }),
      ]);

      // Força refetch de todas as queries de bases vinculadas
      await queryClient.refetchQueries({
        predicate: (query) => query.queryKey[0] === 'bases-carteira-list',
        type: 'active'
      });

      console.log('✅ [Configuracoes] Queries invalidadas e refetchadas');
    },
    onError: (error: any) => {
      console.error('🔴 [Configuracoes] Erro ao vincular bases:', error);
      toast({
        title: "Erro ao vincular bases",
        description: error.message || "Erro ao vincular bases. Verifique o console para mais detalhes.",
        variant: "destructive",
      });
    },
  });

  const handleSaveBases = () => {
    if (!selectedCarteiraId) {
      toast({
        title: "Erro",
        description: "Nenhuma carteira selecionada",
        variant: "destructive",
      });
      return;
    }

    if (!Array.isArray(selectedBases) || selectedBases.length === 0) {
      toast({
        title: "Atenção",
        description: "Selecione pelo menos uma base para vincular",
        variant: "destructive",
      });
      return;
    }

    // Log detalhado das bases que serão salvas
    console.log('🔵 [Configuracoes] Salvando vínculos:', {
      carteiraId: selectedCarteiraId,
      bases: selectedBases,
      basesCount: selectedBases.length,
      basesDetalhadas: selectedBases.map((baseName, idx) => ({
        index: idx,
        nome: baseName,
        tipo: typeof baseName,
        length: String(baseName).length
      }))
    });

    // Verifica se as bases selecionadas existem na lista de bases disponíveis
    const basesDisponiveisNomes = bases.map((b: any) => String(b?.name || b?.id || ''));
    const basesNaoEncontradas = selectedBases.filter(baseName => !basesDisponiveisNomes.includes(baseName));
    if (basesNaoEncontradas.length > 0) {
      console.warn('⚠️ [Configuracoes] Algumas bases selecionadas não foram encontradas na lista:', basesNaoEncontradas);
    }

    vincularMutation.mutate({ carteiraId: selectedCarteiraId, bases: selectedBases });
  };

  const openEdit = async (carteira: Carteira) => {
    try {
      const data = await getCarteira(carteira.id);
      setEditingCarteira(carteira);
      setFormData({
        nome: data.nome || "",
        id_carteira: data.id_carteira || "",
        id_ruler: data.id_ruler || "",
        descricao: data.descricao || "",
      });
      setIsDialogOpen(true);
    } catch (error: any) {
      toast({
        title: "Erro ao carregar carteira",
        description: error.message,
        variant: "destructive",
      });
    }
  };

  const openNew = () => {
    setEditingCarteira(null);
    setFormData({ nome: "", id_carteira: "", id_ruler: "", descricao: "" });
    setIsDialogOpen(true);
  };

  const openBasesDialog = (carteiraId: string) => {
    // Limpa estados antes de abrir
    setSelectedBases([]);
    setSearchBase("");
    setSelectedCarteiraId(carteiraId);
    setIsBasesDialogOpen(true);
  };

  const handleDelete = (id: string) => {
    deleteMutation.mutate(id);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Carteiras"
        description="Gerencie carteiras e vincule bases de dados"
      >
        <Button onClick={openNew} className="gradient-primary hover:opacity-90">
          <Plus className="mr-2 h-4 w-4" />
          Nova Carteira
        </Button>
      </PageHeader>

      {isLoading ? (
        <div className="grid gap-4">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : carteiras.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Database className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold">Nenhuma carteira cadastrada</h3>
            <p className="text-muted-foreground">Crie sua primeira carteira</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {carteiras.map((carteira: any, index: number) => (
            <Card
              key={carteira.id}
              className="animate-slide-in"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                  <div className="flex items-center gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
                      <Database className="h-6 w-6 text-primary" />
                    </div>
                    <div className="flex-1">
                      <CardTitle className="text-lg">{carteira.nome}</CardTitle>
                      <CardDescription className="flex items-center gap-2 mt-1 flex-wrap">
                        <Badge variant="secondary">ID: {carteira.id_carteira}</Badge>
                        {carteira.id_ruler && (
                          <Badge variant="outline" className="border-blue-500 text-blue-600 bg-blue-50/50">
                            Ruler: {carteira.id_ruler}
                          </Badge>
                        )}
                        {carteira.ativo ? (
                          <Badge variant="default">Ativa</Badge>
                        ) : (
                          <Badge variant="secondary">Inativa</Badge>
                        )}
                      </CardDescription>
                      {carteira.descricao && (
                        <p className="text-sm text-muted-foreground mt-1">{carteira.descricao}</p>
                      )}

                      {/* Bases vinculadas - Removido temporariamente para evitar loops de renderização
                      <div className="mt-2">
                        {basesQueries[index]?.isLoading ? (
                          <p className="text-xs text-muted-foreground">Carregando bases...</p>
                        ) : basesPorCarteira[String(carteira.id)]?.length > 0 ? (
                          <div className="flex flex-wrap gap-1 mt-1">
                            <span className="text-xs text-muted-foreground font-medium">Bases:</span>
                            {basesPorCarteira[String(carteira.id)].map((base: string) => (
                              <Badge key={base} variant="outline" className="text-xs">
                                {base}
                              </Badge>
                            ))}
                          </div>
                        ) : (
                          <p className="text-xs text-muted-foreground italic">Nenhuma base vinculada</p>
                        )}
                      </div>
                      */}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => openBasesDialog(String(carteira.id))}
                    >
                      <Link2 className="mr-2 h-4 w-4" />
                      Bases
                    </Button>
                    <Button variant="ghost" size="icon" onClick={() => openEdit(carteira)}>
                      <Edit2 className="h-4 w-4" />
                    </Button>
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive">
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>Excluir carteira?</AlertDialogTitle>
                          <AlertDialogDescription>
                            Esta ação não pode ser desfeita. A carteira "{carteira.nome}" será removida permanentemente.
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>Cancelar</AlertDialogCancel>
                          <AlertDialogAction
                            onClick={() => handleDelete(String(carteira.id))}
                            className="bg-destructive hover:bg-destructive/90"
                            disabled={deleteMutation.isPending}
                          >
                            {deleteMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Excluir
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  </div>
                </div>
              </CardHeader>
            </Card>
          ))}
        </div>
      )}

      {/* Create/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editingCarteira ? "Editar Carteira" : "Nova Carteira"}</DialogTitle>
            <DialogDescription>
              {editingCarteira
                ? "Atualize as informações da carteira"
                : "Crie uma nova carteira para gerenciar bases"}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="nome">Nome da Carteira <span className="text-red-500">*</span></Label>
              <Input
                id="nome"
                placeholder="Ex: BRADESCO"
                value={formData.nome}
                onChange={(e) => setFormData({ ...formData, nome: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="id_carteira">ID da Carteira <span className="text-red-500">*</span></Label>
              <Input
                id="id_carteira"
                placeholder="Ex: BRD001"
                value={formData.id_carteira}
                onChange={(e) => setFormData({ ...formData, id_carteira: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                Este ID será enviado ao provider no lugar de idgis_ambiente
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="id_ruler">ID Ruler (Opcional - GOSAC Oficial)</Label>
              <Input
                id="id_ruler"
                placeholder="Ex: 2021"
                value={formData.id_ruler}
                onChange={(e) => setFormData({ ...formData, id_ruler: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                Necessário para envios via GOSAC Oficial
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="descricao">Descrição</Label>
              <Textarea
                id="descricao"
                placeholder="Descrição opcional da carteira"
                value={formData.descricao}
                onChange={(e) => setFormData({ ...formData, descricao: e.target.value })}
                rows={3}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDialogOpen(false)} disabled={createMutation.isPending || updateMutation.isPending}>
              Cancelar
            </Button>
            <Button
              onClick={handleSave}
              disabled={createMutation.isPending || updateMutation.isPending}
              className="gradient-primary hover:opacity-90"
            >
              {(createMutation.isPending || updateMutation.isPending) && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {editingCarteira ? "Salvar Alterações" : "Criar Carteira"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Bases Dialog */}
      <Dialog
        open={isBasesDialogOpen}
        onOpenChange={(open) => {
          setIsBasesDialogOpen(open);
          if (!open) {
            // Limpa estados quando fecha
            setSelectedBases([]);
            setSearchBase("");
          }
        }}
      >
        <DialogContent className="sm:max-w-2xl max-h-[80vh] flex flex-col">
          <DialogHeader>
            <DialogTitle>Vincular Bases</DialogTitle>
            <DialogDescription>
              Selecione as bases de dados para vincular a esta carteira ({selectedBases.length} selecionada{selectedBases.length !== 1 ? 's' : ''})
            </DialogDescription>
          </DialogHeader>

          {/* Search and Actions */}
          <div className="space-y-3 py-2">
            <Input
              placeholder="Buscar base..."
              value={searchBase}
              onChange={(e) => setSearchBase(e.target.value)}
              className="w-full"
            />
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  const filteredBases = bases.filter((base: any) =>
                    String(base?.name || '').toLowerCase().includes(searchBase.toLowerCase())
                  );
                  setSelectedBases(filteredBases.map((base: any) => String(base.name)));
                }}
              >
                Selecionar Todas
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setSelectedBases([])}
              >
                Desmarcar Todas
              </Button>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto space-y-2 pr-2">
            {!Array.isArray(bases) || bases.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-8">
                Nenhuma base disponível
              </p>
            ) : (
              <>
                {bases
                  .filter((base: any) => {
                    const q = searchBase.toLowerCase();
                    const n = String(base?.name || '').toLowerCase();
                    const l = String(base?.label || '').toLowerCase();
                    return n.includes(q) || l.includes(q);
                  })
                  .map((base: any, index: number) => {
                    try {
                      const baseId = base?.id ? String(base.id) : `base-${index}`;
                      const baseName = base?.name ? String(base.name) : base?.id ? String(base.id) : 'Base sem nome';
                      const displayName = base?.label ? String(base.label) : baseName;
                      const baseRecords = base?.records ? String(base.records) : null;

                      return (
                        <label
                          key={baseId}
                          className="flex items-center space-x-3 p-3 rounded-lg border cursor-pointer hover:bg-muted/50 transition-colors"
                        >
                          <Checkbox
                            checked={selectedBases.includes(baseName)}
                            onCheckedChange={() => handleToggleBase(baseName)}
                          />
                          <div className="flex-1 min-w-0">
                            <p className="font-medium text-sm truncate">{displayName}</p>
                            {baseRecords && (
                              <p className="text-xs text-muted-foreground">{baseRecords} registros</p>
                            )}
                          </div>
                        </label>
                      );
                    } catch (error) {
                      console.error('Erro ao renderizar base:', base, error);
                      return null;
                    }
                  })}
              </>
            )}
          </div>

          <DialogFooter className="mt-4">
            <Button variant="outline" onClick={() => setIsBasesDialogOpen(false)}>
              Cancelar
            </Button>
            <Button onClick={handleSaveBases} className="gradient-primary hover:opacity-90">
              Salvar {selectedBases.length} Base{selectedBases.length !== 1 ? 's' : ''}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
