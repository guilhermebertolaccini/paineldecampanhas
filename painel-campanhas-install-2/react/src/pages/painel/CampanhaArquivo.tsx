import { useState, useEffect, useMemo, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Upload, FileText, CheckCircle, AlertCircle, Loader2, X, Check, ChevronsUpDown, RefreshCw } from "lucide-react";
import {
  TemplateVariableMapper,
  VarMapping,
  DB_FIELDS,
  extractVariables,
  resolveVariables,
  collectPlaceholdersSourceText,
  buildInitialVariableMappingFromOtimaWpp,
  listOtimaWppVariableKeysFromTemplate,
  buildInitialVariableMappingFromNoahOfficial,
  listNoahOfficialVariableKeysFromTemplate,
  extractNoahNumericPlaceholderKeys,
  isNoahOfficialTemplateSource,
  buildNoahOfficialTemplatePreviewMessage,
} from "@/components/campaign/TemplateVariableMapper";
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
  createCpfCampaign,
  getAvailableBases,
  getCarteiras,
  getBasesCarteira,
  checkBaseUpdate,
  getOtimaTemplates,
  getOtimaBrokers,
  getTemplatesByWallet,
  getGosacOficialTemplates,
  getGosacOficialConnections,
  getNoahOficialChannels,
  getRobbuOficialTemplates,
  getIscas,
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
  { id: "TECH_IA", name: "TECHIA (Discador)" },
  { id: "RCS", name: "RCS" },
  { id: "SALESFORCE", name: "Salesforce" },
];

const PROVIDER_TO_SOURCE_MAP: Record<string, string[]> = {
  GOSAC_OFICIAL: ["gosac_oficial"],
  OTIMA_WPP: ["otima_wpp"],
  OTIMA_RCS: ["otima_rcs"],
  SALESFORCE: [],
  CDA: [],
  CDA_RCS: [],
  GOSAC: [],
  NOAH: [],
  NOAH_OFICIAL: ["noah_oficial"],
  ROBBU_OFICIAL: ["robbu_oficial"],
  TECH_IA: [],
  RCS: [],
};

/** Campos do discador TECHIA — mapeamento fixo (sem template). */
const TECHIA_FIXED_VARIABLE_KEYS = [
  "documento",
  "nome",
  "contrato",
  "valor",
  "atraso",
  "COD_DEPARA",
  "campanha_origem",
] as const;

async function readCsvHeadersFromFile(file: File): Promise<string[]> {
  const blob = file.slice(0, Math.min(file.size, 262144));
  const text = await blob.text();
  const firstLine = text.split(/\r?\n/).find((l) => l.trim().length > 0);
  if (!firstLine) return [];
  const semi = (firstLine.match(/;/g) || []).length;
  const comma = (firstLine.match(/,/g) || []).length;
  const delim = semi > comma ? ";" : ",";
  const cells = firstLine.split(delim).map((c) => c.trim().replace(/^"|"$/g, ""));
  return [...new Set(cells.filter(Boolean))];
}

