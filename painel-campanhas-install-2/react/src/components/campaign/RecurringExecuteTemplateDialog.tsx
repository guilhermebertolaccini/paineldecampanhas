/**
 * Modal “Gerar agora”: confirma execução e permite trocar o template antes do disparo
 * (carteira/fornecedores já definidos na campanha salva — mesma construção de lista do Novo filtro salvo).
 */
import { useEffect, useMemo, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader2, Play } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
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
import { Alert, AlertDescription } from "@/components/ui/alert";
import { useMakingCostCenters, useMakingTeams } from "@/hooks/useMakingLists";
import {
  getCarteiras,
  getFilters,
  getMessages,
  getTemplatesByWallet,
  getGosacOficialTemplates,
  getGosacOficialConnections,
  getRobbuOficialTemplates,
  getOtimaTemplates,
  getOtimaBrokers,
  type RecurringExecuteTemplatePayload,
} from "@/lib/api";
import {
  TemplateVariableMapper,
  VarMapping,
  extractVariables,
  collectPlaceholdersSourceText,
  buildInitialVariableMappingFromOtimaWpp,
  buildInitialVariableMappingFromNoahOfficial,
  listOtimaWppVariableKeysFromTemplate,
  listNoahOfficialVariableKeysFromTemplate,
  buildDynamicMapperFieldOptions,
} from "@/components/campaign/TemplateVariableMapper";
import { PROVIDER_TO_SOURCE_MAP } from "@/components/campaign/recurringTemplateProviders";

export const RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE = "__recurring_execute_saved__";

export type CampaignExecuteTarget = {
  id: string;
  nome_campanha: string;
  tabela_origem?: string;
  template_id?: string;
  template_code?: string;
  template_source?: string;
  broker_code?: string;
  customer_code?: string;
  carteira?: string;
  providers_config?: string;
  providers_config_parsed?: Record<string, unknown>;
  template_meta?: string;
  variables_map?: string;
};

type ExecuteDraft = {
  template_id: number;
  template_code: string;
  template_source: string;
  broker_code: string;
  customer_code: string;
  noah_channel_id: string;
  noah_template_id: string;
  noah_language: string;
  noah_template_data: string;
  noah_template_name: string;
  gosac_template_id: number;
  gosac_connection_id: number;
  robbu_channel: number;
  making_team_id: number;
  making_cost_center_id: number;
  gosac_variable_components: string;
  template_variables: Record<string, VarMapping>;
};

function getProvidersParsed(campaign: CampaignExecuteTarget): Record<string, unknown> {
  const p = campaign.providers_config_parsed;
  if (p && typeof p === "object" && p !== null && !Array.isArray(p)) {
    return { ...p };
  }
  const raw = campaign.providers_config;
  if (!raw || typeof raw !== "string") return {};
  try {
    const parsed = JSON.parse(raw);
    return typeof parsed === "object" && parsed !== null && !Array.isArray(parsed) ? { ...parsed } : {};
  } catch {
    return {};
  }
}

function providersListFromCampaign(campaign: CampaignExecuteTarget): string[] {
  const cfg = getProvidersParsed(campaign);
  return Array.isArray(cfg.providers) ? (cfg.providers as string[]) : [];
}

function parseTemplateMeta(raw?: string): Record<string, unknown> {
  if (!raw) return {};
  try {
    const o = JSON.parse(raw);
    return typeof o === "object" && o !== null ? o : {};
  } catch {
    return {};
  }
}

function parseVariablesMapStored(raw?: string): Record<string, VarMapping> {
  if (!raw) return {};
  try {
    const o = JSON.parse(raw);
    return typeof o === "object" && o !== null && !Array.isArray(o) ? (o as Record<string, VarMapping>) : {};
  } catch {
    return {};
  }
}

function templateSummary(c: CampaignExecuteTarget): string {
  const src = c.template_source || "local";
  if (src === "techia_discador") return "TECHIA Discador";
  if (src === "salesforce") return "Salesforce";
  if (c.template_code) return c.template_code;
  if (c.template_id) return String(c.template_id);
  return "—";
}

