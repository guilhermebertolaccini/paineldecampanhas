import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Edit2, Trash2, Fish } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useToast } from "@/hooks/use-toast";
import {
  getIscas,
  createIsca,
  updateIsca,
  deleteIsca,
  getCarteiras,
} from "@/lib/api";

interface Isca {
  id: string;
  telefone: string;
  nome: string;
  id_carteira?: string;
  cpf?: string;
  ativo: number;
  criado_em?: string;
  nome_carteira?: string;
}

interface Carteira {
  id: string;
  nome: string;
  id_carteira: string;
}

export default function Iscas() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingIsca, setEditingIsca] = useState<Isca | null>(null);
  const [formData, setFormData] = useState({
    nome: "",
    telefone: "",
    id_carteira: "none",
    cpf: ""
  });

  // Buscar iscas
  const { data: iscas = [], isLoading } = useQuery({
    queryKey: ['iscas'],
    queryFn: getIscas,
  });

  // Buscar carteiras
  const { data: carteiras = [] } = useQuery<Carteira[]>({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => createIsca(data),
    onSuccess: () => {
      toast({ title: "Isca criada com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['iscas'] });
      setIsDialogOpen(false);
      setFormData({ nome: "", telefone: "", id_carteira: "", cpf: "" });
      setEditingIsca(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao criar isca",
        description: error.message || "Erro ao criar isca",
        variant: "destructive",
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => updateIsca(id, data),
    onSuccess: () => {
      toast({ title: "Isca atualizada com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['iscas'] });
      setIsDialogOpen(false);
      setFormData({ nome: "", telefone: "", id_carteira: "", cpf: "" });
      setEditingIsca(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao atualizar isca",
        description: error.message || "Erro ao atualizar isca",
        variant: "destructive",
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteIsca(id),
    onSuccess: () => {
      toast({ title: "Isca excluída com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['iscas'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir isca",
        description: error.message || "Erro ao excluir isca",
        variant: "destructive",
      });
    },
  });

  const handleSave = () => {
    if (!formData.nome.trim() || !formData.telefone.trim()) {
      toast({
        title: "Campos obrigatórios",
        description: "Nome e telefone são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    if (editingIsca) {
      updateMutation.mutate({
        id: editingIsca.id,
        data: {
          ...formData,
          id_carteira: formData.id_carteira === "none" ? "" : formData.id_carteira,
        },
      });
    } else {
      createMutation.mutate({
        ...formData,
        id_carteira: formData.id_carteira === "none" ? "" : formData.id_carteira,
      });
    }
  };

  const openEditDialog = (isca: Isca) => {
    setEditingIsca(isca);
    setFormData({
      nome: isca.nome,
      telefone: isca.telefone,
      id_carteira: isca.id_carteira || "none",
      cpf: isca.cpf || "",
    });
    setIsDialogOpen(true);
  };

  const openCreateDialog = () => {
    setEditingIsca(null);
    setFormData({ nome: "", telefone: "", id_carteira: "none", cpf: "" });
    setIsDialogOpen(true);
  };

  const formatTelefone = (telefone: string) => {
    const cleaned = telefone.replace(/\D/g, '');
    if (cleaned.length === 13) {
      return `+${cleaned.slice(0, 2)} (${cleaned.slice(2, 4)}) ${cleaned.slice(4, 9)}-${cleaned.slice(9)}`;
    }
    if (cleaned.length === 12) {
      return `+${cleaned.slice(0, 2)} (${cleaned.slice(2, 4)}) ${cleaned.slice(4, 8)}-${cleaned.slice(8)}`;
    }
    return telefone;
  };

  const formatCPF = (cpf: string) => {
    const cleaned = cpf.replace(/\D/g, '');
    if (cleaned.length === 11) {
      return `${cleaned.slice(0, 3)}.${cleaned.slice(3, 6)}.${cleaned.slice(6, 9)}-${cleaned.slice(9)}`;
    }
    return cpf;
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800">
      <PageHeader title="Cadastro de Iscas" />

      <main className="container mx-auto px-4 py-8 max-w-7xl">
        <div className="mb-6 flex justify-between items-center">
          <div>
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Gerenciar Iscas</h2>
            <p className="text-slate-600 dark:text-slate-400 mt-1">
              Cadastre números de teste para validar suas campanhas
            </p>
          </div>
          <Button onClick={openCreateDialog}>
            <Plus className="w-4 h-4 mr-2" />
            Nova Isca
          </Button>
        </div>

        {isLoading ? (
          <div className="grid gap-4">
            {[1, 2, 3].map((i) => (
              <Card key={i}>
                <CardContent className="p-6">
                  <Skeleton className="h-20 w-full" />
                </CardContent>
              </Card>
            ))}
          </div>
        ) : iscas.length === 0 ? (
          <Card>
            <CardContent className="p-12 text-center">
              <Fish className="w-16 h-16 mx-auto text-slate-400 mb-4" />
              <h3 className="text-lg font-semibold mb-2">Nenhuma isca cadastrada</h3>
              <p className="text-slate-600 mb-4">
                Comece criando sua primeira isca para testar envios
              </p>
              <Button onClick={openCreateDialog}>
                <Plus className="w-4 h-4 mr-2" />
                Criar primeira isca
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4">
            {iscas.map((isca: Isca) => (
              <Card key={isca.id} className="hover:shadow-lg transition-shadow">
                <CardHeader className="pb-3">
                  <div className="flex justify-between items-start">
                    <div className="flex-1">
                      <CardTitle className="text-lg flex items-center gap-2">
                        <Fish className="w-5 h-5 text-blue-500" />
                        {isca.nome}
                      </CardTitle>
                      <CardDescription className="mt-2 space-y-1">
                        <div className="flex items-center gap-2">
                          <span className="font-medium">Telefone:</span>
                          <span className="font-mono">{formatTelefone(isca.telefone)}</span>
                        </div>
                        {isca.cpf && (
                          <div className="flex items-center gap-2">
                            <span className="font-medium">CPF:</span>
                            <span className="font-mono">{formatCPF(isca.cpf)}</span>
                          </div>
                        )}
                        {isca.nome_carteira && (
                          <div className="flex items-center gap-2">
                            <span className="font-medium">Carteira:</span>
                            <Badge variant="outline">{isca.nome_carteira}</Badge>
                          </div>
                        )}
                        {isca.criado_em && (
                          <div className="text-sm text-slate-500">
                            Criado em: {new Date(isca.criado_em).toLocaleDateString('pt-BR')}
                          </div>
                        )}
                      </CardDescription>
                    </div>
                    <div className="flex gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => openEditDialog(isca)}
                      >
                        <Edit2 className="w-4 h-4" />
                      </Button>
                      <AlertDialog>
                        <AlertDialogTrigger asChild>
                          <Button variant="outline" size="sm">
                            <Trash2 className="w-4 h-4 text-red-500" />
                          </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                          <AlertDialogHeader>
                            <AlertDialogTitle>Confirmar exclusão</AlertDialogTitle>
                            <AlertDialogDescription>
                              Tem certeza que deseja excluir a isca "{isca.nome}"?
                              Esta ação não pode ser desfeita.
                            </AlertDialogDescription>
                          </AlertDialogHeader>
                          <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                              onClick={() => deleteMutation.mutate(isca.id)}
                              className="bg-red-500 hover:bg-red-600"
                            >
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

        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
          <DialogContent className="sm:max-w-[500px]">
            <DialogHeader>
              <DialogTitle>
                {editingIsca ? "Editar Isca" : "Nova Isca"}
              </DialogTitle>
              <DialogDescription>
                {editingIsca
                  ? "Atualize os dados da isca"
                  : "Cadastre um novo número de teste para suas campanhas"}
              </DialogDescription>
            </DialogHeader>

            <div className="grid gap-4 py-4">
              <div className="grid gap-2">
                <Label htmlFor="nome">Nome *</Label>
                <Input
                  id="nome"
                  placeholder="Ex: Teste WhatsApp Principal"
                  value={formData.nome}
                  onChange={(e) => setFormData({ ...formData, nome: e.target.value })}
                />
              </div>

              <div className="grid gap-2">
                <Label htmlFor="telefone">Telefone *</Label>
                <Input
                  id="telefone"
                  placeholder="5511999999999"
                  value={formData.telefone}
                  onChange={(e) => setFormData({ ...formData, telefone: e.target.value })}
                />
                <p className="text-xs text-slate-500">
                  Formato: 55 + DDD + número (ex: 5511999999999)
                </p>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="cpf">CPF (opcional)</Label>
                <Input
                  id="cpf"
                  placeholder="12345678900"
                  value={formData.cpf}
                  onChange={(e) => setFormData({ ...formData, cpf: e.target.value })}
                />
                <p className="text-xs text-slate-500">
                  Apenas números
                </p>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="id_carteira">Carteira (opcional)</Label>
                <Select
                  value={formData.id_carteira}
                  onValueChange={(value) => setFormData({ ...formData, id_carteira: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione uma carteira" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Nenhuma</SelectItem>
                    {carteiras.map((carteira) => (
                      <SelectItem key={carteira.id} value={carteira.id}>
                        {carteira.nome}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-slate-500">
                  Vincular a uma carteira específica (opcional)
                </p>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setIsDialogOpen(false)}>
                Cancelar
              </Button>
              <Button
                onClick={handleSave}
                disabled={createMutation.isPending || updateMutation.isPending}
              >
                {(createMutation.isPending || updateMutation.isPending)
                  ? "Salvando..."
                  : editingIsca ? "Atualizar" : "Criar"}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </main>
    </div>
  );
}
