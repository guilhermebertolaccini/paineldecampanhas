import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2, Shield, Search, Filter } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent } from "@/components/ui/card";
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { useToast } from "@/hooks/use-toast";
import { wpAjax } from "@/lib/api";

interface BlocklistItem {
  id: string;
  tipo: "telefone" | "cpf";
  valor: string;
  motivo?: string;
  criado_por_nome?: string;
  criado_em: string;
}

export default function Blocklist() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState("");
  const [tipoFilter, setTipoFilter] = useState<string>("all");
  const [formData, setFormData] = useState({
    tipo: "telefone" as "telefone" | "cpf",
    valor: "",
    motivo: "",
  });

  const { data: blocklist = [], isLoading } = useQuery({
    queryKey: ["blocklist", tipoFilter, searchTerm],
    queryFn: async () => {
      const result = await wpAjax("pc_get_blocklist", {
        tipo: tipoFilter === "all" ? "" : tipoFilter,
        search: searchTerm,
      });
      return Array.isArray(result) ? result : [];
    },
  });

  const addMutation = useMutation({
    mutationFn: (data: typeof formData) => wpAjax("pc_add_to_blocklist", data),
    onSuccess: () => {
      toast({ title: "Adicionado à blocklist com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ["blocklist"] });
      setIsDialogOpen(false);
      setFormData({ tipo: "telefone", valor: "", motivo: "" });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao adicionar",
        description: error.message || "Erro ao adicionar à blocklist",
        variant: "destructive",
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => wpAjax("pc_remove_from_blocklist", { id }),
    onSuccess: () => {
      toast({ title: "Removido da blocklist com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ["blocklist"] });
      setDeleteId(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao remover",
        description: error.message || "Erro ao remover da blocklist",
        variant: "destructive",
      });
    },
  });

  const handleSubmit = () => {
    if (!formData.valor.trim()) {
      toast({
        title: "Campo obrigatório",
        description: "Preencha o valor do " + formData.tipo,
        variant: "destructive",
      });
      return;
    }
    addMutation.mutate(formData);
  };

  const formatValue = (tipo: string, valor: string) => {
    if (tipo === "telefone") {
      const cleaned = valor.replace(/\D/g, "");
      if (cleaned.length === 11) {
        return `(${cleaned.slice(0, 2)}) ${cleaned.slice(2, 7)}-${cleaned.slice(7)}`;
      }
      return valor;
    } else if (tipo === "cpf") {
      const cleaned = valor.replace(/\D/g, "");
      if (cleaned.length === 11) {
        return `${cleaned.slice(0, 3)}.${cleaned.slice(3, 6)}.${cleaned.slice(6, 9)}-${cleaned.slice(9)}`;
      }
      return valor;
    }
    return valor;
  };

  const filteredBlocklist = blocklist;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Blocklist"
        description="Gerencie telefones e CPFs bloqueados para envios"
      >
        <Button
          onClick={() => setIsDialogOpen(true)}
          className="gradient-primary hover:opacity-90"
        >
          <Plus className="mr-2 h-4 w-4" />
          Adicionar
        </Button>
      </PageHeader>

      <Card>
        <CardContent className="p-6">
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Buscar por telefone ou CPF..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="pl-10"
              />
            </div>
            <Select value={tipoFilter} onValueChange={setTipoFilter}>
              <SelectTrigger className="w-[180px]">
                <Filter className="mr-2 h-4 w-4" />
                <SelectValue placeholder="Filtrar por tipo" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos</SelectItem>
                <SelectItem value="telefone">Telefones</SelectItem>
                <SelectItem value="cpf">CPFs</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <div className="grid gap-4">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : filteredBlocklist.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Shield className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold">Nenhum item bloqueado</h3>
            <p className="text-muted-foreground">
              {searchTerm
                ? "Nenhum resultado encontrado"
                : "Adicione telefones ou CPFs à blocklist"}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {filteredBlocklist.map((item: BlocklistItem, index: number) => (
            <Card
              key={item.id}
              className="animate-slide-in"
              style={{ animationDelay: `${index * 50}ms` }}
            >
              <CardContent className="p-6">
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <div className="flex items-center gap-3 mb-2">
                      <Badge variant={item.tipo === "telefone" ? "default" : "secondary"}>
                        {item.tipo === "telefone" ? "Telefone" : "CPF"}
                      </Badge>
                      <span className="text-lg font-semibold">
                        {formatValue(item.tipo, item.valor)}
                      </span>
                    </div>
                    {item.motivo && (
                      <p className="text-sm text-muted-foreground mb-2">{item.motivo}</p>
                    )}
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                      {item.criado_por_nome && (
                        <span>Adicionado por: {item.criado_por_nome}</span>
                      )}
                      <span>
                        {new Date(item.criado_em).toLocaleDateString("pt-BR", {
                          day: "2-digit",
                          month: "2-digit",
                          year: "numeric",
                          hour: "2-digit",
                          minute: "2-digit",
                        })}
                      </span>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="text-destructive hover:text-destructive"
                    onClick={() => setDeleteId(item.id)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Adicionar à Blocklist</DialogTitle>
            <DialogDescription>
              Telefones e CPFs bloqueados serão automaticamente removidos dos envios
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="tipo">Tipo</Label>
              <Select
                value={formData.tipo}
                onValueChange={(value: "telefone" | "cpf") =>
                  setFormData({ ...formData, tipo: value })
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="telefone">Telefone</SelectItem>
                  <SelectItem value="cpf">CPF</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="valor">
                {formData.tipo === "telefone" ? "Telefone" : "CPF"}{" "}
                <span className="text-red-500">*</span>
              </Label>
              <Input
                id="valor"
                placeholder={
                  formData.tipo === "telefone"
                    ? "(11) 98765-4321"
                    : "123.456.789-00"
                }
                value={formData.valor}
                onChange={(e) => setFormData({ ...formData, valor: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="motivo">Motivo</Label>
              <Textarea
                id="motivo"
                placeholder="Motivo do bloqueio (opcional)"
                value={formData.motivo}
                onChange={(e) => setFormData({ ...formData, motivo: e.target.value })}
                rows={3}
              />
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsDialogOpen(false)}
              disabled={addMutation.isPending}
            >
              Cancelar
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={addMutation.isPending}
              className="gradient-primary hover:opacity-90"
            >
              Adicionar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!deleteId} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remover da blocklist?</AlertDialogTitle>
            <AlertDialogDescription>
              Esta ação não pode ser desfeita. O item será removido permanentemente da
              blocklist.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteId && deleteMutation.mutate(deleteId)}
              className="bg-destructive hover:bg-destructive/90"
            >
              Remover
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