function draftFromCampaign(campaign: CampaignExecuteTarget): ExecuteDraft {
  const src = campaign.template_source || "local";
  const meta = parseTemplateMeta(campaign.template_meta);
  const vm = parseVariablesMapStored(campaign.variables_map);

  return {
    template_id:
      src === "local" && campaign.template_id ? parseInt(String(campaign.template_id), 10) || 0 : 0,
    template_code: campaign.template_code || "",
    template_source:
      src === "techia_discador" ? "techia_discador" : src === "salesforce" ? "salesforce" : src || "local",
    broker_code: campaign.broker_code || "",
    customer_code: campaign.customer_code || "",
    noah_channel_id: meta.noah_channel_id != null ? String(meta.noah_channel_id) : "",
    noah_template_id: meta.noah_template_id != null ? String(meta.noah_template_id) : "",
    noah_language: typeof meta.noah_language === "string" ? meta.noah_language : "pt_BR",
    noah_template_data:
      typeof meta.noah_template_data === "object" && meta.noah_template_data !== null
        ? JSON.stringify(meta.noah_template_data)
        : "",
    noah_template_name: typeof meta.noah_template_name === "string" ? meta.noah_template_name : "",
    gosac_template_id:
      typeof meta.gosac_template_id === "number" ? meta.gosac_template_id : parseInt(String(meta.gosac_template_id ?? 0), 10) || 0,
    gosac_connection_id:
      typeof meta.gosac_connection_id === "number" ? meta.gosac_connection_id : parseInt(String(meta.gosac_connection_id ?? 0), 10) || 0,
    gosac_variable_components: JSON.stringify(
      Array.isArray(meta.gosac_variable_components) ? meta.gosac_variable_components : [],
    ),
    robbu_channel:
      typeof meta.robbu_channel === "number"
        ? meta.robbu_channel
        : parseInt(String(meta.robbu_channel ?? "3"), 10) || 3,
    making_team_id:
      typeof meta.making_team_id === "number"
        ? meta.making_team_id
        : parseInt(String(meta.making_team_id ?? 0), 10) || 0,
    making_cost_center_id:
      typeof meta.making_cost_center_id === "number"
        ? meta.making_cost_center_id
        : parseInt(String(meta.making_cost_center_id ?? 0), 10) || 0,
    template_variables: vm,
  };
}

function matchCampaignToTemplateOptionId(
  campaign: CampaignExecuteTarget,
  list: { id?: string; source?: string; templateCode?: string; templateId?: unknown; connectionId?: unknown; channelId?: unknown; sendMetaTemplate?: string }[],
): string {
  const src = campaign.template_source || "local";
  const code = String(campaign.template_code || "").trim();
  const tid = String(campaign.template_id || "");
  const meta = parseTemplateMeta(campaign.template_meta);

  for (const t of list) {
    const id = String(t.id ?? "");
    const ts = String(t.source || "");
    const tCode = String(t.templateCode || "");

    if (src === "local" && ts === "local" && String(t.id) === tid) {
      return id;
    }

    if ((src === "otima_wpp" || src === "otima_rcs") && ts === src && code && tCode === code) {
      return id;
    }

    if (src === "gosac_oficial" && ts === "gosac_oficial") {
      const gtMeta = typeof meta.gosac_template_id === "number" ? meta.gosac_template_id : 0;
      const connMeta =
        typeof meta.gosac_connection_id === "number" ? meta.gosac_connection_id : parseInt(String(meta.gosac_connection_id ?? 0), 10) || 0;
      const tTid =
        typeof t.templateId === "number" ? t.templateId : parseInt(String(t.templateId ?? 0), 10);
      const connT =
        typeof t.connectionId === "number"
          ? t.connectionId
          : parseInt(String(t.connectionId ?? 0), 10) || 0;
      if (gtMeta > 0 && tTid === gtMeta && connMeta === connT && connMeta > 0) return id;
      if (code && tCode === code) return id;
    }

    if ((src === "noah_oficial" || src === "noah") && ts === "noah_oficial") {
      const ch = meta.noah_channel_id != null ? String(meta.noah_channel_id) : "";
      const nti = meta.noah_template_id != null ? String(meta.noah_template_id) : "";
      const chSel = t.channelId != null ? String(t.channelId) : "";
      const idSel = t.templateId != null ? String(t.templateId) : "";
      if ((ch === chSel || ch === "" || chSel === "") && nti !== "" && nti === idSel) return id;
      if (code && tCode === code) return id;
    }

    if (src === "robbu_oficial" && ts === "robbu_oficial" && code && tCode === code) {
      return id;
    }

    if (src === "making_oficial" && ts === "making_oficial") {
      const send = String(t.sendMetaTemplate || "").trim();
      const useCode = send || tCode;
      if (code && useCode === code) return id;
    }
  }
  return "";
}

