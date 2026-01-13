import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Edit2, Trash2, Search, MessageSquare, Loader2 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
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
import { useToast } from "@/hooks/use-toast";
import { getMessages, createMessage, updateMessage, deleteMessage, getOtimaTemplates } from "@/lib/api";

interface Template {
  id: string;
  name: string;
  content: string;
  createdAt: string;
  usageCount?: number;
  source?: string;
  templateCode?: string;
  walletName?: string;
  imageUrl?: string;
}

export default function Mensagens() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [isOpen, setIsOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
  const [formData, setFormData] = useState({ name: "", content: "" });

  const { data: messages = [], isLoading: isLoadingMessages } = useQuery({
    queryKey: ['messages'],
    queryFn: getMessages,
  });

  const { data: otimaTemplates = [], isLoading: isLoadingOtima } = useQuery({
    queryKey: ['otima-templates'],
    queryFn: getOtimaTemplates,
    staleTime: 5 * 60 * 1000, // Cache por 5 minutos
  });

  const isLoading = isLoadingMessages || isLoadingOtima;

  // Mapeia os dados da API para o formato esperado
  const localTemplates: Template[] = messages.map((msg: any) => ({
    id: String(msg.id),
    name: msg.title || '',
    content: msg.content || '',
    createdAt: new Date(msg.date).toLocaleDateString('pt-BR'),
    usageCount: 0,
    source: msg.source || 'local',
    templateCode: msg.template_code || msg.template_id || '',
  }));

  const externalTemplates: Template[] = Array.isArray(otimaTemplates) ? otimaTemplates.map((msg: any) => ({
    id: msg.id,
    name: msg.name || '',
    content: msg.content || '',
    createdAt: msg.date ? new Date(msg.date).toLocaleDateString('pt-BR') : '-',
    usageCount: 0,
    source: msg.source,
    templateCode: msg.template_code,
    walletName: msg.wallet_name,
    imageUrl: msg.image_url,
  })) : [];

  const templates = [...localTemplates, ...externalTemplates];

  const filteredTemplates = templates.filter((t) =>
    t.name.toLowerCase().includes(search.toLowerCase()) ||
    (t.content && t.content.toLowerCase().includes(search.toLowerCase())) ||
    (t.walletName && t.walletName.toLowerCase().includes(search.toLowerCase()))
  );

  const createMutation = useMutation({
    mutationFn: (data: { title: string; content: string }) => createMessage(data),
    onSuccess: () => {
      toast({ title: "Template criado com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['messages'] });
      setIsOpen(false);
      setFormData({ name: "", content: "" });
      setEditingTemplate(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao criar template",
        description: error.message || "Erro ao criar template",
        variant: "destructive",
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: { title: string; content: string } }) =>
      updateMessage(id, data),
    onSuccess: () => {
      toast({ title: "Template atualizado com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['messages'] });
      setIsOpen(false);
      setFormData({ name: "", content: "" });
      setEditingTemplate(null);
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao atualizar template",
        description: error.message || "Erro ao atualizar template",
        variant: "destructive",
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteMessage(id),
    onSuccess: () => {
      toast({ title: "Template excluído com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['messages'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao excluir template",
        description: error.message || "Erro ao excluir template",
        variant: "destructive",
      });
    },
  });

  const handleSave = () => {
    if (!formData.name.trim() || !formData.content.trim()) {
      toast({
        title: "Campos obrigatórios",
        description: "Nome e conteúdo são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    if (editingTemplate) {
      updateMutation.mutate({
        id: editingTemplate.id,
        data: { title: formData.name, content: formData.content },
      });
    } else {
      createMutation.mutate({ title: formData.name, content: formData.content });
    }
  };

  const handleEdit = (template: Template) => {
    setEditingTemplate(template);
    setFormData({ name: template.name, content: template.content });
    setIsOpen(true);
  };

  const handleDelete = (id: string) => {
    deleteMutation.mutate(id);
  };

  const openNewDialog = () => {
    setEditingTemplate(null);
    setFormData({ name: "", content: "" });
    setIsOpen(true);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Templates de Mensagem"
        description="Gerencie os templates locais e visualize templates da Ótima (RCS/WPP)"
      >
        <Button onClick={openNewDialog} className="gradient-primary hover:opacity-90">
          <Plus className="mr-2 h-4 w-4" />
          Novo Template Local
        </Button>
      </PageHeader>

      {/* Search */}
      <Card>
        <CardContent className="p-4">
          <div className="relative max-w-md">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Buscar templates (nome, conteúdo ou carteira)..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-10"
            />
          </div>
        </CardContent>
      </Card>

      {/* Templates Grid */}
      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <Skeleton key={i} className="h-48" />
          ))}
        </div>
      ) : filteredTemplates.length === 0 ? (
        <div className="text-center py-12">
          <MessageSquare className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
          <h3 className="text-lg font-semibold">Nenhum template encontrado</h3>
          <p className="text-muted-foreground">Crie seu primeiro template para começar</p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {filteredTemplates.map((template, index) => (
            <Card
              key={template.id}
              className="animate-scale-in hover:shadow-md transition-shadow flex flex-col"
              style={{ animationDelay: `${index * 50}ms` }}
            >
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  {/* Imagem do RCS se houver */}
                  {template.imageUrl && (
                    <div className="w-full h-32 mb-3 rounded-md overflow-hidden bg-muted">
                      <img
                        src={template.imageUrl}
                        alt="Template RCS"
                        className="w-full h-full object-cover"
                        onError={(e) => (e.currentTarget.style.display = 'none')}
                      />
                    </div>
                  )}
                </div>

                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2 w-full">
                    {!template.imageUrl && (
                      <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 shrink-0">
                        <MessageSquare className="h-5 w-5 text-primary" />
                      </div>
                    )}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <CardTitle className="text-base truncate" title={template.name}>{template.name}</CardTitle>
                        {template.source === 'otima_wpp' && (
                          <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">WPP</Badge>
                        )}
                        {template.source === 'otima_rcs' && (
                          <Badge variant="outline" className="text-xs bg-blue-50 text-blue-700 border-blue-200">RCS</Badge>
                        )}
                      </div>

                      {template.walletName && (
                        <div className="mt-1">
                          <Badge variant="secondary" className="text-[10px] h-5">
                            Carteira: {template.walletName}
                          </Badge>
                        </div>
                      )}

                      <div className="flex flex-col mt-1 gap-0.5">
                        <CardDescription className="text-xs">{template.createdAt}</CardDescription>
                        {template.templateCode && (
                          <CardDescription className="text-xs text-muted-foreground">
                            Cód: {template.templateCode}
                          </CardDescription>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4 flex-1 flex flex-col justify-between">
                <p className="text-sm text-muted-foreground whitespace-pre-wrap line-clamp-4">
                  {template.content || '(Sem conteúdo de texto)'}
                </p>
                <div className="flex items-center justify-between pt-2">
                  {template.usageCount !== undefined && template.source === 'local' && (
                    <span className="text-xs text-muted-foreground">
                      Usado {template.usageCount} vezes
                    </span>
                  )}
                  {template.source !== 'local' && (
                    <span className="text-xs text-muted-foreground italic">
                      Template externo
                    </span>
                  )}
                  <div className="flex gap-1 ml-auto">
                    {template.source === 'local' && (
                      <>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleEdit(template)}>
                          <Edit2 className="h-4 w-4" />
                        </Button>
                        <AlertDialog>
                          <AlertDialogTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-8 w-8 text-destructive hover:text-destructive">
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </AlertDialogTrigger>
                          <AlertDialogContent>
                            <AlertDialogHeader>
                              <AlertDialogTitle>Excluir template?</AlertDialogTitle>
                              <AlertDialogDescription>
                                Esta ação não pode ser desfeita. O template "{template.name}" será removido permanentemente.
                              </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                              <AlertDialogCancel>Cancelar</AlertDialogCancel>
                              <AlertDialogAction
                                onClick={() => handleDelete(template.id)}
                                className="bg-destructive hover:bg-destructive/90"
                                disabled={deleteMutation.isPending}
                              >
                                {deleteMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                Excluir
                              </AlertDialogAction>
                            </AlertDialogFooter>
                          </AlertDialogContent>
                        </AlertDialog>
                      </>
                    )}
                    {(template.source === 'otima_wpp' || template.source === 'otima_rcs') && (
                      <Badge variant="secondary" className="text-xs">
                        Somente leitura
                      </Badge>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Dialog */}
      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editingTemplate ? "Editar Template" : "Novo Template"}</DialogTitle>
            <DialogDescription>
              {editingTemplate
                ? "Atualize as informações do template"
                : "Crie um novo template de mensagem"}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="name">Nome do Template</Label>
              <Input
                id="name"
                placeholder="Ex: Promoção de Natal"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="content">Conteúdo da Mensagem</Label>
              <Textarea
                id="content"
                placeholder="Digite sua mensagem..."
                value={formData.content}
                onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                rows={5}
              />
              <p className="text-xs text-muted-foreground">
                Variáveis: {"{nome}"}, {"{cpf}"}, {"{telefone}"}, {"{email}"}, {"{link}"}, {"{data}"}
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsOpen(false)} disabled={createMutation.isPending || updateMutation.isPending}>
              Cancelar
            </Button>
            <Button
              onClick={handleSave}
              className="gradient-primary hover:opacity-90"
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              {(createMutation.isPending || updateMutation.isPending) && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {editingTemplate ? "Salvar Alterações" : "Criar Template"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
