import { useState, useEffect, useMemo } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Upload, FileText, CheckCircle, AlertCircle, Loader2, X, Check, ChevronsUpDown } from "lucide-react";
import { TemplateVariableMapper, VarMapping, extractVariables, resolveVariables } from "@/components/campaign/TemplateVariableMapper";
import { RcsMessagePreview } from "@/components/campaign/RcsMessagePreview";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Checkbox } from "@/components/ui/checkbox";
import { useToast } from "@/hooks/use-toast";
import {
  uploadCampaignFile,
  getMessages,
  previewCount,
  createCpfCampaign,
  getAvailableBases,
  getCarteiras,
  getBasesCarteira,
  checkBaseUpdate,
  getOtimaTemplates,
  getOtimaBrokers,
  getTemplatesByWallet,
} from "@/lib/api";

const providers = [
  { id: "OTIMA_RCS", name: "Ótima RCS" },
  { id: "OTIMA_WPP", name: "Ótima WPP" },
  { id: "CDA_RCS", name: "CDA RCS" },
  { id: "CDA", name: "CDA" },
  { id: "GOSAC", name: "GOSAC" },
  { id: "GOSAC_OFICIAL", name: "Gosac Oficial" },
  { id: "NOAH", name: "NOAH" },
  { id: "NOAH_OFICIAL", name: "Noah Oficial" },
  { id: "ROBBU_OFICIAL", name: "Robbu Oficial" },
  { id: "RCS", name: "RCS" },
  { id: "SALESFORCE", name: "Salesforce" },
];

