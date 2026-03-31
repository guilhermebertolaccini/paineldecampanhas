import { useState, useMemo } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Database, Loader2, Send } from "lucide-react";
import {
  TemplateVariableMapper,
  VarMapping,
  extractVariables,
  collectPlaceholdersSourceText,
  buildInitialVariableMappingFromOtimaWpp,
  buildInitialVariableMappingFromNoahOfficial,
  listOtimaWppVariableKeysFromTemplate,
  listNoahOfficialVariableKeysFromTemplate,
} from "@/components/campaign/TemplateVariableMapper";
import { FilterBuilder, FilterItem } from "@/components/campaign/FilterBuilder";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { useToast } from "@/hooks/use-toast";
import {
  getAvailableBases,
  getFilters,
  getCarteiras,
  getBasesCarteira,
  checkBaseUpdate,
  getMessages,
  getTemplateContent,
  getTemplatesByWallet,
  getGosacOficialTemplates,
  getGosacOficialConnections,
  getRobbuOficialTemplates,
  getOtimaTemplates,
  getOtimaBrokers,
  getIscas,
  saveRecurring,
} from "@/lib/api";

const PROVIDERS_LIST = [
  { id: "OTIMA_RCS", name: "Ótima RCS" },
  { id: "OTIMA_WPP", name: "Ótima WPP" },
  { id: "CDA_RCS", name: "CDA RCS" },
  { id: "CDA", name: "CDA" },
  { id: "GOSAC", name: "Gosac" },
  { id: "GOSAC_OFICIAL", name: "Gosac Oficial" },
  { id: "NOAH", name: "Noah" },
  { id: "NOAH_OFICIAL", name: "Noah Oficial" },
  { id: "ROBBU_OFICIAL", name: "Robbu Oficial" },
  { id: "SALESFORCE", name: "Salesforce" },
  { id: "TECH_IA", name: "TECHIA (Discador)" },
];

const PROVIDER_TO_SOURCE_MAP: Record<string, string[]> = {
  GOSAC_OFICIAL: ["gosac_oficial"],
  OTIMA_WPP: ["otima_wpp"],
  OTIMA_RCS: ["otima_rcs"],
  SALESFORCE: [],
  CDA: [],
  CDA_RCS: [],
  NOAH: [],
  NOAH_OFICIAL: ["noah_oficial"],
  ROBBU_OFICIAL: ["robbu_oficial"],
  TECH_IA: [],
  GOSAC: [],
};