function applyTemplateRowToDraft(
  sel: Record<string, unknown>,
  prev: ExecuteDraft,
  campaign: CampaignExecuteTarget,
  resetVarsFromTemplate: boolean,
): ExecuteDraft {
  const sendMeta =
    typeof sel.sendMetaTemplate === "string" && sel.sendMetaTemplate.trim() !== ""
      ? sel.sendMetaTemplate.trim()
      : "";
  const tplCode = sendMeta || String(sel.templateCode || "");
  const src = String(sel.source || "");

  const nextVars: Record<string, VarMapping> =
    !resetVarsFromTemplate && Object.keys(prev.template_variables).length > 0
      ? prev.template_variables
      : (buildInitialVariableMappingFromOtimaWpp(sel) ??
          buildInitialVariableMappingFromNoahOfficial(sel) ??
          (() => {
            const contentToParse = collectPlaceholdersSourceText(sel) || "";
            const detected = extractVariables(contentToParse);
            const init: Record<string, VarMapping> = {};
            detected.forEach((v) => {
              init[v] = { type: "field", value: "nome" };
            });
            return init;
          })());

  const baseFromCampaign = draftFromCampaign(campaign);

  let making_team_id = prev.making_team_id;
  let making_cost_center_id = prev.making_cost_center_id;
  if (src !== "making_oficial") {
    making_team_id = baseFromCampaign.making_team_id;
    making_cost_center_id = baseFromCampaign.making_cost_center_id;
  }

  const gosacTplNum =
    typeof sel.templateId === "number"
      ? sel.templateId
      : parseInt(String(sel.templateId ?? 0), 10) || 0;
  const gosacConnNum =
    typeof sel.connectionId === "number"
      ? sel.connectionId
      : parseInt(String(sel.connectionId ?? "0"), 10) || 0;

  return {
    ...prev,
    template_id: src === "local" ? parseInt(String(sel.id), 10) || 0 : 0,
    template_code: tplCode,
    template_source: src || prev.template_source,
    broker_code: String(sel.brokerCode || ""),
    customer_code: String(sel.customerCode || ""),
    noah_channel_id: sel.channelId != null ? String(sel.channelId) : "",
    noah_template_id: sel.templateId != null ? String(sel.templateId) : "",
    noah_language: String(sel.language || prev.noah_language || "pt_BR"),
    noah_template_data: prev.noah_template_data,
    noah_template_name:
      typeof sel.templateName === "string"
        ? sel.templateName
        : typeof sel.name === "string"
          ? String(sel.name)
          : prev.noah_template_name,
    gosac_template_id: src === "gosac_oficial" ? gosacTplNum : baseFromCampaign.gosac_template_id,
    gosac_connection_id:
      src === "gosac_oficial"
        ? gosacConnNum || baseFromCampaign.gosac_connection_id
        : baseFromCampaign.gosac_connection_id,
    gosac_variable_components:
      src === "gosac_oficial"
        ? JSON.stringify(
            Array.isArray(sel.variableComponents)
              ? sel.variableComponents
              : ([] as unknown[]),
          )
        : baseFromCampaign.gosac_variable_components,
    robbu_channel:
      src === "robbu_oficial"
        ? typeof sel.channelId === "number"
          ? sel.channelId
          : parseInt(String(sel.channelId ?? "3"), 10) || 3
        : baseFromCampaign.robbu_channel,
    making_team_id,
    making_cost_center_id,
    template_variables: nextVars,
  };
}

export function serializeRecurringExecuteTemplatePayload(d: ExecuteDraft): RecurringExecuteTemplatePayload {
  const vars =
    typeof d.template_variables === "object" && d.template_variables !== null && Object.keys(d.template_variables).length > 0
      ? JSON.stringify(d.template_variables)
      : null;

  const payload: RecurringExecuteTemplatePayload = {
    apply_recurring_template_override: "1",
    template_id: d.template_id,
    template_code: d.template_code,
    template_source: d.template_source,
    broker_code: d.broker_code || "",
    customer_code: d.customer_code || "",
  };

  if (vars !== null) payload.variables_map = vars;

  if (d.template_source === "noah_oficial" || d.template_source === "noah") {
    payload.noah_channel_id = parseInt(String(d.noah_channel_id || "0"), 10) || 0;
    payload.noah_template_id = parseInt(String(d.noah_template_id || "0"), 10) || 0;
    payload.noah_language = d.noah_language || "pt_BR";
    if (d.noah_template_data.trim()) payload.noah_template_data = d.noah_template_data;
    if (d.noah_template_name.trim()) payload.noah_template_name = d.noah_template_name.trim();
  }

  if (d.template_source === "gosac_oficial") {
    payload.gosac_template_id = d.gosac_template_id || 0;
    payload.gosac_connection_id = d.gosac_connection_id || 0;
    payload.gosac_variable_components = d.gosac_variable_components || "[]";
  }

  if (d.template_source === "robbu_oficial") {
    payload.robbu_channel = d.robbu_channel || 3;
  }

  if (d.template_source === "making_oficial") {
    payload.making_team_id = d.making_team_id || 0;
    payload.making_cost_center_id = d.making_cost_center_id || 0;
  }

  return payload;
}

