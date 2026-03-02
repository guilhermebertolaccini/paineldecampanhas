import { useState, useEffect, useMemo } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Database, Filter, MessageSquare, Truck, Send, Loader2, AlertCircle } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { useToast } from "@/hooks/use-toast";
import { FilterBuilder, FilterItem } from "@/components/campaign/FilterBuilder";
import {
  getAvailableBases,
  getFilters,
  getCountDetailed,
  getMessages,
  getTemplateContent,
  scheduleCampaign,
  getCarteiras,
  getBasesCarteira,
  checkBaseUpdate,
  getTemplatesByWallet,
  getIscas,
  saveRecurring,
} from "@/lib/api";

const providers = [
  { id: "OTIMA_RCS", name: "Ótima RCS", available: true },
  { id: "CDA_RCS", name: "CDA RCS", available: true },
  { id: "OTIMA_WPP", name: "Ótima WPP", available: true },
  { id: "CDA", name: "CDA WPP", available: true },
  { id: "GOSAC", name: "Gosac", available: true },
  { id: "GOSAC_OFICIAL", name: "Gosac Oficial", available: true },
  { id: "NOAH", name: "Noah", available: true },
  { id: "NOAH_OFICIAL", name: "Noah Oficial", available: true },
  { id: "SALESFORCE", name: "Salesforce", available: true },
  { id: "TECH_IA", name: "Tech IA", available: true },
];