export function RecurringCampaignCreateForm() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [name, setName] = useState("");
  const [carteira, setCarteira] = useState("");
  const [base, setBase] = useState("");
  const [providers, setProviders] = useState<string[]>([]);
  const [filters, setFilters] = useState<FilterItem[]>([]);
  const [template, setTemplate] = useState("");
  const [templateCode, setTemplateCode] = useState("");
  const [templateSource, setTemplateSource] = useState("");
  const [brokerCode, setBrokerCode] = useState("");
  const [customerCode, setCustomerCode] = useState("");
  const [noahChannelId, setNoahChannelId] = useState("");
  const [noahTemplateId, setNoahTemplateId] = useState("");
  const [noahLanguage, setNoahLanguage] = useState("pt_BR");
  const [gosacTemplateId, setGosacTemplateId] = useState<number | null>(null);
  const [gosacConnectionId, setGosacConnectionId] = useState<number | null>(null);
  const [gosacVariableComponents, setGosacVariableComponents] = useState<{ componentId: number; variable: string }[]>([]);
  const [templateVariables, setTemplateVariables] = useState<Record<string, VarMapping>>({});
  const [selectedTemplateObj, setSelectedTemplateObj] = useState<Record<string, unknown> | null>(null);
  const [recordLimit, setRecordLimit] = useState(0);
  const [excludeRecentPhones, setExcludeRecentPhones] = useState(true);
  const [excludeRecentHours, setExcludeRecentHours] = useState(48);
  const [includeBaits, setIncludeBaits] = useState(false);
  const [selectedBaitIds, setSelectedBaitIds] = useState<number[]>([]);
  const [throttlingType, setThrottlingType] = useState<"none" | "linear" | "split">("none");
  const [throttlingConfig, setThrottlingConfig] = useState<Record<string, unknown>>({});

  const { data: carteiras = [] } = useQuery({ queryKey: ["carteiras"], queryFn: getCarteiras });
  const { data: basesCarteira = [] } = useQuery({
    queryKey: ["bases-carteira", carteira],
    queryFn: () => getBasesCarteira(carteira),
    enabled: !!carteira,
  });
  const { data: allBases = [] } = useQuery({ queryKey: ["available-bases"], queryFn: getAvailableBases });
  const bases = useMemo(() => {
    if (!carteira || !Array.isArray(basesCarteira) || !Array.isArray(allBases)) return [];
    const nomes = basesCarteira.filter((b): b is string => typeof b === "string").map((b) => b.trim().toLowerCase());
    return allBases.filter((b: { name?: string; id?: string }) => {
      const n = String(b?.name || b?.id || "").trim().toLowerCase();
      return nomes.includes(n);
    });
  }, [carteira, basesCarteira, allBases]);

  const { data: baseUpdateData } = useQuery({
    queryKey: ["base-update", base],
    queryFn: () => checkBaseUpdate(base),
    enabled: !!base,
  });

  const { data: localTemplatesData = [] } = useQuery({ queryKey: ["messages"], queryFn: getMessages });
  const { data: externalTemplatesData = [] } = useQuery({
    queryKey: ["external-templates-recurring", carteira],
    queryFn: () => getTemplatesByWallet(carteira),
    enabled: !!carteira,
  });
  const { data: gosacTemplatesData = [] } = useQuery({
    queryKey: ["gosac-oficial-templates-rec"],
    queryFn: getGosacOficialTemplates,
    staleTime: 5 * 60 * 1000,
  });
  const { data: gosacConnectionsData = [] } = useQuery({
    queryKey: ["gosac-oficial-connections-rec", carteira],
    queryFn: () => getGosacOficialConnections({ carteira }),
    enabled: !!carteira && providers.includes("GOSAC_OFICIAL"),
  });
  const { data: robbuTemplatesData = [] } = useQuery({
    queryKey: ["robbu-oficial-templates-rec"],
    queryFn: getRobbuOficialTemplates,
    staleTime: 5 * 60 * 1000,
  });

  const selectedCarteiraObj = (carteiras as { id?: string; id_carteira?: string }[]).find(
    (c) => String(c.id) === String(carteira),
  );
  const rawWallet = selectedCarteiraObj?.id_carteira;
  const walletIdForOtima =
    rawWallet != null && String(rawWallet).trim() !== "" ? String(rawWallet).trim() : undefined;

  const { data: otimaTemplatesData = [] } = useQuery({
    queryKey: ["otima-templates-rec", walletIdForOtima, carteira],
    queryFn: () => getOtimaTemplates(walletIdForOtima, carteira),
    enabled: !!carteira,
    staleTime: 60 * 1000,
  });
  const { data: otimaBrokersData = [] } = useQuery({
    queryKey: ["otima-brokers-rec"],
    queryFn: getOtimaBrokers,
    staleTime: 5 * 60 * 1000,
  });

  const { data: availableFilters = [], isLoading: filtersLoading } = useQuery({
    queryKey: ["filters", base],
    queryFn: () => getFilters(base),
    enabled: !!base,
  });

  useQuery({
    queryKey: ["template-content-rec", template, templateSource],
    queryFn: () => getTemplateContent(template),
    enabled: !!template && templateSource === "local",
    retry: false,
  });

  const { data: baitsData = [] } = useQuery({ queryKey: ["baits-recurring-create"], queryFn: getIscas });

  const templates = useMemo(() => {
    const local = (localTemplatesData || []).map((t: { id?: number; title?: string; source?: string; provider?: string; wallet_id?: string; template_code?: string }) => ({
      id: String(t.id),
      name: t.title || "",
      source: t.source || "local",
      provider: t.provider || null,
      walletId: t.wallet_id || null,
      templateCode: t.template_code || "",
    }));
    const otima = (Array.isArray(otimaTemplatesData) ? otimaTemplatesData : []).map((t: Record<string, unknown>) => {
      const isWpp = t.source === "otima_wpp";
      const code = (t.template_code as string) || "";
      const stableId =
        t.id != null && String(t.id) !== ""
          ? String(t.id)
          : isWpp && code
            ? `wpp_${code}`
            : `otima_${t.wallet_id || "x"}_${code || "x"}`;
      return {
        id: stableId,
        name: isWpp ? code || (t.name as string) || "Template WhatsApp" : (t.name as string) || "",
        source: t.source || "external",
        templateCode: code,
        brokerCode: t.broker_code || "",
        customerCode: t.customer_code || "",
        walletId: t.wallet_id,
        walletName: t.wallet_name || null,
        content: t.content || "",
        variable_sample: t.variable_sample ?? null,
        variableSample: t.variable_sample ?? null,
        raw_data: t,
      };
    });
    const external = (Array.isArray(externalTemplatesData) ? externalTemplatesData : []).map((t: Record<string, unknown>) => {
      const isGosac = t.provider === "Gosac Oficial";
      const isNoah = t.provider === "Noah Oficial";
      const isRobbu = t.provider === "Robbu Oficial";
      const source = isGosac ? "gosac_oficial" : isNoah ? "noah_oficial" : isRobbu ? "robbu_oficial" : "external";
      return {
        id: `${t.provider}_${t.id}_${t.id_ambient}`,
        name: (t.name as string) || String(t.id),
        source,
        templateCode: (t.templateName as string) || (t.name as string) || "",
        walletId: t.id_ambient,
        channelId: t.channelId,
        templateId: t.templateId,
        language: t.language || "pt_BR",
        content: t.content || "",
        components: t.components,
      };
    });
    const gosac = (Array.isArray(gosacTemplatesData) ? gosacTemplatesData : []).map((t: Record<string, unknown>) => {
      const numId =
        typeof t.templateId === "number" && (t.templateId as number) > 0
          ? (t.templateId as number)
          : parseInt(String(t.id), 10) || 0;
      return {
        id: `Gosac Oficial_${t.id ?? t.name}_${t.id_ambient || "default"}`,
        name: (t.name as string) || String(t.id),
        source: "gosac_oficial",
        templateCode: (t.name as string) || String(t.id),
        walletId: t.id_ambient,
        templateId: numId > 0 ? numId : t.id,
        connectionId: t.connectionId ?? null,
        variableComponents: t.variableComponents ?? [],
        content: t.content || "",
      };
    });
    const robbu = (Array.isArray(robbuTemplatesData) ? robbuTemplatesData : []).map((t: Record<string, unknown>) => ({
      id: `Robbu Oficial_${t.id || t.name}_static`,
      name: (t.name as string) || String(t.id),
      source: "robbu_oficial",
      templateCode: (t.templateName as string) || (t.name as string) || "",
      channelId: t.channelId ?? 3,
      templateId: t.templateId,
      content: t.content || "",
      components: t.components,
    }));
    return [...local, ...otima, ...external, ...gosac, ...robbu];
  }, [localTemplatesData, otimaTemplatesData, externalTemplatesData, gosacTemplatesData, robbuTemplatesData]);

  const filteredTemplates = useMemo(() => {
    const noneSelected = providers.length === 0;
    const selectedSources = providers.flatMap((p) => PROVIDER_TO_SOURCE_MAP[p] ?? []);
    const selectedWallet = carteira
      ? (carteiras as { id?: string; id_carteira?: string }[]).find((c) => String(c.id) === String(carteira))
      : null;
    const walletCode = selectedWallet?.id_carteira ? String(selectedWallet.id_carteira) : null;
    return templates.filter((t: { source?: string; provider?: string | null; walletId?: string | number | null }) => {
      if (t.source !== "local") {
        if (noneSelected) return true;
        return selectedSources.includes(t.source as string);
      }
      const hasP = !!t.provider;
      const hasW = !!t.walletId;
      if (!hasP && !hasW) return true;
      const providerMatch = !hasP || noneSelected || providers.includes(String(t.provider));
      const walletMatch = !hasW || !walletCode || String(t.walletId) === walletCode;
      return providerMatch && walletMatch;
    });
  }, [templates, providers, carteira, carteiras]);

  const salesforceOnly = useMemo(
    () => providers.length > 0 && providers.every((p) => p === "SALESFORCE"),
    [providers],
  );
  const techiaOnly = useMemo(
    () => providers.length > 0 && providers.every((p) => p === "TECH_IA"),
    [providers],
  );

  const otimaBrokersForTemplate = useMemo(() => {
    const list = Array.isArray(otimaBrokersData) ? otimaBrokersData : [];
    const isDiag = (b: { code?: string }) => String(b.code ?? "").startsWith("error_");
    if (templateSource === "otima_wpp") {
      return list.filter(
        (b: { channel?: string; name?: string }) =>
          isDiag(b) || b.channel === "wpp" || (!b.channel && /wpp|whatsapp/i.test(String(b.name ?? ""))),
      );
    }
    if (templateSource === "otima_rcs") {
      return list.filter(
        (b: { channel?: string; name?: string }) =>
          isDiag(b) || b.channel === "rcs" || (!b.channel && /rcs/i.test(String(b.name ?? ""))),
      );
    }
    return list;
  }, [otimaBrokersData, templateSource]);

  const otimaWppMapperVariableKeys = useMemo(() => {
    if (templateSource !== "otima_wpp") return [];
    const fromTpl = listOtimaWppVariableKeysFromTemplate(selectedTemplateObj);
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
  }, [templateSource, selectedTemplateObj, templateVariables]);

  const noahOfficialMapperVariableKeys = useMemo(() => {
    if (templateSource !== "noah_oficial" && templateSource !== "noah") return [];
    const fromTpl = listNoahOfficialVariableKeysFromTemplate(selectedTemplateObj);
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
  }, [templateSource, selectedTemplateObj, templateVariables]);

  const buildFilterPayload = (items: FilterItem[]) =>
    items
      .filter((f) => {
        if (!f.column || !f.operator) return false;
        if (f.operator === "is_null" || f.operator === "is_not_null") return true;
        return f.value !== "" && f.value !== null && f.value !== undefined;
      })
      .map((f) => ({ column: f.column, operator: f.operator, value: f.value }));

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!name.trim() || !carteira || !base) throw new Error("Preencha nome, carteira e base.");
      if (providers.length === 0) throw new Error("Selecione ao menos um fornecedor.");
      if (providers.includes("TECH_IA") && !techiaOnly) {
        throw new Error("TECHIA (Discador) não pode ser combinada com outros fornecedores neste fluxo.");
      }
      if (!techiaOnly && !salesforceOnly) {
        if (!template) throw new Error("Selecione um template.");
        if ((templateSource === "otima_wpp" || templateSource === "otima_rcs") && !brokerCode) {
          throw new Error("Selecione o remetente (broker) Ótima.");
        }
        if (templateSource === "gosac_oficial" && (!gosacConnectionId || gosacConnectionId <= 0)) {
          throw new Error("Selecione a ilha GOSAC Oficial.");
        }
      }

      const pct: Record<string, number> = {};
      const pp = 100 / providers.length;
      providers.forEach((p) => {
        pct[p] = pp;
      });
      const providersConfig = {
        mode: "split" as const,
        providers,
        percentages: pct,
        ...(includeBaits ? { bait_ids: [...selectedBaitIds] } : {}),
      };

      const formattedFilters = buildFilterPayload(filters);
      const throttling_cfg =
        throttlingType === "linear"
          ? { qtd_msgs: throttlingConfig.qtd_msgs ?? 100, intervalo_minutos: throttlingConfig.intervalo_minutos ?? 60 }
          : throttlingType === "split"
            ? {
                fase1_percent: throttlingConfig.fase1_percent ?? 70,
                fase1_horas: throttlingConfig.fase1_horas ?? 2,
                fase2_horas: throttlingConfig.fase2_horas ?? 4,
              }
            : {};

      return saveRecurring({
        nome_campanha: name.trim(),
        table_name: base,
        carteira,
        template_id: techiaOnly || salesforceOnly ? 0 : templateSource === "local" ? parseInt(template, 10) : 0,
        template_code: techiaOnly ? "" : templateCode || null,
        template_source: techiaOnly ? "techia_discador" : salesforceOnly ? "salesforce" : templateSource || "local",
        broker_code: brokerCode || null,
        customer_code: customerCode || null,
        noah_channel_id: noahChannelId || null,
        noah_template_id: noahTemplateId || null,
        noah_language: noahLanguage,
        gosac_template_id: gosacTemplateId,
        gosac_connection_id: gosacConnectionId,
        gosac_variable_components: JSON.stringify(gosacVariableComponents || []),
        robbu_channel: templateSource === "robbu_oficial" ? 3 : null,
        variables_map: Object.keys(templateVariables).length > 0 ? templateVariables : null,
        providers_config: providersConfig,
        filters: formattedFilters,
        record_limit: recordLimit || 0,
        exclude_recent_phones: excludeRecentPhones ? 1 : 0,
        exclude_recent_hours: excludeRecentHours || 48,
        include_baits: includeBaits ? 1 : 0,
        throttling_type: throttlingType,
        throttling_config: throttling_cfg,
      });
    },
    onSuccess: () => {
      toast({ title: "Filtro salvo", description: "O filtro recorrente foi criado com sucesso." });
      queryClient.invalidateQueries({ queryKey: ["recurring-campaigns"] });
      setName("");
      setFilters([]);
      setTemplate("");
      setTemplateVariables({});
      setSelectedTemplateObj(null);
    },
    onError: (e: Error) => {
      toast({ title: "Erro", description: e.message, variant: "destructive" });
    },
  });

  const onTemplateChange = (templateId: string) => {
    setTemplate(templateId);
    const sel = templates.find((t: { id?: string }) => String(t.id) === String(templateId)) as Record<string, unknown> | undefined;
    if (!sel) return;
    setSelectedTemplateObj(sel);
    setTemplateCode(String(sel.templateCode || ""));
    setTemplateSource(String(sel.source || ""));
    setBrokerCode(String(sel.brokerCode || ""));
    setCustomerCode(String(sel.customerCode || ""));
    setNoahChannelId(sel.channelId != null ? String(sel.channelId) : "");
    setNoahTemplateId(sel.templateId != null ? String(sel.templateId) : "");
    setNoahLanguage(String(sel.language || "pt_BR"));
    const tid = sel.templateId;
    setGosacTemplateId(typeof tid === "number" && tid > 0 ? tid : parseInt(String(tid), 10) || null);
    setGosacConnectionId((sel.connectionId as number) ?? null);
    setGosacVariableComponents((sel.variableComponents as { componentId: number; variable: string }[]) || []);

    const otimaWppMap = buildInitialVariableMappingFromOtimaWpp(sel);
    const noahOfficialMap = buildInitialVariableMappingFromNoahOfficial(sel);
    const contentToParse = collectPlaceholdersSourceText(sel) || "";
    if (otimaWppMap) setTemplateVariables(otimaWppMap);
    else if (noahOfficialMap) setTemplateVariables(noahOfficialMap);
    else {
      const detected = extractVariables(contentToParse);
      const init: Record<string, VarMapping> = {};
      detected.forEach((v) => {
        init[v] = { type: "field", value: "nome" };
      });
      setTemplateVariables(init);
    }
  };

  const toggleProvider = (id: string) => {
    setProviders((prev) => (prev.includes(id) ? prev.filter((p) => p !== id) : [...prev, id]));
  };

  return (
    <Card className="border-primary/20">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg">
          <Database className="h-5 w-5 text-primary" />
          Novo filtro salvo
        </CardTitle>
        <CardDescription>
          Mesma ordem lógica da Nova Campanha: identificação, base, fornecedor, remetente, template e por último regras de
          envio (throttling). A execução continua manual em <strong>Gerar Agora</strong>.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2 sm:col-span-2">
            <Label>Nome da campanha</Label>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex.: Cobrança semanal — carteira X" />
          </div>
          <div className="space-y-2">
            <Label>Carteira</Label>
            <Select value={carteira} onValueChange={setCarteira}>
              <SelectTrigger>
                <SelectValue placeholder="Selecione" />
              </SelectTrigger>
              <SelectContent>
                {(carteiras as { id?: string; nome?: string }[]).map((c) => (
                  <SelectItem key={String(c.id)} value={String(c.id)}>
                    {c.nome || c.id}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Base de dados</Label>
            <Select value={base} onValueChange={setBase} disabled={!carteira}>
              <SelectTrigger>
                <SelectValue placeholder={carteira ? "Selecione a base" : "Escolha a carteira primeiro"} />
              </SelectTrigger>
              <SelectContent>
                {bases.map((b: { name?: string; id?: string }) => (
                  <SelectItem key={String(b.name || b.id)} value={String(b.name || b.id)}>
                    {String(b.name || b.id)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {baseUpdateData && (
              <p className="text-xs text-muted-foreground">{baseUpdateData.message}</p>
            )}
          </div>
        </div>

        <div className="space-y-2">
          <Label>Fornecedores</Label>
          <div className="flex flex-wrap gap-2">
            {PROVIDERS_LIST.map((p) => (
              <label
                key={p.id}
                className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm cursor-pointer hover:bg-muted/50"
              >
                <Checkbox checked={providers.includes(p.id)} onCheckedChange={() => toggleProvider(p.id)} />
                {p.name}
              </label>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">
            TECHIA não pode ser combinada com outros fornecedores. Com ela, o template é omitido e o payload usa{" "}
            <code className="text-[10px]">techia_discador</code>.
          </p>
        </div>

        {!techiaOnly && !salesforceOnly && (
          <>
            <div className="space-y-2">
              <Label>Template</Label>
              <Select
                value={template}
                onValueChange={onTemplateChange}
                disabled={!carteira || providers.length === 0}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Selecione carteira e fornecedores primeiro" />
                </SelectTrigger>
                <SelectContent className="max-h-72">
                  {filteredTemplates.map((t: { id?: string; name?: string }) => (
                    <SelectItem key={String(t.id)} value={String(t.id)}>
                      {t.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {(templateSource === "gosac_oficial" || providers.includes("GOSAC_OFICIAL")) && (
              <div className="space-y-2">
                <Label>Ilha / conexão (GOSAC Oficial)</Label>
                <Select
                  value={gosacConnectionId ? String(gosacConnectionId) : ""}
                  onValueChange={(v) => setGosacConnectionId(v ? parseInt(v, 10) : null)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione a ilha" />
                  </SelectTrigger>
                  <SelectContent>
                    {(gosacConnectionsData as { id?: number; name?: string }[]).map((c) => (
                      <SelectItem key={c.id} value={String(c.id)}>
                        {c.name || `Ilha ${c.id}`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            {(templateSource === "otima_wpp" || templateSource === "otima_rcs") && (
              <div className="space-y-2">
                <Label>Remetente (broker Ótima)</Label>
                <Select value={brokerCode} onValueChange={setBrokerCode}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione o remetente" />
                  </SelectTrigger>
                  <SelectContent>
                    {otimaBrokersForTemplate.map((b: { code?: string; name?: string }) => (
                      <SelectItem key={String(b.code)} value={String(b.code)}>
                        {b.name || b.code}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            {templateSource === "otima_wpp" && otimaWppMapperVariableKeys.length > 0 && (
              <TemplateVariableMapper
                variables={otimaWppMapperVariableKeys}
                mapping={templateVariables}
                onChange={setTemplateVariables}
              />
            )}
            {(templateSource === "noah_oficial" || templateSource === "noah") && noahOfficialMapperVariableKeys.length > 0 && (
              <TemplateVariableMapper
                variables={noahOfficialMapperVariableKeys}
                mapping={templateVariables}
                onChange={setTemplateVariables}
              />
            )}
            {templateSource === "local" && Object.keys(templateVariables).length > 0 && (
              <TemplateVariableMapper
                variables={Object.keys(templateVariables)}
                mapping={templateVariables}
                onChange={setTemplateVariables}
              />
            )}
          </>
        )}

        {salesforceOnly && (
          <Alert>
            <AlertDescription>Somente Salesforce: sem template local; conteúdo na automação MC/SF.</AlertDescription>
          </Alert>
        )}

        {techiaOnly && (
          <Alert>
            <AlertDescription>
              TECHIA Discador: variáveis são montadas a partir das colunas da base na execução (documento, nome, contrato,
              valor, atraso, COD_DEPARA, campanha_origem quando existirem).
            </AlertDescription>
          </Alert>
        )}

        <div className="space-y-2">
          <Label>Filtros do público</Label>
          {!base ? (
            <p className="text-sm text-muted-foreground">Selecione a base para montar os filtros.</p>
          ) : filtersLoading ? (
            <p className="text-sm text-muted-foreground">Carregando colunas filtráveis…</p>
          ) : (
            <FilterBuilder availableFilters={availableFilters} filters={filters} onChange={setFilters} />
          )}
        </div>

        <div className="rounded-lg border border-border p-4 space-y-4">
          <div>
            <h3 className="text-sm font-semibold mb-1">Recorrência e ritmo de envio</h3>
            <p className="text-xs text-muted-foreground mb-3">
              Não há agendamento por dia da semana no servidor: use <strong>Gerar Agora</strong> na lista. Abaixo, throttle
              aplicado quando os registros forem gerados (igual Nova Campanha).
            </p>
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <button
              type="button"
              className={`cursor-pointer rounded-lg border-2 p-3 text-left text-sm transition-all ${throttlingType === "none" ? "border-primary bg-primary/5" : "border-border"}`}
              onClick={() => setThrottlingType("none")}
            >
              Sem limite
            </button>
            <button
              type="button"
              className={`cursor-pointer rounded-lg border-2 p-3 text-left text-sm transition-all ${throttlingType === "linear" ? "border-primary bg-primary/5" : "border-border"}`}
              onClick={() => {
                setThrottlingType("linear");
                setThrottlingConfig((c) => ({ ...c, qtd_msgs: 100, intervalo_minutos: 60 }));
              }}
            >
              Linear
            </button>
            <button
              type="button"
              className={`cursor-pointer rounded-lg border-2 p-3 text-left text-sm transition-all ${throttlingType === "split" ? "border-primary bg-primary/5" : "border-border"}`}
              onClick={() => {
                setThrottlingType("split");
                setThrottlingConfig((c) => ({ ...c, fase1_percent: 70, fase1_horas: 2, fase2_horas: 4 }));
              }}
            >
              Em fases
            </button>
          </div>
          {throttlingType === "linear" && (
            <div className="flex flex-wrap gap-3 items-end">
              <div className="space-y-1">
                <Label className="text-xs">Msgs / lote</Label>
                <Input
                  type="number"
                  className="w-28"
                  value={String(throttlingConfig.qtd_msgs ?? 100)}
                  onChange={(e) =>
                    setThrottlingConfig((c) => ({ ...c, qtd_msgs: parseInt(e.target.value, 10) || 0 }))
                  }
                />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">Intervalo (min)</Label>
                <Input
                  type="number"
                  className="w-28"
                  value={String(throttlingConfig.intervalo_minutos ?? 60)}
                  onChange={(e) =>
                    setThrottlingConfig((c) => ({ ...c, intervalo_minutos: parseInt(e.target.value, 10) || 0 }))
                  }
                />
              </div>
            </div>
          )}
          {throttlingType === "split" && (
            <div className="flex flex-wrap gap-3 items-end">
              <div className="space-y-1">
                <Label className="text-xs">Fase 1 %</Label>
                <Input
                  type="number"
                  className="w-24"
                  value={String(throttlingConfig.fase1_percent ?? 70)}
                  onChange={(e) =>
                    setThrottlingConfig((c) => ({ ...c, fase1_percent: parseInt(e.target.value, 10) || 0 }))
                  }
                />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">Fase 1 (h)</Label>
                <Input
                  type="number"
                  className="w-24"
                  value={String(throttlingConfig.fase1_horas ?? 2)}
                  onChange={(e) =>
                    setThrottlingConfig((c) => ({ ...c, fase1_horas: parseFloat(e.target.value) || 0 }))
                  }
                />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">Fase 2 (h)</Label>
                <Input
                  type="number"
                  className="w-24"
                  value={String(throttlingConfig.fase2_horas ?? 4)}
                  onChange={(e) =>
                    setThrottlingConfig((c) => ({ ...c, fase2_horas: parseFloat(e.target.value) || 0 }))
                  }
                />
              </div>
            </div>
          )}
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label className="text-xs">Limite de registros (0 = todos)</Label>
              <Input
                type="number"
                value={recordLimit || ""}
                onChange={(e) => setRecordLimit(parseInt(e.target.value, 10) || 0)}
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Exclusão: não reenviar nos últimos (horas)</Label>
              <Input
                type="number"
                value={excludeRecentHours}
                onChange={(e) => setExcludeRecentHours(parseInt(e.target.value, 10) || 48)}
                disabled={!excludeRecentPhones}
              />
            </div>
          </div>
          <div className="flex items-center gap-3">
            <Switch checked={excludeRecentPhones} onCheckedChange={(c) => setExcludeRecentPhones(!!c)} />
            <span className="text-sm">Excluir telefones com envio recente</span>
          </div>
          <div className="rounded-md border border-dashed border-border p-3 space-y-2">
            <div className="flex items-center gap-3">
              <Switch checked={includeBaits} onCheckedChange={(on) => setIncludeBaits(!!on)} />
              <span className="text-sm font-medium">Incluir iscas ao gerar</span>
            </div>
            {includeBaits && (
              <div className="max-h-40 overflow-y-auto space-y-1">
                {(baitsData as { id?: unknown; telefone?: string; nome?: string }[]).map((isca) => {
                  const id = Number(isca.id);
                  if (!id) return null;
                  return (
                    <label key={id} className="flex items-center gap-2 text-sm">
                      <Checkbox
                        checked={selectedBaitIds.includes(id)}
                        onCheckedChange={(ck) =>
                          setSelectedBaitIds((prev) => (ck ? [...prev, id] : prev.filter((x) => x !== id)))
                        }
                      />
                      {isca.telefone}
                      {isca.nome ? ` — ${isca.nome}` : ""}
                    </label>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        <Button
          className="w-full sm:w-auto gradient-primary"
          disabled={saveMutation.isPending}
          onClick={() => saveMutation.mutate()}
        >
          {saveMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
          Salvar filtro recorrente
        </Button>
      </CardContent>
    </Card>
  );
}