/** Permite só troca dentro da mesma linha técnica (ex.: dois templates Ótima WPP, ou WPP ↔ RCS quando ambos Ótima). */
function templateFamiliesCompatible(campaignSrc: string, rowSrc: string): boolean {
  const c = campaignSrc || "local";
  const r = rowSrc || "local";
  if ((c === "noah" || c === "noah_oficial") && (r === "noah" || r === "noah_oficial")) return true;
  const ot = new Set(["otima_wpp", "otima_rcs"]);
  if (ot.has(c) && ot.has(r)) return true;
  return c === r;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  campaign: CampaignExecuteTarget | null;
  isExecuting?: boolean;
  onExecute: (payload: RecurringExecuteTemplatePayload | null) => void;
};

export function RecurringExecuteTemplateDialog({ open, onOpenChange, campaign, isExecuting, onExecute }: Props) {
  const carteiraStr = campaign?.carteira ?? "";
  /** Lista estável por campanha (evita `filteredTemplates` novo a cada render e loop no efeito de auto‑match). */
  const providers = useMemo(() => {
    if (!campaign) return [] as string[];
    return providersListFromCampaign(campaign);
  }, [campaign]);
  const userTouchedSelRef = useRef(false);

  const [templateSel, setTemplateSel] = useState(RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE);
  const [draft, setDraft] = useState<ExecuteDraft | null>(null);
  const [selectedTemplateObj, setSelectedTemplateObj] = useState<Record<string, unknown> | null>(null);

  const salesforceOnly = useMemo(() => providers.length > 0 && providers.every((p) => p === "SALESFORCE"), [providers]);
  const techiaOnly = useMemo(() => providers.length > 0 && providers.every((p) => p === "TECH_IA"), [providers]);

  useEffect(() => {
    userTouchedSelRef.current = false;
    if (!open || !campaign) return;
    setDraft(draftFromCampaign(campaign));
    setSelectedTemplateObj(null);
    setTemplateSel(RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE);
  }, [open, campaign]);

  const { data: carteiras = [] } = useQuery({
    queryKey: ["carteiras"],
    queryFn: getCarteiras,
    enabled: open && !!campaign,
  });

  const selectedWalletRow = useMemo(
    () => (carteiras as { id?: string; id_carteira?: string }[]).find((c) => String(c.id) === String(carteiraStr)),
    [carteiraStr, carteiras],
  );

  const rawWallet =
    selectedWalletRow?.id_carteira != null && String(selectedWalletRow.id_carteira).trim() !== ""
      ? String(selectedWalletRow.id_carteira).trim()
      : undefined;

  const campaignSrc = campaign?.template_source ?? "";
  const otimaTemplatesChannel =
    campaignSrc === "otima_rcs" ? "rcs" : campaignSrc === "otima_wpp" ? "wpp" : ("both" as const);

  const tableName = campaign?.tabela_origem ?? "";

  const { data: localTemplatesData = [] } = useQuery({
    queryKey: ["messages"],
    queryFn: getMessages,
    enabled: open && !!campaign && !salesforceOnly && !techiaOnly,
  });

  const { data: baseFilterDefs = [], isLoading: baseFiltersLoading } = useQuery({
    queryKey: ["filters", "recurring-exec", tableName],
    queryFn: () => getFilters(tableName),
    enabled: open && !!tableName && !salesforceOnly && !techiaOnly,
  });

  const mapperFieldOptionsRec = useMemo(() => {
    const baseCols = Array.isArray(baseFilterDefs)
      ? baseFilterDefs.map((f: { column?: string }) => String(f.column || "").trim()).filter(Boolean)
      : [];
    return buildDynamicMapperFieldOptions([], baseCols);
  }, [baseFilterDefs]);

  const mapperSourceLabel =
    Array.isArray(baseFilterDefs) && baseFilterDefs.length > 0 ? "Base" : "BD";

  const { data: externalTemplatesData = [] } = useQuery({
    queryKey: ["external-templates-rec-exec", carteiraStr],
    queryFn: () => getTemplatesByWallet(carteiraStr),
    enabled: open && !!campaign?.carteira && !salesforceOnly && !techiaOnly,
    staleTime: 60 * 1000,
  });

  const { data: gosacTemplatesData = [] } = useQuery({
    queryKey: ["gosac-templates-rec-exec", carteiraStr],
    queryFn: () => getGosacOficialTemplates({ carteira: carteiraStr }),
    enabled: open && !!carteiraStr && providers.includes("GOSAC_OFICIAL") && !salesforceOnly && !techiaOnly,
    staleTime: 5 * 60 * 1000,
  });

  const { data: gosacConnectionsData = [] } = useQuery({
    queryKey: ["gosac-connections-rec-exec", carteiraStr],
    queryFn: () => getGosacOficialConnections({ carteira: carteiraStr }),
    enabled: open && !!carteiraStr && providers.includes("GOSAC_OFICIAL") && !salesforceOnly && !techiaOnly,
  });

  const { data: robbuTemplatesData = [] } = useQuery({
    queryKey: ["robbu-templates-rec-exec"],
    queryFn: getRobbuOficialTemplates,
    enabled: open && !!campaign && !salesforceOnly && !techiaOnly,
    staleTime: 5 * 60 * 1000,
  });

  const { data: otimaTemplatesData = [] } = useQuery({
    queryKey: ["otima-templates-rec-exec", rawWallet, carteiraStr, otimaTemplatesChannel],
    queryFn: () =>
      getOtimaTemplates(rawWallet, carteiraStr, otimaTemplatesChannel as "both" | "rcs" | "wpp"),
    enabled: open && !!campaign?.carteira && !salesforceOnly && !techiaOnly,
    staleTime: 60 * 1000,
  });

  const { data: otimaBrokersData = [] } = useQuery({
    queryKey: ["otima-brokers-rec-exec"],
    queryFn: getOtimaBrokers,
    enabled: open && !!campaign && !salesforceOnly && !techiaOnly,
    staleTime: 5 * 60 * 1000,
  });

  type UnifiedTplRow = Record<string, unknown> & {
    id: string;
    name: string;
    source: string;
    templateCode?: string;
    provider?: string | null;
    walletId?: string | number | null;
    channelId?: unknown;
    templateId?: unknown;
    connectionId?: unknown;
    sendMetaTemplate?: string;
    brokerCode?: string;
    customerCode?: string;
    variableComponents?: unknown;
    components?: unknown;
    language?: string;
    templateName?: string;
  };

  const templates = useMemo((): UnifiedTplRow[] => {
    const local = (localTemplatesData || []).map(
      (t: { id?: number; title?: string; source?: string; provider?: string; wallet_id?: string }) =>
        ({
          id: String(t.id),
          name: String(t.title || ""),
          source: String(t.source || "local"),
          provider: t.provider || null,
          walletId: t.wallet_id ?? null,
          templateCode: "",
        }) satisfies UnifiedTplRow,
    );

    const otima = (Array.isArray(otimaTemplatesData) ? otimaTemplatesData : []).map((t: Record<string, unknown>) => {
      const isWpp = t.source === "otima_wpp";
      const code = (t.template_code as string) || "";
      const stableId =
        t.id != null && String(t.id) !== ""
          ? String(t.id)
          : isWpp && code
            ? `wpp_${code}`
            : `otima_${String(t.wallet_id || "x")}_${code || "x"}`;
      const srcLane = typeof t.source === "string" && (t.source === "otima_wpp" || t.source === "otima_rcs") ? t.source : "otima_wpp";
      return {
        id: stableId,
        name: isWpp ? code || String(t.name || "") || "Template WhatsApp" : String(t.name || ""),
        source: srcLane,
        templateCode: code,
        brokerCode: String(t.broker_code ?? ""),
        customerCode: String(t.customer_code ?? ""),
        walletId: t.wallet_id,
        variable_sample: t.variable_sample ?? null,
        variableSample: t.variable_sample ?? null,
      } as UnifiedTplRow;
    });

    const external = (Array.isArray(externalTemplatesData) ? externalTemplatesData : []).map((t: Record<string, unknown>) => {
      const isGosac = t.provider === "Gosac Oficial";
      const isNoah = t.provider === "Noah Oficial";
      const isRobbu = t.provider === "Robbu Oficial";
      const isMaking = t.provider === "Making Oficial";
      const source = isGosac
        ? "gosac_oficial"
        : isNoah
          ? "noah_oficial"
          : isRobbu
            ? "robbu_oficial"
            : isMaking
              ? "making_oficial"
              : "external";
      const makingSendMeta =
        typeof t.send_meta_template === "string" && t.send_meta_template.trim() !== ""
          ? t.send_meta_template.trim()
          : "";
      return {
        id: `${t.provider}_${String(t.id)}_${String(t.id_ambient ?? "")}`,
        name: String(t.name || String(t.id || "")),
        source,
        templateCode:
          makingSendMeta || String((t.templateName as string) || (t.name as string) || "") || "",
        sendMetaTemplate: makingSendMeta || undefined,
        walletId: t.id_ambient,
        channelId: t.channelId,
        templateId: t.templateId,
        templateName: t.templateName || t.name,
        language: typeof t.language === "string" ? t.language : "pt_BR",
        components: t.components,
      } as UnifiedTplRow;
    });

    const gosac = (Array.isArray(gosacTemplatesData) ? gosacTemplatesData : []).map((t: Record<string, unknown>) => {
      const numId =
        typeof t.templateId === "number" && (t.templateId as number) > 0
          ? (t.templateId as number)
          : parseInt(String(t.id), 10) || 0;
      return {
        id: `Gosac Oficial_${String(t.id ?? t.name ?? "")}_${String(t.id_ambient ?? "default")}`,
        name: String(t.name || String(t.id || "")),
        source: "gosac_oficial",
        templateCode: String(t.name || String(t.id || "")),
        walletId: t.id_ambient,
        templateId: numId > 0 ? numId : t.id,
        connectionId: t.connectionId ?? null,
        variableComponents: Array.isArray(t.variableComponents)
          ? t.variableComponents
          : Array.isArray(t.variable_components)
            ? t.variable_components
            : [],
      } as UnifiedTplRow;
    });

    const robbu = (Array.isArray(robbuTemplatesData) ? robbuTemplatesData : []).map((t: Record<string, unknown>) => ({
      id: `Robbu Oficial_${String(t.id || t.name || "")}_static`,
      name: String(t.name || String(t.id || "")),
      source: "robbu_oficial",
      templateCode: String((t.templateName as string) || (t.name as string) || ""),
      channelId: t.channelId ?? 3,
      templateId: t.templateId,
    })) as UnifiedTplRow[];

    return [...local, ...otima, ...external, ...gosac, ...robbu];
  }, [localTemplatesData, otimaTemplatesData, externalTemplatesData, gosacTemplatesData, robbuTemplatesData]);

  const filteredTemplates = useMemo(() => {
    const noneSelected = providers.length === 0;
    const selectedSources = providers.flatMap((p) => PROVIDER_TO_SOURCE_MAP[p] ?? []);
    const walletCode = selectedWalletRow?.id_carteira ? String(selectedWalletRow.id_carteira) : null;

    return templates.filter((t) => {
      if (t.source !== "local") {
        if (noneSelected) return false;
        if (t.source === "external") return false;
        return selectedSources.includes(String(t.source || ""));
      }
      const hasP = !!t.provider;
      const hasW = !!t.walletId;
      if (!hasP && !hasW) return true;
      const providerMatch = !hasP || noneSelected || providers.includes(String(t.provider));
      const walletMatch = !hasW || !walletCode || String(t.walletId) === walletCode;
      return providerMatch && walletMatch;
    });
  }, [templates, providers, selectedWalletRow]);

  useEffect(() => {
    if (!open || !campaign || userTouchedSelRef.current || filteredTemplates.length === 0) return;
    const match = matchCampaignToTemplateOptionId(campaign, filteredTemplates);
    if (!match) return;
    setTemplateSel(match);
    const sel = filteredTemplates.find((x) => String(x.id) === String(match));
    if (!sel) return;
    setSelectedTemplateObj(sel);
    setDraft((prev) => {
      const base = prev || draftFromCampaign(campaign);
      return applyTemplateRowToDraft(sel as Record<string, unknown>, base, campaign, true);
    });
  }, [open, campaign, filteredTemplates]);

  const otimaBrokersForTemplate = useMemo(() => {
    const list = Array.isArray(otimaBrokersData) ? otimaBrokersData : [];
    const isDiag = (b: { code?: string }) => String(b.code ?? "").startsWith("error_");
    if (draft?.template_source === "otima_wpp") {
      return list.filter(
        (b: { channel?: string; name?: string }) =>
          isDiag(b) || b.channel === "wpp" || (!b.channel && /wpp|whatsapp/i.test(String(b.name ?? ""))),
      );
    }
    if (draft?.template_source === "otima_rcs") {
      return list.filter(
        (b: { channel?: string; name?: string }) =>
          isDiag(b) || b.channel === "rcs" || (!b.channel && /rcs/i.test(String(b.name ?? ""))),
      );
    }
    return list;
  }, [otimaBrokersData, draft?.template_source]);

  const makingExecEnabled =
    !!(open && draft && draft.template_source === "making_oficial" && !salesforceOnly && !techiaOnly);
  const { data: makingTeamsExec = [], isLoading: mkTeamsLoading, isError: mkTeamsErr } =
    useMakingTeams(makingExecEnabled);
  const {
    data: makingCostExec = [],
    isLoading: mkCostLoading,
    isError: mkCostErr,
  } = useMakingCostCenters(makingExecEnabled);

  const otimaWppMapperVariableKeys = useMemo(() => {
    if (draft?.template_source !== "otima_wpp") return [];
    const fromTpl = listOtimaWppVariableKeysFromTemplate(selectedTemplateObj);
    const fromState = Object.keys(draft?.template_variables || {});
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
  }, [draft?.template_source, draft?.template_variables, selectedTemplateObj]);

  const noahOfficialMapperVariableKeys = useMemo(() => {
    if (draft?.template_source !== "noah_oficial" && draft?.template_source !== "noah") return [];
    const fromTpl = listNoahOfficialVariableKeysFromTemplate(selectedTemplateObj);
    const fromState = Object.keys(draft?.template_variables || {});
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
  }, [draft?.template_source, draft?.template_variables, selectedTemplateObj]);

  const footerDisabledReason = useMemo(() => {
    if (!draft || salesforceOnly || techiaOnly) return null;

    const effectiveSource = draft.template_source || "local";

    if ((effectiveSource === "otima_wpp" || effectiveSource === "otima_rcs") && !draft.broker_code.trim()) {
      return "Selecione o remetente (broker) Ótima.";
    }

    if (effectiveSource === "gosac_oficial") {
      const cid = draft.gosac_connection_id ?? 0;
      if (!cid || cid <= 0) return "Selecione a ilha GOSAC Oficial.";
    }

    if (effectiveSource === "making_oficial") {
      if (!draft.making_team_id || draft.making_team_id <= 0 || !draft.making_cost_center_id || draft.making_cost_center_id <= 0)
        return "Making Oficial: selecione equipe e centro de custo.";
    }

    if (effectiveSource === "local" && (!draft.template_id || draft.template_id <= 0) && templateSel !== RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE) {
      return "Template local inválido.";
    }

    const codeOk =
      !!draft.template_code && String(draft.template_code).trim() !== ""
        ? true
        : effectiveSource === "local" ? draft.template_id > 0 : false;

    const keep = templateSel === RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE;
    if (!keep && !["techia_discador", "salesforce"].includes(effectiveSource) && !codeOk && effectiveSource !== "local") {
      return "Selecione um template válido.";
    }

    return null;
  }, [draft, salesforceOnly, techiaOnly, templateSel]);

  const handleTemplateDropdownChange = (value: string) => {
    userTouchedSelRef.current = true;
    setTemplateSel(value);
    if (!campaign) return;

    if (value === RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE) {
      setDraft(draftFromCampaign(campaign));
      setSelectedTemplateObj(null);
      return;
    }

    const sel = filteredTemplates.find((x) => String(x.id) === String(value));
    if (!sel) return;

    if (
      !templateFamiliesCompatible(String(campaign.template_source || "local"), String(sel.source || "local"))
    ) {
      return;
    }

    setSelectedTemplateObj(sel);
    setDraft((prev) => {
      const basePrev = prev || draftFromCampaign(campaign);
      return applyTemplateRowToDraft(sel, basePrev, campaign, true);
    });
  };

  const validateAndExecute = () => {
    if (!campaign) return;
    if (salesforceOnly || techiaOnly) {
      onExecute(null);
      return;
    }
    if (!draft) return;

    /* Manter atual: não envia override (BD permanece como está). */
    if (templateSel === RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE) {
      onExecute(null);
      return;
    }

    const chosen = filteredTemplates.find((x) => String(x.id) === String(templateSel));
    if (chosen) {
      if (
        !templateFamiliesCompatible(String(campaign.template_source || "local"), String(chosen.source || "local"))
      )
        return;
    }

    onExecute(serializeRecurringExecuteTemplatePayload(draft));
  };

  if (!campaign) return null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Gerar envio agora</DialogTitle>
          <DialogDescription>
            Confirmar disparo para <strong>{campaign.nome_campanha}</strong>. Você pode trocar o template antes de gerar; a
            lista respeita a carteira e os fornecedores deste filtro salvo.
          </DialogDescription>
        </DialogHeader>

        {salesforceOnly || techiaOnly ? (
          <p className="text-sm text-muted-foreground py-2">
            Este filtro não usa seleção de template no painel
            {techiaOnly ? " (TECHIA Discador)." : " (somente Salesforce)."}
          </p>
        ) : (
          <div className="space-y-4 py-2">
            {baseFiltersLoading && (
              <p className="text-xs text-muted-foreground">Carregando colunas da base para o mapeamento de variáveis…</p>
            )}

            <div className="space-y-2">
              <Label>Template para este disparo</Label>
              <Select value={templateSel} onValueChange={handleTemplateDropdownChange}>
                <SelectTrigger>
                  <SelectValue placeholder="Carregando opções…" />
                </SelectTrigger>
                <SelectContent className="max-h-72">
                  <SelectItem value={RECURRING_EXECUTE_KEEP_TEMPLATE_VALUE}>
                    (Manter atual) {templateSummary(campaign)}
                  </SelectItem>
                  {filteredTemplates.map((t) => (
                    <SelectItem key={String(t.id)} value={String(t.id)}>
                      {String(t.name || t.id)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {(draft?.template_source === "gosac_oficial" || providers.includes("GOSAC_OFICIAL")) && (
              <div className="space-y-2">
                <Label>Ilha / conexão (GOSAC Oficial)</Label>
                <Select
                  value={draft?.gosac_connection_id ? String(draft.gosac_connection_id) : ""}
                  onValueChange={(v) =>
                    setDraft((prev) =>
                      prev ? { ...prev, gosac_connection_id: v ? parseInt(v, 10) : 0 } : prev,
                    )
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione a ilha" />
                  </SelectTrigger>
                  <SelectContent>
                    {(gosacConnectionsData as { id?: number; name?: string }[]).map((c) => (
                      <SelectItem key={String(c.id)} value={String(c.id)}>
                        {c.name || `Ilha ${c.id}`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            {draft?.template_source === "making_oficial" && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Equipe (Making)</Label>
                  <Select
                    value={draft.making_team_id ? String(draft.making_team_id) : ""}
                    onValueChange={(v) =>
                      setDraft((prev) => (prev ? { ...prev, making_team_id: v ? parseInt(v, 10) : 0 } : prev))
                    }
                    disabled={mkTeamsLoading}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder={mkTeamsLoading ? "Carregando…" : "Selecione"} />
                    </SelectTrigger>
                    <SelectContent>
                      {mkTeamsErr ? (
                        <div className="px-3 py-2 text-xs text-destructive">Falha ao carregar equipes.</div>
                      ) : null}
                      {(makingTeamsExec as { id: string; name: string }[]).map((r) => (
                        <SelectItem key={r.id} value={String(r.id)}>
                          {r.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Centro de custo (Making)</Label>
                  <Select
                    value={draft.making_cost_center_id ? String(draft.making_cost_center_id) : ""}
                    onValueChange={(v) =>
                      setDraft((prev) =>
                        prev ? { ...prev, making_cost_center_id: v ? parseInt(v, 10) : 0 } : prev,
                      )
                    }
                    disabled={mkCostLoading}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder={mkCostLoading ? "Carregando…" : "Selecione"} />
                    </SelectTrigger>
                    <SelectContent>
                      {mkCostErr ? (
                        <div className="px-3 py-2 text-xs text-destructive">Falha ao carregar centros.</div>
                      ) : null}
                      {(makingCostExec as { id: string; name: string }[]).map((r) => (
                        <SelectItem key={r.id} value={String(r.id)}>
                          {r.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            )}

            {(draft?.template_source === "otima_wpp" || draft?.template_source === "otima_rcs") && (
              <div className="space-y-2">
                <Label>Remetente (broker Ótima)</Label>
                <Select
                  value={draft?.broker_code || ""}
                  onValueChange={(v) =>
                    setDraft((prev) => (prev ? { ...prev, broker_code: String(v || "") } : prev))
                  }
                >
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

            {draft?.template_source === "otima_wpp" && otimaWppMapperVariableKeys.length > 0 && (
              <TemplateVariableMapper
                variables={otimaWppMapperVariableKeys}
                mapping={draft.template_variables}
                onChange={(next) =>
                  setDraft((prev) => (prev ? { ...prev, template_variables: next } : prev))
                }
                fieldOptions={mapperFieldOptionsRec}
                fieldSourceLabel={mapperSourceLabel}
              />
            )}
            {draft?.template_source === "otima_rcs" && Object.keys(draft.template_variables).length > 0 && (
              <TemplateVariableMapper
                variables={Object.keys(draft.template_variables)}
                mapping={draft.template_variables}
                onChange={(next) =>
                  setDraft((prev) => (prev ? { ...prev, template_variables: next } : prev))
                }
                fieldOptions={mapperFieldOptionsRec}
                fieldSourceLabel={mapperSourceLabel}
              />
            )}
            {(draft?.template_source === "noah_oficial" || draft?.template_source === "noah") &&
              noahOfficialMapperVariableKeys.length > 0 && (
                <TemplateVariableMapper
                  variables={noahOfficialMapperVariableKeys}
                  mapping={draft.template_variables}
                  onChange={(next) =>
                    setDraft((prev) => (prev ? { ...prev, template_variables: next } : prev))
                  }
                  fieldOptions={mapperFieldOptionsRec}
                  fieldSourceLabel={mapperSourceLabel}
                />
              )}
            {draft?.template_source === "local" && Object.keys(draft.template_variables || {}).length > 0 && (
              <TemplateVariableMapper
                variables={Object.keys(draft.template_variables)}
                mapping={draft.template_variables}
                onChange={(next) =>
                  setDraft((prev) => (prev ? { ...prev, template_variables: next } : prev))
                }
                fieldOptions={mapperFieldOptionsRec}
                fieldSourceLabel={mapperSourceLabel}
              />
            )}
          </div>
        )}

        {footerDisabledReason ? (
          <Alert variant="destructive" className="text-xs">
            <AlertDescription>{footerDisabledReason}</AlertDescription>
          </Alert>
        ) : null}

        <DialogFooter className="gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={!!isExecuting}>
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={validateAndExecute}
            disabled={!!footerDisabledReason || !!isExecuting || !campaign}
          >
            {isExecuting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <Play className="h-4 w-4 mr-2" />}
            Gerar e enviar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