export default function NovaCampanha() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [step, setStep] = useState(1);
  const [filters, setFilters] = useState<FilterItem[]>([]);
  const [baseUpdateStatus, setBaseUpdateStatus] = useState<{
    isUpdated: boolean;
    message: string;
  } | null>(null);
  const [formData, setFormData] = useState({
    name: "",
    carteira: "",
    base: "",
    template: "",
    templateCode: "",
    templateSource: "",
    message: "",
    providers: [] as string[],
    record_limit: 0,
    exclude_recent_phones: true,
    include_baits: false,
    throttling_type: 'none',
    throttling_config: {} as any,
    is_recurring: false,
  });

  // Buscar carteiras
  const { data: carteiras = [] } = useQuery({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  // Buscar bases da carteira selecionada
  const { data: basesCarteira = [], isLoading: basesCarteiraLoading } = useQuery({
    queryKey: ['bases-carteira', formData.carteira],
    queryFn: async () => {
      console.log('🔵 [NovaCampanha] Buscando bases da carteira:', formData.carteira);
      const result = await getBasesCarteira(formData.carteira);
      console.log('🔵 [NovaCampanha] Bases da carteira retornadas:', result);
      return Array.isArray(result) ? result : [];
    },
    enabled: !!formData.carteira,
  });

  // Buscar todas as bases disponíveis (fallback se não houver carteira)
  const { data: allBases = [], isLoading: basesLoading } = useQuery({
    queryKey: ['available-bases'],
    queryFn: async () => {
      const result = await getAvailableBases();
      console.log('🔵 [NovaCampanha] Todas as bases disponíveis:', result);
      return Array.isArray(result) ? result : [];
    },
  });

  // Bases filtradas por carteira
  const bases = useMemo(() => {
    if (!formData.carteira) {
      return [];
    }

    if (!Array.isArray(basesCarteira) || basesCarteira.length === 0) {
      console.log('⚠️ [NovaCampanha] Nenhuma base vinculada encontrada para carteira:', formData.carteira);
      return [];
    }

    if (!Array.isArray(allBases) || allBases.length === 0) {
      console.log('⚠️ [NovaCampanha] Nenhuma base disponível encontrada');
      return [];
    }

    // Backend agora retorna array simples de strings: ['base1', 'base2', ...]
    // Normaliza para lowercase para comparação case-insensitive
    const nomesVinculados = basesCarteira
      .filter((b): b is string => typeof b === 'string')
      .map(b => b.trim().toLowerCase());

    console.log('🟢 [NovaCampanha] Bases vinculadas (normalizado):', nomesVinculados);
    console.log('🟢 [NovaCampanha] Total de bases disponíveis:', allBases.length);

    // Filtra bases disponíveis usando MATCH EXATO (case-insensitive)
    const basesFiltradas = allBases.filter((base: any) => {
      const baseName = String(base?.name || base?.id || '').trim().toLowerCase();
      return nomesVinculados.includes(baseName);
    });

    console.log('🟢 [NovaCampanha] Bases filtradas:', basesFiltradas.map((b: any) => b?.name || b?.id));
    console.log('🟢 [NovaCampanha] Total após filtro:', basesFiltradas.length);

    return basesFiltradas;
  }, [formData.carteira, basesCarteira, allBases]);

  // Buscar templates de mensagem locais
  const { data: localTemplatesData = [], isLoading: localTemplatesLoading } = useQuery({
    queryKey: ['messages'],
    queryFn: getMessages,
  });

  // Buscar iscas (baits) cadastradas e ativas
  const { data: baitsData = [], isLoading: baitsLoading } = useQuery({
    queryKey: ['baits'],
    queryFn: getIscas,
  });

  // Buscar templates externos por carteira
  const { data: externalTemplatesData = [], isLoading: externalTemplatesLoading } = useQuery({
    queryKey: ['external-templates', formData.carteira],
    queryFn: () => getTemplatesByWallet(formData.carteira),
    enabled: !!formData.carteira,
  });

  // Processar e mesclar templates
  const templates = useMemo(() => {
    // Templates Locais
    const local = (localTemplatesData || []).map((t: any) => ({
      id: String(t.id),
      name: t.title || '',
      source: t.source || 'local',
      templateCode: t.template_code || t.template_id || '',
      walletId: null,
      walletName: null,
      imageUrl: null,
    }));

    // Templates Externos (já filtrados por carteira pelo backend)
    const external = Array.isArray(externalTemplatesData) ? externalTemplatesData.map((t: any) => ({
      id: `${t.provider}_${t.id}_${t.id_ambient}`,
      name: t.name || t.id || '',
      source: t.provider === 'Gosac Oficial' ? 'gosac_oficial' : (t.source || 'external'),
      templateCode: t.name || '',
      walletId: t.id_ambient,
      walletName: t.wallet_name || `${t.provider} (${t.id_ambient})`,
      imageUrl: t.image_url,
      content: t.content || '',
      language: t.language,
      category: t.category,
      components: t.components,
    })) : [];

    console.log('📋 [NovaCampanha] Templates locais:', local.length);
    console.log('📋 [NovaCampanha] Templates externos:', external.length);

    return [...local, ...external];
  }, [localTemplatesData, externalTemplatesData]);

  const templatesLoading = localTemplatesLoading || externalTemplatesLoading;

  // Buscar filtros quando base for selecionada
  const { data: availableFilters = [], isLoading: filtersLoading } = useQuery({
    queryKey: ['filters', formData.base],
    queryFn: async () => {
      try {
        const result = await getFilters(formData.base);
        console.log('🔍 [Filtros] Resultado da API:', result, 'Tipo:', typeof result, 'É array?', Array.isArray(result));

        // Garante que sempre retorna um array
        if (!result) {
          console.log('⚠️ [Filtros] Resultado null/undefined, retornando array vazio');
          return [];
        }

        if (!Array.isArray(result)) {
          console.log('⚠️ [Filtros] Resultado não é array, retornando array vazio. Valor:', result);
          return [];
        }

        return result;
      } catch (error: any) {
        console.error('🔴 [NovaCampanha] Erro ao buscar filtros:', error);
        // Retorna array vazio em caso de erro para não quebrar a UI
        return [];
      }
    },
    enabled: !!formData.base && step >= 2,
    retry: 1, // Tenta apenas 1 vez em caso de erro
    retryDelay: 1000,
  });

  // Calcular contagem quando filtros mudarem
  const { data: countData = { total: 0, recent_excluded: 0, blocked: 0, effective: 0 }, isLoading: countLoading } = useQuery({
    queryKey: ['count', formData.base, filters, formData.exclude_recent_phones],
    queryFn: async () => {
      try {
        // Formata os filtros novos para enviar ao backend
        // O backend espera um array de objetos {column, operator, value}
        // A FilterItem interface já combina com isso
        const formattedFilters = filters
          .filter(f => f.column && f.operator && f.value !== '' && f.value !== null)
          .map(f => ({
            column: f.column,
            operator: f.operator,
            value: f.value
          }));

        return await getCountDetailed({
          table_name: formData.base,
          filters: formattedFilters,
          exclude_recent: formData.exclude_recent_phones,
        });
      } catch (error: any) {
        console.error('🔴 [NovaCampanha] Erro ao calcular contagem:', error);
        // Retorna zerado em caso de erro
        return { total: 0, recent_excluded: 0, blocked: 0, effective: 0 };
      }
    },
    enabled: !!formData.base && step >= 2,
    retry: 1,
    retryDelay: 1000,
  });

  // Buscar conteúdo do template quando selecionado
  const { data: templateContent, refetch: refetchTemplate } = useQuery({
    queryKey: ['template-content', formData.template],
    queryFn: () => {
      console.log('🔍 [useQuery template-content] formData.template:', formData.template);
      if (!formData.template || formData.template === '' || formData.template === '0') {
        console.error('⚠️ [useQuery template-content] ID do template vazio, não buscando');
        return Promise.reject(new Error('ID do template vazio'));
      }
      return getTemplateContent(formData.template);
    },
    enabled: !!formData.template && formData.template !== '' && formData.template !== '0' && step >= 3,
    retry: false, // Não tenta novamente em caso de erro
  });

  // Verificar atualização da base quando selecionada
  const { data: baseUpdateData } = useQuery({
    queryKey: ['base-update', formData.base],
    queryFn: () => checkBaseUpdate(formData.base),
    enabled: !!formData.base,
  });

  // Atualiza baseUpdateStatus quando os dados mudarem
  useEffect(() => {
    console.log('🔍 [useEffect baseUpdateData] Dados recebidos:', baseUpdateData);
    if (baseUpdateData) {
      const newStatus = {
        isUpdated: baseUpdateData.is_updated,
        message: baseUpdateData.message || '',
      };
      console.log('✅ [useEffect baseUpdateData] Setando baseUpdateStatus:', newStatus);
      setBaseUpdateStatus(newStatus);
    } else {
      console.log('⚠️ [useEffect baseUpdateData] Dados ainda não disponíveis');
    }
  }, [baseUpdateData]);

  // Atualizar mensagem quando template mudar
  useEffect(() => {
    if (formData.template && step === 3 && templateContent?.content) {
      console.log('🔄 [useEffect template] Atualizando mensagem com:', templateContent.content.substring(0, 50));
      // Template local, atualiza apenas a mensagem
      setFormData(prev => {
        // Evita loop: só atualiza se a mensagem realmente mudou
        if (prev.message !== templateContent.content) {
          console.log('✅ [useEffect template] Mensagem atualizada');
          return { ...prev, message: templateContent.content };
        }
        console.log('⏭️ [useEffect template] Mensagem já está atualizada, pulando');
        return prev;
      });
    }
  }, [templateContent?.content, step]);

  const scheduleMutation = useMutation({
    mutationFn: (data: any) => scheduleCampaign(data),
    onSuccess: () => {
      toast({
        title: "Campanha criada com sucesso!",
        description: "Sua campanha foi enviada para aprovação.",
      });
      navigate("/painel/campanhas");
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao criar campanha",
        description: error.message || "Erro ao criar campanha",
        variant: "destructive",
      });
    },
  });

  const saveRecurringMutation = useMutation({
    mutationFn: (data: any) => saveRecurring(data),
    onSuccess: () => {
      toast({
        title: "Filtro salvo com sucesso!",
        description: "Você pode gerar esta campanha manualmente a qualquer momento em 'Filtros Salvos'.",
      });
      navigate("/painel/campanhas");
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar filtro",
        description: error.message || "Erro ao salvar filtro",
        variant: "destructive",
      });
    },
  });

  const handleProviderToggle = (providerId: string) => {
    setFormData((prev) => ({
      ...prev,
      providers: prev.providers.includes(providerId)
        ? prev.providers.filter((p) => p !== providerId)
        : [...prev.providers, providerId],
    }));
  };

  const handleSubmit = async () => {
    if (!formData.name.trim()) {
      toast({
        title: "Nome obrigatório",
        description: "Por favor, informe o nome da campanha",
        variant: "destructive",
      });
      return;
    }

    if (!formData.base) {
      toast({
        title: "Base obrigatória",
        description: "Por favor, selecione uma base de dados",
        variant: "destructive",
      });
      return;
    }

    if (!formData.template) {
      toast({
        title: "Template obrigatório",
        description: "Por favor, selecione um template de mensagem",
        variant: "destructive",
      });
      return;
    }

    if (formData.providers.length === 0) {
      toast({
        title: "Fornecedor obrigatório",
        description: "Por favor, selecione pelo menos um fornecedor",
        variant: "destructive",
      });
      return;
    }

    // Prepara providers_config (formato esperado pelo backend: mode, providers, percentages)
    const providersConfigMap: Record<string, number> = {};
    const percentPerProvider = 100 / formData.providers.length;
    formData.providers.forEach(provider => {
      providersConfigMap[provider] = percentPerProvider;
    });

    const providersConfig = {
      mode: 'split',
      providers: formData.providers,
      percentages: providersConfigMap
    };

    const formattedFilters = filters
      .filter(f => f.column && f.operator && f.value !== '' && f.value !== null)
      .map(f => ({
        column: f.column,
        operator: f.operator,
        value: f.value
      }));

    if (formData.is_recurring) {
      const recurringData = {
        nome_campanha: formData.name,
        table_name: formData.base,
        template_id: formData.templateSource === 'local' ? parseInt(formData.template) : null,
        template_code: formData.templateCode || null,
        template_source: formData.templateSource || 'local',
        providers_config: providersConfig,
        filters: formattedFilters,
        record_limit: formData.record_limit || 0,
        exclude_recent_phones: formData.exclude_recent_phones ? 1 : 0,
        include_baits: formData.include_baits ? 1 : 0,
        throttling_type: formData.throttling_type || 'none',
        throttling_config: formData.throttling_config || {},
      };
      saveRecurringMutation.mutate(recurringData);
    } else {
      const campaignData = {
        table_name: formData.base,
        filters: formattedFilters,
        providers_config: providersConfig,
        template_id: formData.templateSource === 'local' ? parseInt(formData.template) : null,
        template_code: formData.templateCode || null,
        template_source: formData.templateSource || 'local',
        record_limit: formData.record_limit || 0,
        exclude_recent_phones: formData.exclude_recent_phones ? 1 : 0,
        include_baits: formData.include_baits ? 1 : 0,
        throttling_type: formData.throttling_type || 'none',
        throttling_config: formData.throttling_config || {},
      };

      scheduleMutation.mutate(campaignData);
    }
  };

  const canGoNext = () => {
    switch (step) {
      case 1:
        // Verifica se nome e base estão preenchidos E se a base está atualizada
        const hasRequiredFields = boolean(formData.name.trim() && formData.carteira && formData.base);
        const isBaseUpdated = !baseUpdateStatus || baseUpdateStatus.isUpdated;

        return hasRequiredFields && isBaseUpdated;
      case 2:
        return true; // Filtros são opcionais
      case 3:
        return boolean(formData.template && formData.message.trim());
      case 4:
        // Configuração de envio (sempre válido, pois tem defaults)
        if (formData.throttling_type === 'linear') {
          return !!formData.throttling_config?.qtd_msgs && !!formData.throttling_config?.intervalo_minutos;
        }
        if (formData.throttling_type === 'split') {
          return !!formData.throttling_config?.fase1_percent && !!formData.throttling_config?.fase1_horas && !!formData.throttling_config?.fase2_horas;
        }
        return true;
      case 5:
        return formData.providers.length > 0;
      default:
        return false;
    }
  };

  const boolean = (value: any) => !!value;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Nova Campanha"
        description="Crie uma nova campanha usando bases de dados"
      />

      {/* Progress Steps */}
      <div className="flex items-center justify-center gap-2">
        {[1, 2, 3, 4, 5].map((s) => (
          <div key={s} className="flex items-center">
            <div
              className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold transition-all ${s === step
                ? "gradient-primary text-primary-foreground shadow-glow"
                : s < step
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted text-muted-foreground"
                }`}
            >
              {s}
            </div>
            {s < 5 && (
              <div
                className={`h-1 w-12 sm:w-20 mx-2 rounded-full ${s < step ? "bg-primary" : "bg-muted"
                  }`}
              />
            )}
          </div>
        ))}
      </div>

      {/* Step Content */}
      <Card className="animate-scale-in">
        {step === 1 && (
          <>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Database className="h-5 w-5 text-primary" />
                Selecionar Base de Dados
              </CardTitle>
              <CardDescription>Escolha a base de dados para sua campanha</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Nome da Campanha *</Label>
                <Input
                  id="name"
                  placeholder="Ex: Black Friday 2024"
                  value={formData.name}
                  onChange={(e) => {
                    console.log('📝 [Input Nome] Valor digitado:', e.target.value);
                    setFormData({ ...formData, name: e.target.value });
                  }}
                />
              </div>

              {/* Seleção de Carteira */}
              <div className="space-y-2">
                <Label>Carteira *</Label>
                <Select
                  value={formData.carteira || undefined}
                  onValueChange={(value) => {
                    setFormData({ ...formData, carteira: value, base: "" });
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione uma carteira para filtrar as bases" />
                  </SelectTrigger>
                  <SelectContent>
                    {carteiras.map((carteira: any) => (
                      <SelectItem key={carteira.id} value={carteira.id}>
                        {carteira.nome}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {formData.carteira && (
                  <p className="text-xs text-muted-foreground">
                    Mostrando apenas bases vinculadas a esta carteira
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Base de Dados</Label>
                {!formData.carteira ? (
                  <div className="rounded-xl border-2 border-dashed border-border p-8 text-center">
                    <p className="text-sm text-muted-foreground">
                      Selecione uma carteira para listar as bases disponíveis
                    </p>
                  </div>
                ) : (basesLoading || basesCarteiraLoading) ? (
                  <Skeleton className="h-48" />
                ) : bases.length === 0 ? (
                  <div className="rounded-xl border-2 border-dashed border-border p-8 text-center">
                    <p className="text-sm text-muted-foreground">
                      {formData.carteira
                        ? "Nenhuma base vinculada a esta carteira. Vá em Configurações para vincular bases à carteira."
                        : "Nenhuma base disponível"}
                    </p>
                    {formData.carteira && (
                      <p className="text-xs text-muted-foreground mt-2">
                        Debug: Carteira ID {formData.carteira} | Bases vinculadas: {basesCarteira?.length || 0} | Bases disponíveis: {allBases?.length || 0}
                      </p>
                    )}
                  </div>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-3">
                    {bases.map((base: any) => (
                      <button
                        key={base.id}
                        type="button"
                        onClick={() => {
                          console.log('🔵 [NovaCampanha] Base selecionada:', base.id, base.name);
                          setFormData({ ...formData, base: base.id });
                        }}
                        className={`rounded-xl border-2 p-4 text-left transition-all hover:border-primary/50 w-full ${formData.base === base.id
                          ? "border-primary bg-primary/5"
                          : "border-border"
                          }`}
                      >
                        <p
                          className="font-semibold text-sm truncate w-full"
                          title={base.name}
                          style={{
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                            maxWidth: '100%'
                          }}
                        >
                          {base.name}
                        </p>
                        <p className="text-xs text-muted-foreground mt-1">{base.records} registros</p>
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Alerta de base desatualizada */}
              {formData.base && baseUpdateStatus && !baseUpdateStatus.isUpdated && (
                <Alert variant="destructive">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>
                    <strong>Base desatualizada!</strong> Esta base não foi atualizada hoje.
                    Não é possível criar campanhas com bases desatualizadas.
                    {baseUpdateStatus.message && (
                      <span className="block mt-1 text-xs">{baseUpdateStatus.message}</span>
                    )}
                  </AlertDescription>
                </Alert>
              )}
            </CardContent>
          </>
        )}

        {step === 2 && (
          <>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Filter className="h-5 w-5 text-primary" />
                Filtros Avançados
              </CardTitle>
              <CardDescription>Defina os filtros para segmentar sua base (opcional)</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <FilterBuilder
                availableFilters={availableFilters}
                filters={filters}
                onChange={setFilters}
              />
              <div className="space-y-4">
                <div className="flex items-center justify-between border rounded-lg p-4 bg-card">
                  <div className="space-y-0.5">
                    <Label className="text-base font-semibold">Enviar para acionados</Label>
                    <p className="text-sm text-muted-foreground mr-6">
                      Se ativado, envia para todos. Se desmarcado, remove clientes que já receberam campanhas nas últimas 24h.
                    </p>
                  </div>
                  <div className="flex items-center space-x-3">
                    <span className={`text-sm font-medium ${!formData.exclude_recent_phones ? 'text-primary' : 'text-muted-foreground'}`}>
                      {!formData.exclude_recent_phones ? "Sim" : "Não"}
                    </span>
                    <Switch
                      checked={!formData.exclude_recent_phones}
                      onCheckedChange={(checked) => setFormData({ ...formData, exclude_recent_phones: !checked })}
                      className="scale-110"
                    />
                  </div>
                </div>

                <div className="rounded-lg bg-muted/50 p-4 space-y-2">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Total Bruto (após filtros):</span>
                    <span className="font-semibold">{countLoading ? <Loader2 className="inline h-3 w-3 animate-spin" /> : countData.total.toLocaleString('pt-BR')}</span>
                  </div>

                  {countData.blocked > 0 && (
                    <div className="flex items-center justify-between text-sm text-destructive">
                      <span>Removidos pela Blocklist:</span>
                      <span>- {countData.blocked.toLocaleString('pt-BR')}</span>
                    </div>
                  )}

                  {countData.recent_excluded > 0 && (
                    <div className="flex items-center justify-between text-sm text-yellow-600 dark:text-yellow-500">
                      <span>Removidos (envio recente):</span>
                      <span>- {countData.recent_excluded.toLocaleString('pt-BR')}</span>
                    </div>
                  )}

                  <div className="border-t pt-2 mt-2 flex items-center justify-between text-sm">
                    <span className="font-semibold text-foreground">Total Líquido Estimado:</span>
                    <span className="font-bold text-primary text-base">
                      {countLoading ? (
                        <Loader2 className="inline h-4 w-4 animate-spin" />
                      ) : (
                        countData.effective.toLocaleString('pt-BR')
                      )}
                    </span>
                  </div>
                </div>
              </div></CardContent>
          </>
        )}

        {step === 3 && (
          <>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <MessageSquare className="h-5 w-5 text-primary" />
                Mensagem
              </CardTitle>
              <CardDescription>Selecione ou crie a mensagem da campanha</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label>Template</Label>
                <Select
                  disabled={templatesLoading}
                  value={formData.template}
                  onValueChange={(v) => {
                    console.log('📝 [Template Select] Valor selecionado:', v, 'Tipo:', typeof v);

                    const selectedTemplate = templates.find(t => t.id === v);
                    console.log('📝 [Template Select] Template encontrado:', selectedTemplate);

                    setFormData({
                      ...formData,
                      template: v,
                      templateCode: selectedTemplate?.templateCode || '',
                      templateSource: selectedTemplate?.source || ''
                    });

                    // Só busca conteúdo se for template local
                    if (selectedTemplate?.source === 'local') {
                      console.log('✅ [Template Select] Template local, buscando conteúdo...');
                      refetchTemplate();
                    } else {
                      console.log('ℹ️ [Template Select] Template externo, usando conteúdo pré-carregado');
                      setFormData(prev => ({ ...prev, message: selectedTemplate?.content || '' }));
                    }
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder={templatesLoading ? "Carregando templates..." : "Selecione um template"} />
                  </SelectTrigger>
                  <SelectContent>
                    {templates.map((t, idx) => (
                      <SelectItem key={`template-${t.id || idx}`} value={t.id}>
                        <div className="flex items-center gap-2">
                          <span>{t.name}</span>
                          {t.source === 'otima_wpp' && (
                            <Badge variant="outline" className="text-xs">Ótima WPP</Badge>
                          )}
                          {t.source === 'otima_rcs' && (
                            <Badge variant="outline" className="text-xs">Ótima RCS</Badge>
                          )}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Pré-visualização do Template</Label>
                <div className="rounded-md border bg-gray-50 p-4 min-h-[120px]">
                  {formData.message ? (
                    <p className="text-sm whitespace-pre-wrap text-gray-700">
                      {formData.message}
                    </p>
                  ) : (
                    <p className="text-sm text-gray-400 italic">
                      Selecione um template acima para ver a pré-visualização
                    </p>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  O template selecionado será enviado para os destinatários. Variáveis como {"{nome}"}, {"{cpf}"} serão substituídas automaticamente.
                </p>
              </div>
            </CardContent>
          </>
        )}

        {step === 4 && (
          <>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Loader2 className="h-5 w-5 text-primary" />
                Configuração de Envio
              </CardTitle>
              <CardDescription>Defina a velocidade de disparo da campanha</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-4">
                <Label>Tipo de Envio</Label>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div
                    className={`cursor-pointer rounded-lg border-2 p-4 transition-all ${formData.throttling_type === 'none'
                      ? "border-primary bg-primary/5"
                      : "border-border hover:border-primary/20"
                      }`}
                    onClick={() => setFormData(prev => ({ ...prev, throttling_type: 'none' }))}
                  >
                    <div className="font-semibold mb-1">Imediato</div>
                    <div className="text-sm text-muted-foreground">
                      Envia todas as mensagens o mais rápido possível
                    </div>
                  </div>

                  <div
                    className={`cursor-pointer rounded-lg border-2 p-4 transition-all ${formData.throttling_type === 'linear'
                      ? "border-primary bg-primary/5"
                      : "border-border hover:border-primary/20"
                      }`}
                    onClick={() => setFormData(prev => ({
                      ...prev,
                      throttling_type: 'linear',
                      // Default config for linear
                      throttling_config: prev.throttling_type === 'linear' ? prev.throttling_config : { qtd_msgs: 100, intervalo_minutos: 60 }
                    }))}
                  >
                    <div className="font-semibold mb-1">Linear</div>
                    <div className="text-sm text-muted-foreground">
                      Quantidade fixa de mensagens a cada intervalo de tempo
                    </div>
                  </div>

                  <div
                    className={`cursor-pointer rounded-lg border-2 p-4 transition-all ${formData.throttling_type === 'split'
                      ? "border-primary bg-primary/5 text-primary"
                      : "border-border hover:border-primary/20"
                      }`}
                    onClick={() => setFormData(prev => ({
                      ...prev,
                      throttling_type: 'split',
                      // Default config for split
                      throttling_config: prev.throttling_type === 'split' ? prev.throttling_config : { fase1_percent: 70, fase1_horas: 2, fase2_horas: 4 }
                    }))}
                  >
                    <div className="font-semibold mb-1">Por Etapas (Split)</div>
                    <div className="text-sm text-muted-foreground">
                      Envia X% em Y horas, e o restante em Z horas
                    </div>
                  </div>
                </div>
              </div>

              {formData.throttling_type === 'linear' && (
                <div className="p-4 bg-muted/30 rounded-lg space-y-4 animate-in fade-in slide-in-from-top-2">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label>Quantidade de Mensagens</Label>
                      <Input
                        type="number"
                        min="1"
                        value={formData.throttling_config?.qtd_msgs || 100}
                        onChange={(e) => setFormData(prev => ({
                          ...prev,
                          throttling_config: { ...prev.throttling_config, qtd_msgs: parseInt(e.target.value) || 0 }
                        }))}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label>Intervalo (minutos)</Label>
                      <Input
                        type="number"
                        min="1"
                        value={formData.throttling_config?.intervalo_minutos || 60}
                        onChange={(e) => setFormData(prev => ({
                          ...prev,
                          throttling_config: { ...prev.throttling_config, intervalo_minutos: parseInt(e.target.value) || 0 }
                        }))}
                      />
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    Serão enviadas <strong>{formData.throttling_config?.qtd_msgs || 0}</strong> mensagens a cada <strong>{formData.throttling_config?.intervalo_minutos || 0}</strong> minutos.
                  </p>
                </div>
              )}

              {formData.throttling_type === 'split' && (
                <div className="p-4 bg-muted/30 rounded-lg space-y-4 animate-in fade-in slide-in-from-top-2">
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label>Fase 1: Envio Inicial (%)</Label>
                      <div className="flex items-center gap-4">
                        <Input
                          type="number"
                          min="1"
                          max="99"
                          className="w-24"
                          value={formData.throttling_config?.fase1_percent || 70}
                          onChange={(e) => setFormData(prev => ({
                            ...prev,
                            throttling_config: { ...prev.throttling_config, fase1_percent: parseInt(e.target.value) || 0 }
                          }))}
                        />
                        <span className="text-sm text-muted-foreground">% do total</span>
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div className="space-y-2">
                        <Label>Duração Fase 1 (horas)</Label>
                        <Input
                          type="number"
                          min="0.1"
                          step="0.1"
                          value={formData.throttling_config?.fase1_horas || 2}
                          onChange={(e) => setFormData(prev => ({
                            ...prev,
                            throttling_config: { ...prev.throttling_config, fase1_horas: parseFloat(e.target.value) || 0 }
                          }))}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>Duração Fase 2 (horas)</Label>
                        <Input
                          type="number"
                          min="0.1"
                          step="0.1"
                          value={formData.throttling_config?.fase2_horas || 4}
                          onChange={(e) => setFormData(prev => ({
                            ...prev,
                            throttling_config: { ...prev.throttling_config, fase2_horas: parseFloat(e.target.value) || 0 }
                          }))}
                        />
                      </div>
                    </div>
                  </div>
                  <div className="text-sm text-muted-foreground space-y-1">
                    <p>• <strong>Fase 1:</strong> Envia <strong>{formData.throttling_config?.fase1_percent}%</strong> da base distribuídos em <strong>{formData.throttling_config?.fase1_horas} horas</strong>.</p>
                    <p>• <strong>Fase 2:</strong> Envia o restante (<strong>{100 - (formData.throttling_config?.fase1_percent || 0)}%</strong>) distribuídos nas próximas <strong>{formData.throttling_config?.fase2_horas} horas</strong>.</p>
                  </div>
                </div>
              )}
            </CardContent>
          </>
        )}

        {step === 5 && (
          <>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Truck className="h-5 w-5 text-primary" />
                Fornecedores
              </CardTitle>
              <CardDescription>Selecione os fornecedores para distribuição</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-2">
                {providers.map((provider, idx) => (
                  <label
                    key={`provider-${provider.id || idx}`}
                    className={`flex items-center gap-3 rounded-xl border-2 p-4 cursor-pointer transition-all ${formData.providers.includes(provider.id)
                      ? "border-primary bg-primary/5"
                      : "border-border hover:border-primary/30"
                      } ${!provider.available && "opacity-50 cursor-not-allowed"}`}
                  >
                    <Checkbox
                      checked={formData.providers.includes(provider.id)}
                      onCheckedChange={() => provider.available && handleProviderToggle(provider.id)}
                      disabled={!provider.available}
                    />
                    <div>
                      <p className="font-semibold">{provider.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {provider.available ? "Disponível" : "Indisponível"}
                      </p>
                    </div>
                  </label>
                ))}
              </div>
              <div className="rounded-lg bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">
                  <span className="font-semibold text-foreground">Distribuição:</span>{" "}
                  {formData.providers.length > 0
                    ? `Igual entre ${formData.providers.length} fornecedor(es) selecionado(s)`
                    : "Selecione pelo menos um fornecedor"}
                </p>
              </div>

              {/* Opção para incluir iscas */}
              <div className="rounded-lg border-2 border-dashed border-border p-4 space-y-3">
                <div className="flex items-center gap-3">
                  <Checkbox
                    id="include-baits"
                    checked={formData.include_baits}
                    onCheckedChange={(checked) => setFormData({ ...formData, include_baits: !!checked })}
                  />
                  <div className="flex-1">
                    <label htmlFor="include-baits" className="font-semibold cursor-pointer">
                      Incluir iscas de teste
                    </label>
                    <p className="text-xs text-muted-foreground mt-1">
                      Adiciona automaticamente todos os números cadastrados como iscas nesta campanha
                    </p>
                  </div>
                </div>

                {/* Lista de Iscas Renderizada Condicionalmente */}
                {formData.include_baits && baitsData.length > 0 && (
                  <div className="mt-4 p-3 bg-muted rounded-md text-sm">
                    <p className="font-semibold mb-2">Serão enviadas cópias para {baitsData.length} isca(s):</p>
                    <ul className="list-disc list-inside text-muted-foreground space-y-1">
                      {baitsData.slice(0, 10).map((isca: any, idx: number) => (
                        <li key={idx} className="truncate">
                          {isca.telefone} {isca.nome ? `- ${isca.nome}` : ''}
                        </li>
                      ))}
                      {baitsData.length > 10 && (
                        <li className="italic">+ {baitsData.length - 10} outras iscas.</li>
                      )}
                    </ul>
                  </div>
                )}
                {formData.include_baits && baitsData.length === 0 && !baitsLoading && (
                  <div className="mt-4 p-3 bg-destructive/10 text-destructive rounded-md text-sm border border-destructive/20">
                    <p>Você não possui enables ativas cadastradas no sistema. Nenhuma isca será enviada.</p>
                  </div>
                )}
              </div>

              {/* Opção para Salvar Filtro (Antiga Campanha Recorrente) */}
              <div className="rounded-lg border border-border p-4 bg-muted/20">
                <div className="flex items-center gap-3">
                  <Switch
                    id="save-filter"
                    checked={formData.is_recurring}
                    onCheckedChange={(checked) => setFormData({ ...formData, is_recurring: checked })}
                  />
                  <div className="flex-1">
                    <label htmlFor="save-filter" className="font-semibold cursor-pointer">
                      Salvar como Filtro Salvo
                    </label>
                    <p className="text-xs text-muted-foreground mt-1">
                      Salva esta configuração de público (filtros), template, iscas e envio para ser gerada manualmente depois, com estimativa de volume em tempo real na tela de Filtros Salvos.
                    </p>
                  </div>
                </div>
              </div>
            </CardContent>
          </>
        )}

        {/* Navigation */}
        <CardContent className="flex justify-between border-t pt-6">
          <Button
            variant="outline"
            onClick={() => setStep(Math.max(1, step - 1))}
            disabled={step === 1 || scheduleMutation.isPending}
          >
            Voltar
          </Button>
          {step < 5 ? (
            <Button
              onClick={() => setStep(step + 1)}
              disabled={!canGoNext()}
              className="gradient-primary hover:opacity-90"
            >
              Próximo
            </Button>
          ) : (
            <Button
              onClick={handleSubmit}
              disabled={scheduleMutation.isPending || saveRecurringMutation.isPending || !canGoNext()}
              className="gradient-primary hover:opacity-90"
            >
              {scheduleMutation.isPending || saveRecurringMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Salvando...
                </>
              ) : formData.is_recurring ? (
                <>
                  <Database className="mr-2 h-4 w-4" />
                  Salvar Filtro
                </>
              ) : (
                <>
                  <Send className="mr-2 h-4 w-4" />
                  Criar Campanha
                </>
              )}
            </Button>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