export default function CampanhaArquivo() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [tempId, setTempId] = useState<string>("");
  const [matchField, setMatchField] = useState<"cpf" | "telefone">("cpf");
  const [recordCount, setRecordCount] = useState(0);
  const [template, setTemplate] = useState("");
  const [brokerCode, setBrokerCode] = useState("");
  const [provider, setProvider] = useState("");
  const [carteira, setCarteira] = useState("");
  const [tableName, setTableName] = useState("");
  const [includeBaits, setIncludeBaits] = useState(false);
  const [showAlreadySent, setShowAlreadySent] = useState(false);
  const [baseUpdateStatus, setBaseUpdateStatus] = useState<{ isUpdated: boolean; message: string } | null>(null);
  const [templateVariables, setTemplateVariables] = useState<Record<string, VarMapping>>({});
  const [selectedTemplateObj, setSelectedTemplateObj] = useState<any>(null);
  const [openTemplateDropdown, setOpenTemplateDropdown] = useState(false);

  // Buscar carteiras (deve vir antes do useMemo que a usa)
  const { data: carteiras = [] } = useQuery({
    queryKey: ['carteiras'],
    queryFn: getCarteiras,
  });

  // Buscar templates de mensagem locais
  const { data: localTemplatesData = [], isLoading: localTemplatesLoading } = useQuery({
    queryKey: ['messages'],
    queryFn: getMessages,
  });

  // Buscar templates Ótima sob demanda (apenas após selecionar carteira)
  const selectedCarteiraObj = (carteiras as any[]).find((c: any) => String(c.id) === String(carteira));
  const walletIdForOtima = selectedCarteiraObj?.id_carteira ? String(selectedCarteiraObj.id_carteira) : undefined;

  const { data: otimaTemplatesData = [], isLoading: otimaTemplatesLoading } = useQuery({
    queryKey: ['otima-templates', walletIdForOtima],
    queryFn: () => getOtimaTemplates(walletIdForOtima),
    enabled: !!walletIdForOtima,
    staleTime: 5 * 60 * 1000,
  });

  // Buscar brokers Ótima (WPP + RCS)
  const { data: otimaBrokersData = [], isLoading: otimaBrokersLoading } = useQuery({
    queryKey: ['otima-brokers'],
    queryFn: getOtimaBrokers,
    staleTime: 5 * 60 * 1000,
  });

  // Buscar templates externos (GOSAC Oficial + NOAH Oficial) por carteira
  const { data: externalTemplatesData = [], isLoading: externalTemplatesLoading } = useQuery({
    queryKey: ['external-templates', carteira],
    queryFn: () => getTemplatesByWallet(carteira),
    enabled: !!carteira,
  });

  // Processar e mesclar templates
  const templates = useMemo(() => {
    // Templates Locais
    const local = (localTemplatesData || []).map((t: any) => ({
      id: String(t.id),
      name: t.title || '',
      source: t.source || 'local',
      provider: t.provider || null,
      walletId: t.wallet_id || null,
      templateCode: t.template_code || t.template_id || '',
      walletName: null,
    }));

    // Templates Ótima (já vem com wallet_id do backend)
    const otima = Array.isArray(otimaTemplatesData) ? otimaTemplatesData.map((t: any) => ({
      id: `otima_${t.template_code}_${t.wallet_id}`,
      name: t.name || t.template_code || '',
      source: t.source || 'otima_rcs', // Backend retorna otima_rcs ou otima_wpp
      templateCode: t.template_code || '',
      brokerCode: t.broker_code || '',
      customerCode: t.customer_code || '',
      walletId: t.wallet_id,
      walletName: t.wallet_name,
      imageUrl: t.image_url || null,
      content: t.content || '',
      raw_data: t.raw_data || t,
    })) : [];

    // Templates Externos (GOSAC Oficial + NOAH Oficial)
    const external = Array.isArray(externalTemplatesData) ? externalTemplatesData.map((t: any) => {
      const isGosac = t.provider === 'Gosac Oficial';
      const isNoah = t.provider === 'Noah Oficial';
      const isRobbu = t.provider === 'Robbu Oficial';
      const source = isGosac ? 'gosac_oficial' : (isNoah ? 'noah_oficial' : (isRobbu ? 'robbu_oficial' : (t.source || 'external')));
      return {
        id: `${t.provider}_${t.id}_${t.id_ambient}`,
        name: t.name || t.id || '',
        source,
        templateCode: t.templateName || t.name || '',
        walletId: t.id_ambient,
        walletName: t.wallet_name || `${t.provider} (${t.id_ambient})`,
        channelId: t.channelId,
        templateId: t.templateId,
        templateName: t.templateName || t.name,
        language: t.language || 'pt_BR',
      };
    }) : [];

    console.log('📋 [CampanhaArquivo] Templates locais:', local.length);
    console.log('📋 [CampanhaArquivo] Templates Ótima:', otima.length);
    console.log('📋 [CampanhaArquivo] Templates GOSAC/NOAH:', external.length);

    const selectedWallet = carteira
      ? (carteiras as any[]).find((c: any) => String(c.id) === String(carteira))
      : null;
    const walletCode = selectedWallet?.id_carteira ? String(selectedWallet.id_carteira) : null;

    // Ótima templates only make sense for OTIMA_RCS / OTIMA_WPP providers.
    const OTIMA_PROVIDERS = ['OTIMA_RCS', 'OTIMA_WPP'];
    const otimaProviderSelected = !provider || OTIMA_PROVIDERS.includes(provider);

    // Filter Ótima templates: must match provider type AND wallet
    const otimaFiltrados = otimaProviderSelected
      ? otima.filter(t => {
        if (carteira && walletCode) {
          return String(t.walletId) === walletCode || String(t.customerCode) === walletCode;
        }
        return true;
      })
      : [];

    // GOSAC/NOAH/ROBBU templates: show when provider is GOSAC_OFICIAL/NOAH_OFICIAL/ROBBU_OFICIAL or none selected
    const EXTERNAL_PROVIDERS = ['GOSAC_OFICIAL', 'NOAH_OFICIAL', 'ROBBU_OFICIAL'];
    const externalProviderSelected = !provider || EXTERNAL_PROVIDERS.includes(provider);
    const externalFiltrados = externalProviderSelected
      ? external.filter(t => {
        if (carteira && walletCode) {
          return String(t.walletId) === walletCode;
        }
        return true;
      })
      : [];

    console.log('📋 [CampanhaArquivo] Templates Ótima filtrados:', otimaFiltrados.length);
    console.log('📋 [CampanhaArquivo] Templates GOSAC/NOAH filtrados:', externalFiltrados.length);

    // Filter local templates: show if no metadata (backward compat)
    // else strict match on provider AND wallet
    const noneSelected = !provider;
    const localFiltrados = local.filter((t: any) => {
      const hasProviderMeta = !!t.provider;
      const hasWalletMeta = !!t.walletId;

      if (!hasProviderMeta && !hasWalletMeta) return true; // backward compat

      const providerMatch = !hasProviderMeta || noneSelected || t.provider === provider;
      const walletMatch = !hasWalletMeta || !walletCode || String(t.walletId) === walletCode;

      return providerMatch && walletMatch;
    });

    return [...localFiltrados, ...otimaFiltrados, ...externalFiltrados];
  }, [localTemplatesData, otimaTemplatesData, externalTemplatesData, carteira, carteiras, provider]);

  const templatesLoading = localTemplatesLoading || otimaTemplatesLoading || externalTemplatesLoading || otimaBrokersLoading;

  // Buscar bases da carteira selecionada
  const { data: basesCarteira = [] } = useQuery({
    queryKey: ['bases-carteira', carteira],
    queryFn: () => getBasesCarteira(carteira),
    enabled: !!carteira,
  });

  // Buscar todas as bases disponíveis (dados completos)
  const { data: allBases = [], isLoading: basesLoading } = useQuery({
    queryKey: ['available-bases'],
    queryFn: getAvailableBases,
  });

  // Bases filtradas por carteira
  // Backend agora retorna array simples de strings: ['base1', 'base2', ...]
  const bases = carteira
    ? (basesCarteira.length > 0
      ? allBases.filter((base: any) => {
        const baseName = (base.name || '').trim().toLowerCase();
        // basesCarteira agora é array de strings
        return basesCarteira.some((bc: string) =>
          bc.trim().toLowerCase() === baseName
        );
      })
      : [])
    : [];

  // Verificar atualização da base quando selecionada
  const { data: baseUpdateData } = useQuery({
    queryKey: ['base-update', tableName],
    queryFn: () => checkBaseUpdate(tableName),
    enabled: !!tableName,
  });

  useEffect(() => {
    if (baseUpdateData) {
      setBaseUpdateStatus({
        isUpdated: baseUpdateData.is_updated,
        message: baseUpdateData.message || '',
      });
    }
  }, [baseUpdateData]);

  const uploadMutation = useMutation({
    mutationFn: ({ file, matchField }: { file: File; matchField: string }) =>
      uploadCampaignFile(file, matchField),
    onSuccess: (data: any) => {
      setTempId(data.temp_id);
      setRecordCount(data.count || 0);
      setMatchField(data.match_field || 'cpf');
      toast({
        title: "Arquivo validado com sucesso!",
        description: `${data.count} registros encontrados no arquivo.`,
      });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao validar arquivo",
        description: error.message || "Erro ao fazer upload do arquivo",
        variant: "destructive",
      });
    },
  });

  const previewMutation = useMutation({
    mutationFn: (data: any) => previewCount(data),
    onSuccess: (data: any) => {
      setRecordCount(data.count || 0);
      toast({
        title: "Preview atualizado",
        description: `${data.count} registros após aplicar filtros.`,
      });
    },
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => createCpfCampaign(data),
    onSuccess: () => {
      toast({
        title: "Campanha criada com sucesso!",
        description: `${recordCount.toLocaleString("pt-BR")} registros serão processados.`,
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

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (!selectedFile) return;

    if (!selectedFile.name.endsWith('.csv')) {
      toast({
        title: "Formato inválido",
        description: "Apenas arquivos CSV são permitidos",
        variant: "destructive",
      });
      return;
    }

    setFile(selectedFile);
    uploadMutation.mutate({ file: selectedFile, matchField });
  };

  const removeFile = () => {
    setFile(null);
    setTempId("");
    setRecordCount(0);
  };

  const handleSubmit = async () => {
    if (!file || !tempId) {
      toast({
        title: "Arquivo obrigatório",
        description: "Por favor, faça upload de um arquivo CSV válido",
        variant: "destructive",
      });
      return;
    }

    if (!template) {
      toast({
        title: "Template obrigatório",
        description: "Por favor, selecione um template de mensagem",
        variant: "destructive",
      });
      return;
    }

    const selectedTemplate = templates.find((t) => t.id === template);

    if ((selectedTemplate?.source === 'otima_rcs' || selectedTemplate?.source === 'otima_wpp') && !brokerCode) {
      toast({
        title: "Broker obrigatório",
        description: "Por favor, selecione um broker da Ótima",
        variant: "destructive",
      });
      return;
    }

    if (!provider) {
      toast({
        title: "Fornecedor obrigatório",
        description: "Por favor, selecione um fornecedor",
        variant: "destructive",
      });
      return;
    }

    if (!carteira) {
      toast({
        title: "Carteira obrigatória",
        description: "Selecione uma carteira para listar a base",
        variant: "destructive",
      });
      return;
    }

    if (!tableName) {
      toast({
        title: "Base obrigatória",
        description: "Por favor, informe o nome da tabela base",
        variant: "destructive",
      });
      return;
    }

    if (baseUpdateStatus && !baseUpdateStatus.isUpdated) {
      toast({
        title: "Base desatualizada",
        description: "Atualize a base antes de criar a campanha",
        variant: "destructive",
      });
      return;
    }

    // template_source: PHP exige otima_rcs/otima_wpp para Ótima, gosac_oficial/noah_oficial para GOSAC/NOAH
    const isOtimaTemplate = selectedTemplate?.source === 'otima_rcs' || selectedTemplate?.source === 'otima_wpp' || selectedTemplate?.source === 'otima';
    const templateSource = isOtimaTemplate
      ? (provider === 'OTIMA_WPP' ? 'otima_wpp' : 'otima_rcs')
      : (selectedTemplate?.source || 'local');

    const payload: Record<string, any> = {
      temp_id: tempId,
      table_name: tableName,
      carteira: carteira || '',
      template_id: selectedTemplate?.source === 'local' ? parseInt(template) : null,
      template_code: selectedTemplate?.templateCode || null,
      template_source: templateSource,
      broker_code: brokerCode || selectedTemplate?.brokerCode || null,
      customer_code: selectedTemplate?.customerCode || null,
      variables_map: Object.keys(templateVariables).length > 0 ? templateVariables : null,
      provider: provider.toUpperCase(),
      match_field: matchField,
      include_baits: includeBaits ? 1 : 0,
      show_already_sent: showAlreadySent ? 1 : 0,
    };

    if (templateSource === 'noah_oficial' && selectedTemplate) {
      payload.noah_channel_id = selectedTemplate.channelId ?? '';
      payload.noah_template_id = selectedTemplate.templateId ?? '';
      payload.noah_language = selectedTemplate.language ?? 'pt_BR';
    }
    if (templateSource === 'robbu_oficial') {
      payload.robbu_channel = 3;
    }

    createMutation.mutate(payload);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Campanha via Arquivo"
        description="Crie uma campanha através de upload de arquivo CSV"
      />

      <Alert>
        <AlertCircle className="h-4 w-4" />
        <AlertDescription>
          <strong>Formato do arquivo CSV:</strong> O arquivo deve conter as colunas: <strong>nome</strong>, <strong>telefone</strong> (obrigatório: formato 55 + DDD + Número, ex: 5511999999999), <strong>cpf</strong> (obrigatório: pelo menos 11 dígitos).
          Colunas opcionais: <strong>carteira</strong>, <strong>contrato</strong>, <strong>id_carteira</strong>.
        </AlertDescription>
      </Alert>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Upload Section */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Upload className="h-5 w-5 text-primary" />
              Upload de Arquivo
            </CardTitle>
            <CardDescription>
              Envie um arquivo CSV com os dados dos clientes
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {!file ? (
              <label className="flex flex-col items-center justify-center h-48 border-2 border-dashed border-border rounded-xl cursor-pointer hover:border-primary/50 hover:bg-muted/50 transition-colors">
                <Upload className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm font-medium text-foreground">
                  Clique para selecionar ou arraste o arquivo
                </p>
                <p className="text-xs text-muted-foreground mt-1">
                  CSV com colunas: telefone (55+DDD+Número), cpf, nome (obrigatórios)
                </p>
                <input
                  type="file"
                  accept=".csv"
                  onChange={handleFileChange}
                  className="hidden"
                  disabled={uploadMutation.isPending}
                />
              </label>
            ) : (
              <div className="space-y-4">
                <div className="flex items-center justify-between p-4 rounded-xl bg-muted/50 border">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <FileText className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="font-medium text-sm">{file.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {(file.size / 1024).toFixed(1)} KB
                      </p>
                    </div>
                  </div>
                  <Button variant="ghost" size="icon" onClick={removeFile} disabled={uploadMutation.isPending}>
                    <X className="h-4 w-4" />
                  </Button>
                </div>

                {uploadMutation.isPending && (
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    <span className="text-sm">Validando arquivo...</span>
                  </div>
                )}

                {uploadMutation.isSuccess && tempId && (
                  <div className="flex items-center gap-2 p-3 rounded-lg bg-success/10 text-success">
                    <CheckCircle className="h-4 w-4" />
                    <div className="flex-1">
                      <p className="text-sm font-medium">Arquivo válido!</p>
                      <p className="text-xs">
                        {recordCount.toLocaleString('pt-BR')} registros encontrados
                      </p>
                    </div>
                  </div>
                )}

                {uploadMutation.isError && (
                  <div className="flex items-center gap-2 p-3 rounded-lg bg-destructive/10 text-destructive">
                    <AlertCircle className="h-4 w-4" />
                    <p className="text-sm">
                      {uploadMutation.error instanceof Error
                        ? uploadMutation.error.message
                        : "Erro ao validar arquivo"}
                    </p>
                  </div>
                )}
              </div>
            )}

            <div className="space-y-2">
              <Label>Tipo de Cruzamento</Label>
              <Select value={matchField} onValueChange={(v: "cpf" | "telefone") => setMatchField(v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="cpf">CPF</SelectItem>
                  <SelectItem value="telefone">Telefone</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* Configuration Section */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileText className="h-5 w-5 text-primary" />
              Configuração
            </CardTitle>
            <CardDescription>
              Configure os detalhes da campanha
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label>Carteira <span className="text-red-500">*</span></Label>
              <Select
                value={carteira || undefined}
                onValueChange={(value) => {
                  setCarteira(value);
                  setTableName("");
                  setBaseUpdateStatus(null);
                }}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Selecione a carteira para filtrar as bases" />
                </SelectTrigger>
                <SelectContent>
                  {carteiras.map((c: any) => (
                    <SelectItem key={c.id} value={c.id}>
                      {c.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                As bases exibidas serão apenas as vinculadas à carteira selecionada
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="table-name">Tabela Base <span className="text-red-500">*</span></Label>
              {!carteira ? (
                <div className="rounded-xl border-2 border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                  Selecione uma carteira para listar as bases disponíveis
                </div>
              ) : basesLoading ? (
                <div className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-muted-foreground">
                  Carregando bases...
                </div>
              ) : (
                <Select value={tableName} onValueChange={(value) => {
                  setTableName(value);
                  setBaseUpdateStatus(null);
                }}>
                  <SelectTrigger id="table-name">
                    <SelectValue placeholder="Selecione a tabela base" />
                  </SelectTrigger>
                  <SelectContent>
                    {bases.length === 0 ? (
                      <div className="p-3 text-sm text-muted-foreground">
                        Nenhuma base vinculada a esta carteira
                      </div>
                    ) : (
                      bases.map((base: any) => (
                        <SelectItem key={base.id} value={base.id}>
                          {base.name} ({base.records} registros)
                        </SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
              )}
              <p className="text-xs text-muted-foreground">
                Tabela base para cruzamento dos dados do arquivo
              </p>
            </div>

            {tableName && baseUpdateStatus && !baseUpdateStatus.isUpdated && (
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

            <div className="space-y-2">
              <Label>Template de Mensagem <span className="text-red-500">*</span></Label>
              {(provider === 'OTIMA_RCS' || provider === 'OTIMA_WPP') && !carteira && (
                <p className="text-xs text-muted-foreground">Selecione uma carteira para carregar templates Ótima.</p>
              )}
              <Popover open={openTemplateDropdown} onOpenChange={setOpenTemplateDropdown}>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={openTemplateDropdown}
                    className="w-full justify-between font-normal"
                  >
                    {template
                      ? templates.find((t) => t.id === template)?.name || "Template Selecionado"
                      : templatesLoading ? "Carregando templates..." : "Selecione um template..."}
                    {templatesLoading && <Loader2 className="ml-2 h-4 w-4 shrink-0 animate-spin" />}
                    {!templatesLoading && <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                  <Command>
                    <CommandInput placeholder="Buscar template..." disabled={templatesLoading} />
                    <CommandList>
                      {templatesLoading ? (
                        <div className="py-6 px-4 flex items-center justify-center gap-2 text-muted-foreground">
                          <Loader2 className="h-4 w-4 animate-spin" />
                          <span>Carregando templates...</span>
                        </div>
                      ) : (
                        <>
                      <CommandEmpty>Nenhum template encontrado.</CommandEmpty>
                      <CommandGroup>
                          {templates.map((t) => {
                            const isOtima = t.source === 'otima_rcs' || t.source === 'otima_wpp';
                            return (
                              <CommandItem
                                key={t.id}
                                value={t.name}
                                onSelect={() => {
                                  setTemplate(t.id);
                                  const selectedTpl = templates.find((tpl) => tpl.id === t.id) as any;
                                  if (selectedTpl?.brokerCode) {
                                    setBrokerCode(selectedTpl.brokerCode);
                                  }
                                  // Save for preview
                                  setSelectedTemplateObj(selectedTpl ?? null);
                                  // Extract variables from content
                                  const rawData = selectedTpl?.raw_data || {};
                                  const rc = rawData.rich_card || rawData.richCard || {};
                                  const rawContent = [
                                    selectedTpl?.content,
                                    rawData.text,
                                    rawData.description,
                                    rc.title,
                                    rc.description,
                                    rc.text
                                  ].filter(Boolean).join(' ');
                                  const detectedVars = extractVariables(rawContent);
                                  const initMap: Record<string, VarMapping> = {};
                                  detectedVars.forEach(vVar => {
                                    initMap[vVar] = { type: 'field', value: 'nome' };
                                  });
                                  setTemplateVariables(initMap);
                                  setOpenTemplateDropdown(false);
                                }}
                              >
                                <Check
                                  className={cn(
                                    "mr-2 h-4 w-4",
                                    template === t.id ? "opacity-100" : "opacity-0"
                                  )}
                                />
                                <div className="flex flex-col">
                                  <span className="font-medium text-sm truncate max-w-[400px]" title={t.name}>
                                    {t.name}
                                  </span>
                                  {isOtima && (
                                    <span className="text-[10px] text-muted-foreground flex items-center gap-1">
                                      {t.source === 'otima_rcs' ? 'Ótima RCS' : 'Ótima WPP'}
                                    </span>
                                  )}
                                </div>
                              </CommandItem>
                            );
                          })}
                        </CommandGroup>
                        </>
                      )}
                      </CommandList>
                    </Command>
                  </PopoverContent>
                </Popover>
            </div>

            {(() => {
              const selectedTpl = templates.find((t) => t.id === template);
              const isOtima = selectedTpl?.source === 'otima_rcs' || selectedTpl?.source === 'otima_wpp';
              if (isOtima) {
                return (
                  <div className="space-y-2 animate-in fade-in slide-in-from-top-2">
                    <Label>Broker Ótima <span className="text-red-500">*</span></Label>
                    <Select
                      disabled={otimaBrokersLoading}
                      value={brokerCode}
                      onValueChange={setBrokerCode}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder={otimaBrokersLoading ? "Carregando brokers..." : "Selecione o broker para envio"} />
                      </SelectTrigger>
                      <SelectContent>
                        {Array.isArray(otimaBrokersData) && otimaBrokersData.length === 0 && !otimaBrokersLoading && (
                          <div className="py-2 px-3 text-xs text-muted-foreground italic">
                            Nenhum broker encontrado. Verifique as credenciais.
                          </div>
                        )}
                        {Array.isArray(otimaBrokersData) && otimaBrokersData.map((b: any, idx: number) => {
                          const isRcs = String(b.name).toLowerCase().includes('rcs');
                          const isWpp = String(b.name).toLowerCase().includes('wpp') || String(b.name).toLowerCase().includes('whatsapp');
                          return (
                            <SelectItem key={`broker-${b.code || idx}`} value={b.code}>
                              <div className="flex items-center gap-1.5">
                                {isRcs && <Badge className="text-[10px] py-0 px-1 bg-blue-500 text-white shrink-0">RCS</Badge>}
                                {isWpp && <Badge variant="outline" className="text-[10px] py-0 px-1 shrink-0">WPP</Badge>}
                                <span>{b.name} ({b.code})</span>
                              </div>
                            </SelectItem>
                          );
                        })}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                      Selecione qual canal/broker da Ótima será usado para o envio desta campanha.
                    </p>
                  </div>
                );
              }
              return null;
            })()}

            {/* Variable Mapper — only for Ótima templates */}
            {(() => {
              const selectedTpl = templates.find((t) => t.id === template) as any;
              const isOtima = selectedTpl?.source === 'otima_rcs' || selectedTpl?.source === 'otima_wpp';
              if (!isOtima) return null;
              return (
                <TemplateVariableMapper
                  variables={Object.keys(templateVariables)}
                  mapping={templateVariables}
                  onChange={setTemplateVariables}
                />
              );
            })()}

            <div className="space-y-2">
              <Label>Fornecedor <span className="text-red-500">*</span></Label>
              <Select value={provider} onValueChange={setProvider}>
                <SelectTrigger>
                  <SelectValue placeholder="Selecione um fornecedor" />
                </SelectTrigger>
                <SelectContent>
                  {providers.map((p) => (
                    <SelectItem key={p.id} value={p.id}>
                      {p.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {tempId && (
              <div className="rounded-lg bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">
                  <span className="font-semibold text-foreground">Registros a processar:</span>{" "}
                  {recordCount.toLocaleString("pt-BR")}
                </p>
              </div>
            )}

            {/* Opção para incluir iscas */}
            <div className="rounded-lg border-2 border-dashed border-border p-4 space-y-3">
              <div className="flex items-center gap-3">
                <Checkbox
                  id="include-baits-file"
                  checked={includeBaits}
                  onCheckedChange={(checked) => setIncludeBaits(!!checked)}
                />
                <div className="flex-1">
                  <label htmlFor="include-baits-file" className="font-semibold cursor-pointer">
                    Incluir iscas de teste
                  </label>
                  <p className="text-xs text-muted-foreground mt-1">
                    Adiciona automaticamente todos os números cadastrados como iscas nesta campanha
                  </p>
                </div>
              </div>
            </div>

            {/* Filtro Adicional: Mostrar já enviados */}
            <div className="space-y-2">
              <Label>Filtros Adicionais</Label>
              <div className="flex items-center gap-3 p-4 rounded-lg border border-border">
                <Checkbox
                  id="show-already-sent"
                  checked={showAlreadySent}
                  onCheckedChange={(checked) => setShowAlreadySent(!!checked)}
                />
                <label htmlFor="show-already-sent" className="font-medium text-sm cursor-pointer">
                  Mostrar registros já enviados (ignorar bloqueio de 24h)
                </label>
              </div>
            </div>

            <Button
              onClick={handleSubmit}
              disabled={
                !file ||
                !tempId ||
                !template ||
                !provider ||
                !tableName ||
                createMutation.isPending ||
                (baseUpdateStatus && !baseUpdateStatus.isUpdated)
              }
              className="w-full gradient-primary hover:opacity-90"
            >
              {createMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Criando campanha...
                </>
              ) : (
                <>
                  <FileText className="mr-2 h-4 w-4" />
                  Criar Campanha
                </>
              )}
            </Button>
          </CardContent>
        </Card>
      </div>

      {/* RCS Preview — full width, only for Ótima templates */}
      {selectedTemplateObj && (() => {
        const selectedTpl = templates.find((t) => t.id === template) as any;
        const isOtima = selectedTpl?.source === 'otima_rcs' || selectedTpl?.source === 'otima_wpp';
        if (!isOtima) return null;
        return (
          <div className="flex justify-center py-4">
            <RcsMessagePreview
              template={selectedTemplateObj}
              resolvedVariables={resolveVariables(templateVariables)}
              channel={selectedTpl?.source === 'otima_rcs' ? 'rcs' : 'wpp'}
            />
          </div>
        );
      })()}
    </div>
  );
}