export default function CampanhaArquivo() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const otimaTemplatesErrorShownRef = useRef<unknown>(null);
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
  const [selectedBaitIds, setSelectedBaitIds] = useState<number[]>([]);
  const [showAlreadySent, setShowAlreadySent] = useState(false);
  const [baseUpdateStatus, setBaseUpdateStatus] = useState<{ isUpdated: boolean; message: string } | null>(null);
  const [templateVariables, setTemplateVariables] = useState<Record<string, VarMapping>>({});
  const [selectedTemplateObj, setSelectedTemplateObj] = useState<any>(null);
  const [openTemplateDropdown, setOpenTemplateDropdown] = useState(false);
  const [gosacConnectionId, setGosacConnectionId] = useState<string>("");
  const [noahChannelId, setNoahChannelId] = useState<string>("");
  const [campaignName, setCampaignName] = useState("");
  const [csvHeaders, setCsvHeaders] = useState<string[]>([]);

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
  const rawWallet = selectedCarteiraObj?.id_carteira;
  const walletIdForOtima =
    rawWallet != null && String(rawWallet).trim() !== '' ? String(rawWallet).trim() : undefined;

  const {
    data: otimaTemplatesData = [],
    isLoading: otimaTemplatesLoading,
    isFetching: otimaTemplatesFetching,
    isError: otimaTemplatesIsError,
    error: otimaTemplatesErr,
  } = useQuery({
    queryKey: ['otima-templates', walletIdForOtima, carteira],
    queryFn: () => getOtimaTemplates(walletIdForOtima, carteira),
    enabled: !!carteira && (provider === "OTIMA_WPP" || provider === "OTIMA_RCS"),
    staleTime: 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });

  useEffect(() => {
    if (!otimaTemplatesIsError || !otimaTemplatesErr) {
      otimaTemplatesErrorShownRef.current = null;
      return;
    }
    if (otimaTemplatesErrorShownRef.current === otimaTemplatesErr) return;
    otimaTemplatesErrorShownRef.current = otimaTemplatesErr;
    const msg =
      otimaTemplatesErr instanceof Error
        ? otimaTemplatesErr.message
        : "Não foi possível sincronizar os templates para esta carteira.";
    toast({ variant: "destructive", title: "Templates Ótima", description: msg });
  }, [otimaTemplatesIsError, otimaTemplatesErr, toast]);

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
    enabled: !!carteira && (provider === "NOAH_OFICIAL" || provider === "GOSAC_OFICIAL"),
  });

  // Templates GOSAC Oficial (estáticos)
  const { data: gosacTemplatesData = [], isLoading: gosacTemplatesLoading } = useQuery({
    queryKey: ['gosac-oficial-templates'],
    queryFn: getGosacOficialTemplates,
    enabled: provider === "GOSAC_OFICIAL",
    staleTime: 5 * 60 * 1000,
  });

  // Conexões (ilhas) GOSAC Oficial
  const { data: gosacConnectionsData = [], isLoading: gosacConnectionsLoading } = useQuery({
    queryKey: ['gosac-oficial-connections', carteira],
    queryFn: () => getGosacOficialConnections({ carteira }),
    enabled: !!carteira && provider === "GOSAC_OFICIAL",
    staleTime: 2 * 60 * 1000,
  });

  const { data: noahChannelsData = [], isLoading: noahChannelsLoading } = useQuery({
    queryKey: ['noah-oficial-channels-file', carteira],
    queryFn: () => getNoahOficialChannels({ carteira_id: carteira }),
    enabled: !!carteira && provider === "NOAH_OFICIAL",
    staleTime: 2 * 60 * 1000,
  });

  // Templates Robbu Oficial (estáticos, não dependem da carteira)
  const { data: robbuTemplatesData = [], isLoading: robbuTemplatesLoading } = useQuery({
    queryKey: ['robbu-oficial-templates'],
    queryFn: getRobbuOficialTemplates,
    enabled: provider === "ROBBU_OFICIAL",
    staleTime: 5 * 60 * 1000,
  });

  const { data: baitsData = [], isLoading: baitsLoading } = useQuery({
    queryKey: ['baits-file-campaign'],
    queryFn: getIscas,
  });

  const mergedTemplates = useMemo(() => {
    const local = (localTemplatesData || []).map((t: any) => ({
      id: String(t.id),
      name: t.title || '',
      source: t.source || 'local',
      provider: t.provider || null,
      walletId: t.wallet_id || null,
      templateCode: t.template_code || t.template_id || '',
      walletName: null,
    }));

    const otima = Array.isArray(otimaTemplatesData)
      ? otimaTemplatesData.map((t: any) => {
          const isWpp = t.source === 'otima_wpp';
          const code = t.template_code || '';
          const id =
            t.id != null && String(t.id) !== ''
              ? String(t.id)
              : isWpp && code
                ? `wpp_${code}_${t.wallet_id || ''}`
                : `otima_${code}_${t.wallet_id || ''}`;
          return {
            id,
            name: isWpp ? (code || t.name || 'Template WhatsApp') : (t.name || t.template_code || ''),
            source: t.source || 'otima_rcs',
            templateCode: code,
            brokerCode: t.broker_code || '',
            customerCode: t.customer_code || '',
            walletId: t.wallet_id,
            walletName: t.wallet_name,
            imageUrl: t.image_url || null,
            content: t.content || '',
            variable_sample: t.variable_sample ?? null,
            variableSample: t.variable_sample ?? null,
            category: t.category,
            status: t.status,
            accounts: t.accounts,
            raw_data: t.raw_data && typeof t.raw_data === 'object' ? t.raw_data : t,
          };
        })
      : [];

    const external = Array.isArray(externalTemplatesData)
      ? externalTemplatesData.map((t: any) => {
          const isGosac = t.provider === 'Gosac Oficial';
          const isNoah = t.provider === 'Noah Oficial';
          const isRobbu = t.provider === 'Robbu Oficial';
          const source = isGosac
            ? 'gosac_oficial'
            : isNoah
              ? 'noah_oficial'
              : isRobbu
                ? 'robbu_oficial'
                : t.source || 'external';
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
            format: t.format ?? null,
            textHeader: t.textHeader ?? t.text_header ?? '',
            textBody: t.textBody ?? t.text_body ?? '',
            textFooter: t.textFooter ?? t.text_footer ?? '',
            buttons: t.buttons ?? null,
            content: t.content || '',
            components: t.components,
          };
        })
      : [];

    const gosac = (Array.isArray(gosacTemplatesData) ? gosacTemplatesData : []).map((t: any) => {
      const numId =
        typeof t.templateId === 'number' && t.templateId > 0
          ? t.templateId
          : typeof t.id === 'number' && t.id > 0
            ? t.id
            : parseInt(String(t.id), 10) || 0;
      return {
        id: `Gosac Oficial_${t.id ?? t.name}_${t.id_ambient || 'default'}`,
        name: t.name || t.id || '',
        source: 'gosac_oficial',
        templateCode: t.name || t.id || '',
        walletId: t.id_ambient || 'default',
        walletName: `Gosac Oficial (${t.id_ambient || 'default'})`,
        channelId: null,
        templateId: numId > 0 ? numId : t.id,
        templateName: t.name || t.id,
        connectionId: t.connectionId ?? null,
        variableComponents: t.variableComponents ?? [],
        language: t.language || 'pt_BR',
        content: t.content || '',
        components: t.components,
      };
    });

    const robbu = (Array.isArray(robbuTemplatesData) ? robbuTemplatesData : []).map((t: any) => ({
      id: `Robbu Oficial_${t.id || t.name}_static`,
      name: t.name || t.id || '',
      source: 'robbu_oficial',
      templateCode: t.templateName || t.name || t.id || '',
      walletId: t.env_id || 'static',
      walletName: 'Robbu Oficial',
      channelId: t.channelId ?? 3,
      templateId: t.templateId,
      templateName: t.templateName || t.name || t.id,
      language: t.language || 'pt_BR',
      content: t.content || '',
      components: t.components,
    }));

    const selectedWallet = carteira
      ? (carteiras as any[]).find((c: any) => String(c.id) === String(carteira))
      : null;
    const walletCode = selectedWallet?.id_carteira ? String(selectedWallet.id_carteira) : null;

    const otimaFiltrados = otima.filter((t) => {
      if (carteira && walletCode) {
        return String(t.walletId) === walletCode || String(t.customerCode) === walletCode;
      }
      return true;
    });

    const externalFiltrados = external.filter((t) => {
      if (t.source === 'robbu_oficial' || t.source === 'gosac_oficial') return false;
      if (carteira && walletCode) {
        return String(t.walletId) === walletCode;
      }
      return true;
    });

    return [...local, ...otimaFiltrados, ...externalFiltrados, ...gosac, ...robbu];
  }, [
    localTemplatesData,
    otimaTemplatesData,
    externalTemplatesData,
    gosacTemplatesData,
    robbuTemplatesData,
    carteira,
    carteiras,
  ]);

  const filteredTemplates = useMemo(() => {
    if (!provider) return [];
    const selectedSources = PROVIDER_TO_SOURCE_MAP[provider] ?? [];
    const selectedWallet = carteira
      ? (carteiras as any[]).find((c: any) => String(c.id) === String(carteira))
      : null;
    const walletCode = selectedWallet?.id_carteira ? String(selectedWallet.id_carteira) : null;

    return mergedTemplates.filter((t: any) => {
      if (t.source !== 'local') {
        if (selectedSources.length === 0) return false;
        if (!selectedSources.includes(t.source)) return false;
        if (
          carteira &&
          walletCode &&
          (t.source === 'otima_wpp' || t.source === 'otima_rcs' || t.source === 'noah_oficial')
        ) {
          return String(t.walletId) === walletCode || String(t.customerCode) === walletCode;
        }
        return true;
      }
      const hasProviderMeta = !!t.provider;
      const hasWalletMeta = !!t.walletId;
      if (!hasProviderMeta && !hasWalletMeta) return true;
      const providerMatch = !hasProviderMeta || t.provider === provider;
      const walletMatch = !hasWalletMeta || !walletCode || String(t.walletId) === walletCode;
      return providerMatch && walletMatch;
    });
  }, [mergedTemplates, provider, carteira, carteiras]);

  const templates = filteredTemplates;

  const otimaBrokersForTemplate = useMemo(() => {
    const list = Array.isArray(otimaBrokersData) ? otimaBrokersData : [];
    const isDiag = (b: any) => String(b.code ?? '').startsWith('error_');
    if (provider === 'OTIMA_WPP') {
      return list.filter(
        (b: any) =>
          isDiag(b) ||
          b.channel === 'wpp' ||
          (!b.channel && /wpp|whatsapp/i.test(String(b.name ?? ''))),
      );
    }
    if (provider === 'OTIMA_RCS') {
      return list.filter(
        (b: any) =>
          isDiag(b) ||
          b.channel === 'rcs' ||
          (!b.channel && /rcs/i.test(String(b.name ?? ''))),
      );
    }
    return list;
  }, [otimaBrokersData, provider]);

  const selectedTemplateForMapper = templates.find((t) => t.id === template) as any;

  const noahOfficialMapperVariableKeys = useMemo(() => {
    const sel = selectedTemplateForMapper;
    if (!sel || !isNoahOfficialTemplateSource(sel.source)) return [];
    const fromTpl = listNoahOfficialVariableKeysFromTemplate(sel);
    const fromState = Object.keys(templateVariables);
    if (fromTpl.length === 0) return fromState;
    const seen = new Set<string>();
    const out: string[] = [];
    for (const k of fromTpl) {
      if (!seen.has(k)) {
        seen.add(k);
        out.push(k);
      }
    }
    for (const k of fromState) {
      if (!seen.has(k)) {
        seen.add(k);
        out.push(k);
      }
    }
    return out;
  }, [selectedTemplateForMapper, templateVariables]);

  const gosacMapperVariableKeys = useMemo(() => {
    const sel = selectedTemplateForMapper;
    if (!sel || sel.source !== 'gosac_oficial') return [];
    const comps = sel.variableComponents;
    if (!Array.isArray(comps) || comps.length === 0) return Object.keys(templateVariables);
    const keys = comps.map((c: any) => String(c.variable ?? '')).filter(Boolean);
    if (keys.length === 0) return Object.keys(templateVariables);
    return [...new Set([...keys, ...Object.keys(templateVariables)])];
  }, [selectedTemplateForMapper, templateVariables]);

  const otimaWppMapperVariableKeys = useMemo(() => {
    if (!selectedTemplateForMapper || selectedTemplateForMapper.source !== 'otima_wpp') return [];
    const fromTpl = listOtimaWppVariableKeysFromTemplate(selectedTemplateForMapper);
    const fromState = Object.keys(templateVariables);
    if (fromTpl.length === 0) return fromState;
    const seen = new Set<string>();
    const out: string[] = [];
    for (const k of fromTpl) {
      if (!seen.has(k)) {
        seen.add(k);
        out.push(k);
      }
    }
    for (const k of fromState) {
      if (!seen.has(k)) {
        seen.add(k);
        out.push(k);
      }
    }
    return out;
  }, [selectedTemplateForMapper, templateVariables]);

  const fieldOptionsForMapper = useMemo(() => {
    if (csvHeaders.length > 0) return csvHeaders.map((h) => ({ value: h, label: h }));
    return DB_FIELDS;
  }, [csvHeaders]);
  const fieldSourceLabel = csvHeaders.length > 0 ? 'CSV' : 'BD';

  const mapperVariableKeys = useMemo(() => {
    if (provider === "TECH_IA") {
      return [...TECHIA_FIXED_VARIABLE_KEYS];
    }
    const sel = selectedTemplateForMapper;
    if (!sel) return [];
    if (sel.source === 'otima_wpp' && otimaWppMapperVariableKeys.length > 0) return otimaWppMapperVariableKeys;
    if (isNoahOfficialTemplateSource(sel.source) && noahOfficialMapperVariableKeys.length > 0) {
      return noahOfficialMapperVariableKeys;
    }
    if (sel.source === 'gosac_oficial' && gosacMapperVariableKeys.length > 0) return gosacMapperVariableKeys;
    if (
      sel.source === 'otima_rcs' ||
      sel.source === 'otima_wpp' ||
      isNoahOfficialTemplateSource(sel.source)
    ) {
      return Object.keys(templateVariables);
    }
    return [];
  }, [
    selectedTemplateForMapper,
    otimaWppMapperVariableKeys,
    noahOfficialMapperVariableKeys,
    gosacMapperVariableKeys,
    templateVariables,
    provider,
  ]);

  /** Sugestão inicial de colunas CSV para cada chave TECHIA (analista pode ajustar). */
  useEffect(() => {
    if (provider !== "TECH_IA") return;
    setTemplateVariables((prev) => {
      const h = csvHeaders;
      if (h.length === 0) {
        const empty: Record<string, VarMapping> = {};
        for (const k of TECHIA_FIXED_VARIABLE_KEYS) {
          empty[k] = { type: "field", value: "" };
        }
        return Object.keys(prev).length > 0 ? prev : empty;
      }
      const guess = (re: RegExp) => h.find((x) => re.test(x)) || "";
      const next: Record<string, VarMapping> = { ...prev };
      let changed = false;
      for (const key of TECHIA_FIXED_VARIABLE_KEYS) {
        if (next[key]?.value && String(next[key].value).trim() !== "") continue;
        let col = "";
        if (key === "nome") col = guess(/^nome$/i) || h[0];
        else if (key === "documento") col = guess(/cpf|cnpj|documento/i) || h[0];
        else if (key === "contrato") col = guess(/contrato|id.*contrato|idcob/i) || h[0];
        else if (key === "valor") col = guess(/^valor$/i) || guess(/vlr|amount/i) || "";
        else if (key === "atraso") col = guess(/atraso|dias|dias_atraso/i) || "";
        else if (key === "COD_DEPARA") col = guess(/depara|cod.*depara|COD_DEPARA/i) || "";
        else if (key === "campanha_origem") col = guess(/campanha|origem|cod.*campanha/i) || "";
        next[key] = { type: "field", value: col || h[0] };
        changed = true;
      }
      return changed ? next : prev;
    });
  }, [provider, csvHeaders]);

  const skipResetRef = useRef(true);
  useEffect(() => {
    if (skipResetRef.current) {
      skipResetRef.current = false;
      return;
    }
    setTemplate('');
    setBrokerCode('');
    setGosacConnectionId('');
    setTemplateVariables({});
    setSelectedTemplateObj(null);
  }, [carteira, provider]);

  const templatesLoading = localTemplatesLoading || otimaTemplatesLoading || externalTemplatesLoading || gosacTemplatesLoading || robbuTemplatesLoading || otimaBrokersLoading;

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
      if (Array.isArray(data.headers) && data.headers.length > 0) {
        setCsvHeaders(data.headers.map((h: unknown) => String(h)));
      }
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
    readCsvHeadersFromFile(selectedFile)
      .then(setCsvHeaders)
      .catch(() => setCsvHeaders([]));
    uploadMutation.mutate({ file: selectedFile, matchField });
  };

  const removeFile = () => {
    setFile(null);
    setTempId("");
    setRecordCount(0);
    setCsvHeaders([]);
  };

  const handleSubmit = async () => {
    if (!campaignName.trim()) {
      toast({
        title: "Nome obrigatório",
        description: "Informe o nome da campanha",
        variant: "destructive",
      });
      return;
    }

    if (!file || !tempId) {
      toast({
        title: "Arquivo obrigatório",
        description: "Por favor, faça upload de um arquivo CSV válido",
        variant: "destructive",
      });
      return;
    }

    const salesforceOnly = provider === "SALESFORCE";
    const isTechiaDiscador = provider === "TECH_IA";

    if (!salesforceOnly && !isTechiaDiscador && !template) {
      toast({
        title: "Template obrigatório",
        description: "Por favor, selecione um template de mensagem",
        variant: "destructive",
      });
      return;
    }

    if (isTechiaDiscador) {
      const missing = TECHIA_FIXED_VARIABLE_KEYS.filter(
        (k) => !(templateVariables[k]?.value && String(templateVariables[k].value).trim()),
      );
      if (missing.length > 0) {
        toast({
          title: "Mapeamento TECHIA incompleto",
          description: `Associe uma coluna do CSV a cada campo: ${missing.join(", ")}`,
          variant: "destructive",
        });
        return;
      }
    }

    const selectedTemplate = templates.find((t) => t.id === template);

    if (!salesforceOnly && !isTechiaDiscador && (selectedTemplate?.source === 'otima_rcs' || selectedTemplate?.source === 'otima_wpp') && !brokerCode) {
      toast({
        title: "Broker obrigatório",
        description: "Por favor, selecione um broker da Ótima",
        variant: "destructive",
      });
      return;
    }

    if (!salesforceOnly && !isTechiaDiscador && selectedTemplate?.source === 'gosac_oficial' && !gosacConnectionId) {
      toast({
        title: "Ilha obrigatória",
        description: "Selecione a ilha (conexão) por qual o disparo será enviado",
        variant: "destructive",
      });
      return;
    }

    if (
      !salesforceOnly &&
      !isTechiaDiscador &&
      selectedTemplate &&
      isNoahOfficialTemplateSource(selectedTemplate.source)
    ) {
      const ch = parseInt(String(noahChannelId || selectedTemplate?.channelId || ''), 10);
      if (!ch || ch <= 0) {
        toast({
          title: "Remetente NOAH obrigatório",
          description: "Selecione a linha de disparo (channelId) na lista de canais NOAH Oficial.",
          variant: "destructive",
        });
        return;
      }
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
    const templateSource = salesforceOnly
      ? 'salesforce'
      : isTechiaDiscador
        ? 'techia_discador'
      : isOtimaTemplate
      ? (provider === 'OTIMA_WPP' ? 'otima_wpp' : 'otima_rcs')
      : (selectedTemplate?.source || 'local');

    const payload: Record<string, any> = {
      nome_campanha: campaignName.trim(),
      nome_carteira: selectedCarteiraObj?.nome?.trim() || '',
      temp_id: tempId,
      table_name: tableName,
      carteira: carteira || '',
      wallet_id: walletIdForOtima || selectedCarteiraObj?.id_carteira || '',
      fornecedor: provider.toUpperCase(),
      template_id: salesforceOnly || isTechiaDiscador ? null : (selectedTemplate?.source === 'local' ? parseInt(template, 10) : null),
      template_code: salesforceOnly || isTechiaDiscador ? null : (selectedTemplate?.templateCode || null),
      template_source: templateSource,
      broker_code: salesforceOnly || isTechiaDiscador ? null : (brokerCode || selectedTemplate?.brokerCode || null),
      customer_code: salesforceOnly || isTechiaDiscador
        ? null
        : (selectedTemplate?.customerCode || walletIdForOtima || selectedCarteiraObj?.id_carteira || null),
      variables_map: salesforceOnly ? null : (Object.keys(templateVariables).length > 0 ? templateVariables : null),
      provider: provider.toUpperCase(),
      match_field: matchField,
      include_baits: includeBaits ? 1 : 0,
      bait_ids: includeBaits ? selectedBaitIds : [],
      show_already_sent: showAlreadySent ? 1 : 0,
    };

    if (templateSource === 'noah_oficial' && selectedTemplate) {
      const nch = parseInt(String(noahChannelId || selectedTemplate.channelId || ''), 10);
      payload.noah_channel_id = !Number.isNaN(nch) && nch > 0 ? nch : '';
      payload.noah_template_id = selectedTemplate.templateId ?? '';
      payload.noah_language = selectedTemplate.language ?? 'pt_BR';
    }
    if (templateSource === 'robbu_oficial') {
      payload.robbu_channel = 3;
    }
    if (templateSource === 'gosac_oficial' && selectedTemplate) {
      payload.gosac_template_id = selectedTemplate.templateId ?? selectedTemplate.id ?? '';
      payload.gosac_connection_id = gosacConnectionId || (selectedTemplate.connectionId ?? '');
      payload.gosac_variable_components = selectedTemplate.variableComponents
        ? JSON.stringify(selectedTemplate.variableComponents)
        : '[]';
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

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <FileText className="h-5 w-5 text-primary" />
            Campanha por arquivo
          </CardTitle>
          <CardDescription>
            Nome, carteira, fornecedor e remetente antes do template; em seguida CSV e mapeamento de variáveis às colunas do arquivo.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="campaign-name-file">Nome da campanha <span className="text-red-500">*</span></Label>
              <Input
                id="campaign-name-file"
                value={campaignName}
                onChange={(e) => setCampaignName(e.target.value)}
                placeholder="Ex.: Campanha Black Friday — base X"
              />
            </div>

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
              <p className="text-xs text-muted-foreground">
                Os templates externos (Ótima, NOAH, GOSAC Oficial, Robbu) só são carregados após escolher carteira e fornecedor compatíveis.
              </p>
            </div>

            {provider === "GOSAC_OFICIAL" && (
              <div className="space-y-2 animate-in fade-in slide-in-from-top-2">
                <Label>Ilha / Conexão (remetente) <span className="text-red-500">*</span></Label>
                <Select
                  value={gosacConnectionId}
                  onValueChange={setGosacConnectionId}
                  disabled={gosacConnectionsLoading || !carteira}
                >
                  <SelectTrigger>
                    <SelectValue
                      placeholder={
                        !carteira
                          ? "Selecione a carteira primeiro"
                          : gosacConnectionsLoading
                            ? "Carregando ilhas..."
                            : "Selecione a ilha por qual sairá o disparo"
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {Array.isArray(gosacConnectionsData) && gosacConnectionsData.length === 0 && !gosacConnectionsLoading && carteira && (
                      <div className="py-2 px-3 text-xs text-muted-foreground italic">
                        Nenhuma ilha encontrada. Verifique id_carteira e id_ruler na carteira.
                      </div>
                    )}
                    {Array.isArray(gosacConnectionsData) &&
                      gosacConnectionsData.map((c: any) => (
                        <SelectItem key={c.id} value={String(c.id)}>
                          <div className="flex items-center gap-1.5">
                            <span>{c.name || `Ilha ${c.id}`}</span>
                            {c.status && (
                              <Badge variant={c.status === "CONNECTED" ? "default" : "secondary"} className="text-[10px] py-0 px-1">
                                {c.status}
                              </Badge>
                            )}
                          </div>
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            {provider === "NOAH_OFICIAL" && (
              <div className="space-y-2 animate-in fade-in slide-in-from-top-2">
                <Label>Remetente / linha NOAH (channelId) <span className="text-red-500">*</span></Label>
                <Select
                  value={noahChannelId}
                  onValueChange={setNoahChannelId}
                  disabled={noahChannelsLoading || !carteira}
                >
                  <SelectTrigger>
                    <SelectValue
                      placeholder={
                        !carteira
                          ? "Selecione a carteira primeiro"
                          : noahChannelsLoading
                            ? "Carregando canais..."
                            : "Selecione o canal de disparo"
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {Array.isArray(noahChannelsData) &&
                      noahChannelsData.length === 0 &&
                      !noahChannelsLoading &&
                      carteira && (
                        <div className="py-2 px-3 text-xs text-muted-foreground italic">
                          Nenhum canal encontrado. Configure NOAH Oficial no API Manager para esta carteira.
                        </div>
                      )}
                    {Array.isArray(noahChannelsData) &&
                      noahChannelsData.map((ch: Record<string, unknown>) => {
                        const cid = ch.id ?? ch.channelId ?? ch.channel_id ?? ch.IdCanal;
                        const label =
                          (ch.name as string) ||
                          (ch.nome as string) ||
                          (ch.label as string) ||
                          `Canal ${String(cid ?? "")}`;
                        const v = cid != null ? String(cid) : "";
                        if (!v) return null;
                        return (
                          <SelectItem key={`noah-ch-file-${v}`} value={v}>
                            {label}
                          </SelectItem>
                        );
                      })}
                  </SelectContent>
                </Select>
              </div>
            )}

            {(provider === "OTIMA_WPP" || provider === "OTIMA_RCS") && (
              <div className="space-y-2 animate-in fade-in slide-in-from-top-2">
                <Label>Remetente / Broker Ótima <span className="text-red-500">*</span></Label>
                <Select disabled={otimaBrokersLoading} value={brokerCode} onValueChange={setBrokerCode}>
                  <SelectTrigger>
                    <SelectValue
                      placeholder={otimaBrokersLoading ? "Carregando brokers..." : "Número (code) do remetente WPP ou RCS"}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {otimaBrokersForTemplate.length === 0 && !otimaBrokersLoading && (
                      <div className="py-2 px-3 text-xs text-muted-foreground italic">
                        Nenhum broker para este canal. Verifique o token no API Manager.
                      </div>
                    )}
                    {otimaBrokersForTemplate.map((b: any, idx: number) => {
                      const v = String(b.value ?? b.code ?? "");
                      const isRcs = b.channel === "rcs" || String(b.name ?? "").toLowerCase().includes("rcs");
                      const isWpp = b.channel === "wpp" || /wpp|whatsapp/i.test(String(b.name ?? ""));
                      const display = b.label ?? b.name ?? v;
                      return (
                        <SelectItem key={`broker-${v || idx}`} value={v}>
                          <div className="flex items-center gap-1.5">
                            {isRcs && <Badge className="text-[10px] py-0 px-1 bg-blue-500 text-white shrink-0">RCS</Badge>}
                            {isWpp && (
                              <Badge variant="outline" className="text-[10px] py-0 px-1 shrink-0">
                                WPP
                              </Badge>
                            )}
                            <span>
                              {display}
                              {v ? ` (${v})` : ""}
                            </span>
                          </div>
                        </SelectItem>
                      );
                    })}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">Use o valor numérico retornado pela Ótima (campo code), não o nome da credencial.</p>
              </div>
            )}

            {provider === "TECH_IA" && (
              <Alert className="border-cyan-200 bg-cyan-50/50 dark:bg-cyan-950/20">
                <FileText className="h-4 w-4 text-cyan-700" />
                <AlertDescription className="text-sm">
                  <strong>TECHIA (discador):</strong> não há template de mensagem. Mapeie abaixo cada campo do discador para uma coluna do CSV
                  (telefone continua vindo do cruzamento CPF/telefone + arquivo).
                </AlertDescription>
              </Alert>
            )}

            {provider !== "TECH_IA" && (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <Label>Template <span className="text-red-500">*</span></Label>
                {walletIdForOtima && carteira && (provider === "OTIMA_RCS" || provider === "OTIMA_WPP") ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 shrink-0 gap-1 text-xs"
                    disabled={otimaTemplatesFetching}
                    onClick={() => {
                      queryClient.invalidateQueries({ queryKey: ["otima-templates"] });
                      toast({
                        title: "Sincronizando",
                        description: "Buscando templates na API Ótima Digital…",
                      });
                    }}
                  >
                    <RefreshCw className={`h-3.5 w-3.5 ${otimaTemplatesFetching ? "animate-spin" : ""}`} />
                    Sincronizar Ótima
                  </Button>
                ) : null}
              </div>
              {!provider && (
                <p className="text-xs text-muted-foreground">Selecione o fornecedor para listar templates compatíveis.</p>
              )}
              {(provider === "OTIMA_RCS" || provider === "OTIMA_WPP") && !carteira && (
                <p className="text-xs text-muted-foreground">Selecione uma carteira para carregar templates Ótima (wallet / id_carteira).</p>
              )}
              <Popover open={openTemplateDropdown} onOpenChange={setOpenTemplateDropdown}>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={openTemplateDropdown}
                    disabled={!provider}
                    className="w-full justify-between font-normal"
                  >
                    {!provider
                      ? "Selecione o fornecedor primeiro…"
                      : template
                        ? templates.find((t) => t.id === template)?.name || "Template selecionado"
                        : templatesLoading
                          ? "Carregando templates…"
                          : "Selecione um template…"}
                    {templatesLoading && <Loader2 className="ml-2 h-4 w-4 shrink-0 animate-spin" />}
                    {!templatesLoading && <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                  <Command>
                    <CommandInput placeholder="Buscar template…" disabled={templatesLoading || !provider} />
                    <CommandList>
                      {templatesLoading ? (
                        <div className="py-6 px-4 flex items-center justify-center gap-2 text-muted-foreground">
                          <Loader2 className="h-4 w-4 animate-spin" />
                          <span>Carregando templates…</span>
                        </div>
                      ) : (
                        <>
                          <CommandEmpty>Nenhum template para este fornecedor.</CommandEmpty>
                          <CommandGroup>
                            {templates.map((t) => {
                              const isOtima = t.source === "otima_rcs" || t.source === "otima_wpp";
                              const isNoah = t.source === "noah_oficial";
                              const isGosac = t.source === "gosac_oficial";
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
                                    if (
                                      selectedTpl?.channelId != null &&
                                      String(selectedTpl.channelId).trim() !== ''
                                    ) {
                                      setNoahChannelId(String(selectedTpl.channelId));
                                    } else {
                                      setNoahChannelId('');
                                    }
                                    setSelectedTemplateObj(selectedTpl ?? null);
                                    const colDefault = csvHeaders[0] || "nome";
                                    const otimaWppMap = buildInitialVariableMappingFromOtimaWpp(selectedTpl);
                                    const noahOfficialMap = buildInitialVariableMappingFromNoahOfficial(selectedTpl);
                                    const rawContent = collectPlaceholdersSourceText(selectedTpl ?? null) || "";
                                    if (otimaWppMap) {
                                      setTemplateVariables(otimaWppMap);
                                    } else if (noahOfficialMap) {
                                      const col0 = csvHeaders[0] || "nome";
                                      const patched: Record<string, VarMapping> = {};
                                      for (const [k, m] of Object.entries(noahOfficialMap)) {
                                        patched[k] =
                                          m.type === "field" && m.value === "nome"
                                            ? { ...m, value: col0 }
                                            : m;
                                      }
                                      setTemplateVariables(patched);
                                    } else {
                                      let detectedVars = extractVariables(rawContent);
                                      if (
                                        detectedVars.length === 0 &&
                                        isNoahOfficialTemplateSource(selectedTpl?.source)
                                      ) {
                                        detectedVars = extractNoahNumericPlaceholderKeys(rawContent);
                                      }
                                      const initMap: Record<string, VarMapping> = {};
                                      detectedVars.forEach((vVar) => {
                                        initMap[vVar] = { type: "field", value: colDefault };
                                      });
                                      setTemplateVariables(initMap);
                                    }
                                    setOpenTemplateDropdown(false);
                                  }}
                                >
                                  <Check
                                    className={cn("mr-2 h-4 w-4", template === t.id ? "opacity-100" : "opacity-0")}
                                  />
                                  <div className="flex flex-col">
                                    <span className="font-medium text-sm truncate max-w-[400px]" title={t.name}>
                                      {t.name}
                                    </span>
                                    <span className="text-[10px] text-muted-foreground flex items-center gap-1">
                                      {isOtima && (t.source === "otima_rcs" ? "Ótima RCS" : "Ótima WPP")}
                                      {isNoah && "NOAH Oficial"}
                                      {isGosac && "GOSAC Oficial"}
                                      {t.source === "robbu_oficial" && "Robbu Oficial"}
                                      {t.source === "local" && "Local"}
                                    </span>
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
            )}

            <div className="space-y-3 rounded-lg border border-border p-4">
              <div className="flex items-center gap-2">
                <Upload className="h-4 w-4 text-primary" />
                <span className="text-sm font-semibold">Arquivo CSV</span>
              </div>
              <div className="space-y-2">
                <Label>Tipo de cruzamento</Label>
                <Select
                  value={matchField}
                  onValueChange={(v: "cpf" | "telefone") => {
                    setMatchField(v);
                    if (file) {
                      uploadMutation.mutate({ file, matchField: v });
                    }
                  }}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="cpf">CPF</SelectItem>
                    <SelectItem value="telefone">Telefone</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {!file ? (
                <label className="flex flex-col items-center justify-center min-h-[140px] border-2 border-dashed border-border rounded-xl cursor-pointer hover:border-primary/50 hover:bg-muted/50 transition-colors">
                  <Upload className="h-8 w-8 text-muted-foreground mb-2" />
                  <p className="text-sm font-medium text-foreground">Selecione o CSV</p>
                  <p className="text-xs text-muted-foreground mt-1 px-4 text-center">
                    Cabeçalhos detectados alimentam o mapeamento de variáveis do template.
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
                <div className="space-y-3">
                  <div className="flex items-center justify-between p-3 rounded-xl bg-muted/50 border">
                    <div className="flex items-center gap-3 min-w-0">
                      <FileText className="h-5 w-5 text-primary shrink-0" />
                      <div className="min-w-0">
                        <p className="font-medium text-sm truncate">{file.name}</p>
                        <p className="text-xs text-muted-foreground">{(file.size / 1024).toFixed(1)} KB</p>
                      </div>
                    </div>
                    <Button variant="ghost" size="icon" onClick={removeFile} disabled={uploadMutation.isPending}>
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                  {uploadMutation.isPending && (
                    <div className="flex items-center gap-2 text-muted-foreground text-sm">
                      <Loader2 className="h-4 w-4 animate-spin" />
                      Validando arquivo…
                    </div>
                  )}
                  {uploadMutation.isSuccess && tempId && (
                    <div className="flex items-center gap-2 p-3 rounded-lg bg-success/10 text-success text-sm">
                      <CheckCircle className="h-4 w-4 shrink-0" />
                      <span>
                        {recordCount.toLocaleString("pt-BR")} registros — colunas:{" "}
                        {csvHeaders.length ? csvHeaders.join(", ") : "—"}
                      </span>
                    </div>
                  )}
                  {uploadMutation.isError && (
                    <div className="flex items-center gap-2 p-3 rounded-lg bg-destructive/10 text-destructive text-sm">
                      <AlertCircle className="h-4 w-4 shrink-0" />
                      {uploadMutation.error instanceof Error ? uploadMutation.error.message : "Erro ao validar arquivo"}
                    </div>
                  )}
                </div>
              )}
            </div>

            {mapperVariableKeys.length > 0 && (
              <div className="space-y-2">
                {provider === "TECH_IA" && (
                  <p className="text-sm font-medium text-foreground">
                    Mapeamento TECHIA — colunas do CSV
                  </p>
                )}
                <TemplateVariableMapper
                  variables={mapperVariableKeys}
                  mapping={templateVariables}
                  onChange={setTemplateVariables}
                  fieldOptions={fieldOptionsForMapper}
                  fieldSourceLabel={fieldSourceLabel}
                />
              </div>
            )}

            {selectedTemplateObj &&
              provider === "NOAH_OFICIAL" &&
              isNoahOfficialTemplateSource(selectedTemplateObj.source) && (
                <div className="space-y-2">
                  <Label>Pré-visualização do template (NOAH)</Label>
                  <div className="rounded-md border bg-muted/30 p-4 min-h-[80px] text-sm whitespace-pre-wrap text-foreground">
                    {buildNoahOfficialTemplatePreviewMessage(selectedTemplateObj) || (
                      <span className="text-muted-foreground italic">Sem texto de corpo/cabeçalho na resposta da API.</span>
                    )}
                  </div>
                </div>
              )}

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
                  onCheckedChange={(checked) => {
                    const on = !!checked;
                    setIncludeBaits(on);
                    if (on && Array.isArray(baitsData) && baitsData.length) {
                      setSelectedBaitIds(
                        baitsData.map((b: any) => Number(b.id)).filter((n: number) => !Number.isNaN(n) && n > 0),
                      );
                    } else {
                      setSelectedBaitIds([]);
                    }
                  }}
                />
                <div className="flex-1">
                  <label htmlFor="include-baits-file" className="font-semibold cursor-pointer">
                    Incluir iscas de teste
                  </label>
                  <p className="text-xs text-muted-foreground mt-1">
                    Escolha quais iscas ativas entram nesta campanha
                  </p>
                </div>
              </div>
              {includeBaits && baitsData.length > 0 && (
                <div className="max-h-40 overflow-y-auto space-y-2 pl-1">
                  {baitsData.map((isca: any) => {
                    const id = Number(isca.id);
                    if (!id) return null;
                    return (
                      <label key={id} className="flex items-center gap-2 text-sm cursor-pointer">
                        <Checkbox
                          checked={selectedBaitIds.includes(id)}
                          onCheckedChange={(c) => {
                            const on = !!c;
                            setSelectedBaitIds((prev) =>
                              on ? [...prev, id] : prev.filter((x) => x !== id),
                            );
                          }}
                        />
                        <span className="truncate">
                          {isca.telefone}
                          {isca.nome ? ` — ${isca.nome}` : ""}
                        </span>
                      </label>
                    );
                  })}
                </div>
              )}
              {includeBaits && !baitsLoading && baitsData.length === 0 && (
                <p className="text-xs text-destructive">Nenhuma isca ativa cadastrada.</p>
              )}
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
                !campaignName.trim() ||
                !file ||
                !tempId ||
                (!template && provider !== "SALESFORCE" && provider !== "TECH_IA") ||
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
