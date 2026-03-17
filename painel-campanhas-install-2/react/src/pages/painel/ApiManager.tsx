import { useState, useEffect } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Key, Eye, EyeOff, Save, Plus, Trash2, Link2, Loader2, Server, Play, CheckCircle2, XCircle, RefreshCw } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { useToast } from "@/hooks/use-toast";
import {
  saveMasterApiKey,
  getMasterApiKey,
  getMicroserviceConfig,
  saveMicroserviceConfig,
  getStaticCredentials,
  saveStaticCredentials,
  getOtimaCustomers,
  listCredentials,
  createCredential,
  getCredential,
  updateCredential,
  deleteCredential,
  createCustomProvider,
  listCustomProviders,
  getCustomProvider,
  updateCustomProvider,
  deleteCustomProvider,
  runSalesforceImport,
  getRobbuWebhookStats,
} from "@/lib/api";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";

function RobbuWebhookCard() {
  const { toast } = useToast();
  const { data: stats, isLoading, refetch } = useQuery({
    queryKey: ['robbu-webhook-stats'],
    queryFn: getRobbuWebhookStats,
    refetchInterval: 30000,
  });

  const totalEvents = stats?.total_events ?? 0;
  const lastEventAt = stats?.last_event_at ?? null;
  const eventsByType = stats?.events_by_type ?? [];
  const recentEvents = stats?.recent_events ?? [];
  const totalLines = stats?.total_lines ?? 0;
  const lines = stats?.lines ?? [];

  return (
    <Card className="border-amber-300 bg-amber-50/40 dark:bg-amber-900/10">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-amber-700 dark:text-amber-400">
          <Server className="h-5 w-5" />
          Webhook Robbu / Invenio
        </CardTitle>
        <CardDescription>
          URL para cadastrar no Invenio Center e receber eventos em tempo real (status de mensagens, saúde das linhas WhatsApp).
          Acesse: Configurações → Webhook → Gerenciar
        </CardDescription>
        <p className="text-sm text-amber-700/90 dark:text-amber-400/90 mt-1">
          <strong>Credenciais para envio:</strong> role até a seção &quot;Credenciais Estáticas&quot; abaixo → preencha <strong>Robbu Oficial</strong> (Company, Username, Password e Token Privado Invenio).
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-2">
          <Label>URL do Webhook</Label>
          <div className="flex gap-2">
            <Input
              readOnly
              value={`${window.location.origin}/wp-json/robbu-webhook/v2/receive`}
              className="font-mono text-sm"
            />
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                navigator.clipboard.writeText(`${window.location.origin}/wp-json/robbu-webhook/v2/receive`);
                toast({ title: "URL copiada para a área de transferência!" });
              }}
            >
              Copiar
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Libere os IPs da Robbu no firewall se necessário. Após cadastrar, aguarde ~60 min para começar a receber eventos.
          </p>
        </div>

        {/* Monitoramento de eventos */}
        <div className="rounded-lg border border-amber-200 bg-white/60 dark:bg-slate-900/40 p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="font-semibold text-sm text-amber-800 dark:text-amber-300">Monitoramento</h4>
            <Button variant="ghost" size="sm" onClick={() => refetch()} disabled={isLoading}>
              <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
            </Button>
          </div>
          {isLoading ? (
            <Skeleton className="h-16 w-full" />
          ) : (
            <>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div>
                  <p className="text-muted-foreground">Total de eventos</p>
                  <p className="font-bold text-lg tabular-nums">{totalEvents.toLocaleString('pt-BR')}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Linhas cadastradas</p>
                  <p className="font-bold text-lg tabular-nums">{totalLines}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Último evento</p>
                  <p className="font-medium text-xs">
                    {lastEventAt ? new Date(lastEventAt).toLocaleString('pt-BR') : '—'}
                  </p>
                </div>
                <div>
                  <p className="text-muted-foreground">Por tipo</p>
                  <p className="font-medium text-xs">
                    {eventsByType.length > 0
                      ? eventsByType.map((e: any) => `${e.event_type}: ${e.cnt}`).join(', ')
                      : '—'}
                  </p>
                </div>
              </div>
              {recentEvents.length > 0 && (
                <div>
                  <p className="text-xs font-medium text-muted-foreground mb-1">Últimos 10 eventos</p>
                  <div className="max-h-24 overflow-y-auto space-y-0.5 text-xs font-mono">
                    {recentEvents.map((e: any) => (
                      <div key={e.id} className="flex gap-2">
                        <span className="text-amber-600 dark:text-amber-500">{e.event_type}</span>
                        <span className="text-muted-foreground">{e.created_at}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              {lines.length > 0 && (
                <div>
                  <p className="text-xs font-medium text-muted-foreground mb-1">Linhas WhatsApp (últimas 20)</p>
                  <div className="max-h-28 overflow-y-auto text-xs">
                    <table className="w-full">
                      <thead>
                        <tr className="border-b">
                          <th className="text-left py-1">Número</th>
                          <th className="text-left py-1">Status</th>
                          <th className="text-left py-1">Limite/dia</th>
                          <th className="text-left py-1">Atualizado</th>
                        </tr>
                      </thead>
                      <tbody>
                        {lines.map((l: any) => (
                          <tr key={l.robbu_line_id} className="border-b border-amber-100">
                            <td className="py-1">+55 {l.area_code || ''}{l.phone_number || l.robbu_line_id}</td>
                            <td className="py-1">
                              <Badge variant={l.status === 'GREEN' ? 'default' : l.status === 'YELLOW' ? 'secondary' : 'destructive'}>
                                {l.status || '—'}
                              </Badge>
                            </td>
                            <td className="py-1">{l.broadcast_limit_per_day ?? '—'}</td>
                            <td className="py-1 text-muted-foreground">{l.updated_at ? new Date(l.updated_at).toLocaleString('pt-BR') : '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
              {totalEvents === 0 && totalLines === 0 && (
                <p className="text-sm text-muted-foreground italic">
                  Nenhum evento recebido ainda. Cadastre a URL no Invenio Center e aguarde.
                </p>
              )}
            </>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

const PROVIDERS = [
  { value: 'gosac', label: 'GOSAC' },
  { value: 'gosac_oficial', label: 'Gosac Oficial' },
  { value: 'noah', label: 'Noah' },
  { value: 'noah_oficial', label: 'Noah Oficial' },
  { value: 'salesforce', label: 'Salesforce' },
];

export default function ApiManager() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [visibleKeys, setVisibleKeys] = useState<string[]>([]);
  const [masterKey, setMasterKey] = useState("");
  const [microserviceConfig, setMicroserviceConfig] = useState({
    url: "",
    api_key: "",
  });
  const [staticCreds, setStaticCreds] = useState({
    cda_api_url: "",
    cda_api_key: "",
    sf_client_id: "",
    sf_client_secret: "",
    sf_username: "",
    sf_password: "",
    sf_token_url: "",
    sf_api_url: "",
    mkc_client_id: "",
    mkc_client_secret: "",
    mkc_token_url: "",
    mkc_api_url: "",
    mkc_account_id: "",
    mkc_de_key: "",
    rcs_chave_api: "",
    rcs_base_url: "",
    rcs_token: "",
    otima_wpp_token: "",
    otima_wpp_customer_code: "",
    otima_wpp_broker_code: "",
    otima_rcs_token: "",
    otima_rcs_customer_code: "",
    gosac_oficial_token: "",
    gosac_oficial_url: "",
    robbu_company: "",
    robbu_username: "",
    robbu_password: "",
    robbu_invenio_token: "",
    dashboard_password: "",
  });
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [dynamicCredential, setDynamicCredential] = useState({
    provider: "",
    env_id: "",
    url: "",
    token: "",
    channel_ids: "",
    company: "",
    username: "",
    password: "",
    invenio_private_token: "",
    operacao: "",
    automation_id: "",
    chave_api: "",
  });

  // Buscar configuração do microserviço
  const { data: microConfigData, isLoading: microLoading } = useQuery({
    queryKey: ['microservice-config'],
    queryFn: getMicroserviceConfig,
  });

  // Buscar master api key
  const { data: masterKeyData } = useQuery({
    queryKey: ['master-api-key'],
    queryFn: getMasterApiKey,
  });

  // Buscar credenciais estáticas
  const { data: staticCredsData, isLoading: staticCredsLoading } = useQuery({
    queryKey: ['static-credentials'],
    queryFn: getStaticCredentials,
  });

  useEffect(() => {
    if (microConfigData) {
      setMicroserviceConfig({
        url: microConfigData.url || "",
        api_key: microConfigData.api_key || "",
      });
    }
  }, [microConfigData]);

  useEffect(() => {
    if (masterKeyData && masterKeyData.master_api_key !== undefined) {
      setMasterKey(masterKeyData.master_api_key);
    }
  }, [masterKeyData]);

  useEffect(() => {
    if (staticCredsData) {
      console.log('🔵 [ApiManager] Carregando credenciais do backend:', staticCredsData);
      const loadedCreds = {
        cda_api_url: staticCredsData.cda_api_url || "",
        cda_api_key: staticCredsData.cda_api_key || "",
        sf_client_id: staticCredsData.sf_client_id || "",
        sf_client_secret: staticCredsData.sf_client_secret || "",
        sf_username: staticCredsData.sf_username || "",
        sf_password: staticCredsData.sf_password || "",
        sf_token_url: staticCredsData.sf_token_url || "",
        sf_api_url: staticCredsData.sf_api_url || "",
        mkc_client_id: staticCredsData.mkc_client_id || "",
        mkc_client_secret: staticCredsData.mkc_client_secret || "",
        mkc_token_url: staticCredsData.mkc_token_url || "",
        mkc_api_url: staticCredsData.mkc_api_url || "",
        mkc_account_id: staticCredsData.mkc_account_id || "",
        mkc_de_key: staticCredsData.mkc_de_key || "",
        rcs_chave_api: staticCredsData.rcs_chave_api || staticCredsData.rcs_token || staticCredsData.cda_api_key || "",
        rcs_base_url: staticCredsData.rcs_base_url || "https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI",
        rcs_token: staticCredsData.rcs_token || "",
        otima_wpp_token: staticCredsData.otima_wpp_token || "",
        otima_wpp_customer_code: staticCredsData.otima_wpp_customer_code || "",
        otima_wpp_broker_code: staticCredsData.otima_wpp_broker_code || "",
        otima_rcs_token: staticCredsData.otima_rcs_token || "",
        otima_rcs_customer_code: staticCredsData.otima_rcs_customer_code || "",
        gosac_oficial_token: staticCredsData.gosac_oficial_token || "",
        gosac_oficial_url: staticCredsData.gosac_oficial_url || "",
        robbu_company: staticCredsData.robbu_company || "",
        robbu_username: staticCredsData.robbu_username || "",
        robbu_password: staticCredsData.robbu_password || "",
        robbu_invenio_token: staticCredsData.robbu_invenio_token || "",
        dashboard_password: staticCredsData.dashboard_password || "",
      };
      console.log('🔵 [ApiManager] Credenciais carregadas no estado:', Object.entries(loadedCreds).filter(([_, v]) => v && v.trim()).map(([k, v]) => `${k}: ${v.substring(0, 10)}...`));
      setStaticCreds(loadedCreds);
    }
  }, [staticCredsData]);

  const masterKeyMutation = useMutation({
    mutationFn: (key: string) => saveMasterApiKey(key),
    onSuccess: () => {
      toast({ title: "Master API Key salva com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['master-api-key'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar Master API Key",
        variant: "destructive",
      });
    },
  });

  const microserviceMutation = useMutation({
    mutationFn: (data: any) => saveMicroserviceConfig(data),
    onSuccess: () => {
      toast({ title: "Configuração do microserviço salva com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['microservice-config'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar configuração",
        variant: "destructive",
      });
    },
  });

  const staticCredsMutation = useMutation({
    mutationFn: (data: any) => {
      console.log('🔵 [ApiManager] Enviando para API:', data);
      return saveStaticCredentials({ static_credentials: data });
    },
    onSuccess: (response) => {
      console.log('✅ [ApiManager] Credenciais salvas com sucesso:', response);
      toast({ title: "Credenciais estáticas salvas com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['static-credentials'] });
      // Invalida templates para recarregar templates da Ótima se customer_code foi atualizado
      queryClient.invalidateQueries({ queryKey: ['messages'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao salvar",
        description: error.message || "Erro ao salvar credenciais",
        variant: "destructive",
      });
    },
  });

  const [sfImportResult, setSfImportResult] = useState<{ rows_inserted: number; pages_processed: number; errors: string[] } | null>(null);

  const sfImportMutation = useMutation({
    mutationFn: runSalesforceImport,
    onSuccess: (data: any) => {
      console.log('🔵 [ApiManager] Salesforce Import Data received:', data);
      setSfImportResult(data);
      if (data?.errors?.length > 0) {
        toast({
          title: `Importação concluída com avisos`,
          description: `${data.rows_inserted ?? 0} registros inseridos/atualizados em ${data.pages_processed ?? 0} páginas. ${data.errors.length} erro(s).`,
          variant: "destructive",
        });
      } else {
        toast({
          title: "Importação Salesforce concluída!",
          description: `${data.rows_inserted ?? 0} registros inseridos/atualizados em ${data.pages_processed ?? 0} página(s).`,
        });
      }
    },
    onError: (error: any) => {
      const msg = error?.message ?? (typeof error === 'string' ? error : 'Erro desconhecido');
      console.error('🔴 [ApiManager] Error in Salesforce Import:', msg, error);
      setSfImportResult(null);
      toast({
        title: "Erro na importação Salesforce",
        description: msg,
        variant: "destructive",
      });
    },
  });


  const toggleKeyVisibility = (id: string) => {
    setVisibleKeys((prev) =>
      prev.includes(id) ? prev.filter((k) => k !== id) : [...prev, id]
    );
  };

  const handleSaveMasterKey = () => {
    if (!masterKey.trim()) {
      toast({
        title: "Campo obrigatório",
        description: "Por favor, informe a Master API Key",
        variant: "destructive",
      });
      return;
    }
    masterKeyMutation.mutate(masterKey);
  };

  const handleSaveMicroservice = () => {
    if (!microserviceConfig.url.trim() || !microserviceConfig.api_key.trim()) {
      toast({
        title: "Campos obrigatórios",
        description: "URL e API Key são obrigatórios",
        variant: "destructive",
      });
      return;
    }
    microserviceMutation.mutate({
      microservice_url: microserviceConfig.url,
      microservice_api_key: microserviceConfig.api_key,
    });
  };

  const handleSaveStaticCreds = () => {
    console.log('🔵 [ApiManager] Salvando credenciais estáticas:', staticCreds);
    console.log('🔵 [ApiManager] Campos preenchidos:', Object.entries(staticCreds).filter(([_, v]) => v && v.trim()).map(([k]) => k));
    staticCredsMutation.mutate(staticCreds);
  };

  const handleCreateDynamicCredential = () => {
    if (!dynamicCredential.provider || !dynamicCredential.env_id) {
      toast({
        title: "Campos obrigatórios",
        description: "Provider e Environment ID são obrigatórios",
        variant: "destructive",
      });
      return;
    }

    const credentialData: any = {};

    if (['gosac', 'gosac_oficial', 'noah', 'noah_oficial'].includes(dynamicCredential.provider)) {
      if (!dynamicCredential.url || !dynamicCredential.token) {
        toast({
          title: "Campos obrigatórios",
          description: "URL e Token são obrigatórios para este provider",
          variant: "destructive",
        });
        return;
      }
      credentialData.url = dynamicCredential.url;
      credentialData.token = dynamicCredential.token;
      if (dynamicCredential.provider === 'noah_oficial' && dynamicCredential.channel_ids?.trim()) {
        credentialData.channel_ids = dynamicCredential.channel_ids
          .split(/[,;\s]+/)
          .map((s) => s.trim())
          .filter(Boolean)
          .map((s) => (/^\d+$/.test(s) ? parseInt(s, 10) : s));
      }
    } else if (dynamicCredential.provider === 'salesforce') {
      if (!dynamicCredential.operacao || !dynamicCredential.automation_id) {
        toast({
          title: "Campos obrigatórios",
          description: "Operação e Automation ID são obrigatórios para Salesforce",
          variant: "destructive",
        });
        return;
      }
      credentialData.operacao = dynamicCredential.operacao;
      credentialData.automation_id = dynamicCredential.automation_id;
    } else if (dynamicCredential.provider === 'rcs') {
      if (!dynamicCredential.chave_api) {
        toast({
          title: "Campos obrigatórios",
          description: "Chave API é obrigatória para RCS",
          variant: "destructive",
        });
        return;
      }
      credentialData.chave_api = dynamicCredential.chave_api;
    }

    createCredential({
      provider: dynamicCredential.provider,
      env_id: dynamicCredential.env_id,
      credential_data: credentialData,
    })
      .then(() => {
        toast({ title: "Credencial criada com sucesso!" });
        queryClient.invalidateQueries({ queryKey: ['dynamic-credentials'] });
        setShowCreateDialog(false);
        setDynamicCredential({
          provider: "",
          env_id: "",
          url: "",
          token: "",
          channel_ids: "",
          company: "",
          username: "",
          password: "",
          invenio_private_token: "",
          operacao: "",
          automation_id: "",
          chave_api: "",
        });
      })
      .catch((error: any) => {
        toast({
          title: "Erro ao criar credencial",
          description: error.message || "Erro desconhecido",
          variant: "destructive",
        });
      });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="API Manager"
        description="Gerencie chaves de API e integrações"
      />

      {/* ── Salesforce Manual Import ───────────────────────────────────────── */}
      <Card className="border-emerald-300 bg-emerald-50/40 dark:bg-emerald-900/10">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
            <Server className="h-5 w-5" />
            Importação Manual Salesforce
          </CardTitle>
          <CardDescription>
            Executa o mesmo processo do cron diário (11h00 São Paulo) para ingerir dados do Salesforce Marketing Cloud na tabela <code>salesforce_returns</code>.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-4">
            <Button
              onClick={() => { setSfImportResult(null); sfImportMutation.mutate(); }}
              disabled={sfImportMutation.isPending}
              className="bg-emerald-600 hover:bg-emerald-700 text-white gap-2"
            >
              {sfImportMutation.isPending ? (
                <><Loader2 className="h-4 w-4 animate-spin" /> Importando...</>
              ) : (
                <><Play className="h-4 w-4" /> Executar Importação Agora</>
              )}
            </Button>
            {sfImportMutation.isPending && (
              <span className="text-sm text-muted-foreground animate-pulse">
                Buscando dados no Salesforce e gravando no banco... Aguarde (pode levar 1-2 minutos).
              </span>
            )}
          </div>

          {sfImportResult && (
            <div className={`rounded-lg border p-4 space-y-2 text-sm ${sfImportResult.errors.length > 0 ? 'border-amber-300 bg-amber-50 dark:bg-amber-900/20' : 'border-emerald-300 bg-emerald-50 dark:bg-emerald-900/20'}`}>
              <div className="flex items-center gap-2 font-semibold">
                {sfImportResult.errors.length > 0
                  ? <XCircle className="h-4 w-4 text-amber-600" />
                  : <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                }
                Resultado da Última Importação
              </div>
              <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                <span className="text-muted-foreground">Registros inseridos/atualizados:</span>
                <span className="font-bold tabular-nums">{sfImportResult.rows_inserted.toLocaleString('pt-BR')}</span>
                <span className="text-muted-foreground">Páginas processadas:</span>
                <span className="font-bold tabular-nums">{sfImportResult.pages_processed}</span>
              </div>
              {sfImportResult.errors.length > 0 && (
                <div className="space-y-1">
                  <p className="text-xs font-semibold text-amber-700">Erros:</p>
                  {sfImportResult.errors.map((e, i) => (
                    <p key={i} className="text-xs text-amber-800 font-mono bg-amber-100 dark:bg-amber-900/40 rounded px-2 py-1">{e}</p>
                  ))}
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* ── Robbu Webhook ───────────────────────────────────────────────────── */}
      <RobbuWebhookCard />

      {/* Master API Key */}
      <Card className="border-primary/30 bg-primary/5">

        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Key className="h-5 w-5 text-primary" />
            Master API Key
          </CardTitle>
          <CardDescription>Chave principal para autenticação do sistema</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label>API Key</Label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Input
                  type={visibleKeys.includes("master") ? "text" : "password"}
                  value={masterKey}
                  onChange={(e) => setMasterKey(e.target.value)}
                  placeholder="sk_live_..."
                  className="pr-10"
                />
                <button
                  type="button"
                  onClick={() => toggleKeyVisibility("master")}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {visibleKeys.includes("master") ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              <Button
                onClick={handleSaveMasterKey}
                disabled={masterKeyMutation.isPending}
              >
                {masterKeyMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Save className="mr-2 h-4 w-4" />
                )}
                Salvar
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Microservice Config */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Server className="h-5 w-5 text-primary" />
            Configuração do Microserviço
          </CardTitle>
          <CardDescription>URL e API Key do microserviço de disparos</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {microLoading ? (
            <Skeleton className="h-20" />
          ) : (
            <>
              <div className="space-y-2">
                <Label>URL do Microserviço</Label>
                <Input
                  value={microserviceConfig.url}
                  onChange={(e) =>
                    setMicroserviceConfig({ ...microserviceConfig, url: e.target.value })
                  }
                  placeholder="https://api.exemplo.com"
                />
              </div>
              <div className="space-y-2">
                <Label>API Key</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("microservice") ? "text" : "password"}
                    value={microserviceConfig.api_key}
                    onChange={(e) =>
                      setMicroserviceConfig({ ...microserviceConfig, api_key: e.target.value })
                    }
                    placeholder="sua-api-key"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("microservice")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("microservice") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
              <Button
                onClick={handleSaveMicroservice}
                disabled={microserviceMutation.isPending}
              >
                {microserviceMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Save className="mr-2 h-4 w-4" />
                )}
                Salvar Configuração
              </Button>
            </>
          )}
        </CardContent>
      </Card>

      {/* Static Credentials */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Link2 className="h-5 w-5 text-primary" />
            Static Provider Credentials
          </CardTitle>
          <CardDescription>Credenciais estáticas para providers específicos</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* CDA */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">CDA Provider</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>CDA API URL</Label>
                <Input
                  value={staticCreds.cda_api_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, cda_api_url: e.target.value })
                  }
                  placeholder="https://api.cda.com"
                />
              </div>
              <div className="space-y-2">
                <Label>CDA API Key</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("cda") ? "text" : "password"}
                    value={staticCreds.cda_api_key}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, cda_api_key: e.target.value })
                    }
                    placeholder="API Key"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("cda")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("cda") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* RCS CDA (CromosApp) */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">RCS CDA (CromosApp)</h4>
            <p className="text-sm text-muted-foreground">
              Usa a mesma chave do CDA WPP. Deixe vazio para herdar automaticamente a Chave API do CDA acima.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Chave API</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("rcs_chave") ? "text" : "password"}
                    value={staticCreds.rcs_chave_api}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, rcs_chave_api: e.target.value })
                    }
                    placeholder="Deixe vazio para usar a chave do CDA WPP"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("rcs_chave")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("rcs_chave") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Mesma chave do CDA WPP. Se vazio, o sistema usa automaticamente a Chave API do CDA acima.
                </p>
              </div>
              <div className="space-y-2">
                <Label>Base URL (RCS)</Label>
                <Input
                  value={staticCreds.rcs_base_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, rcs_base_url: e.target.value })
                  }
                  placeholder="https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI"
                />
                <p className="text-xs text-muted-foreground">
                  URL da API CromosApp para RCS. Diferente da URL do CDA WPP.
                </p>
              </div>
            </div>
          </div>

          {/* Salesforce */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Salesforce</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Client ID</Label>
                <Input
                  value={staticCreds.sf_client_id}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, sf_client_id: e.target.value })
                  }
                />
              </div>
              <div className="space-y-2">
                <Label>Client Secret</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("sf_secret") ? "text" : "password"}
                    value={staticCreds.sf_client_secret}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, sf_client_secret: e.target.value })
                    }
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("sf_secret")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("sf_secret") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Username</Label>
                <Input
                  value={staticCreds.sf_username}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, sf_username: e.target.value })
                  }
                />
              </div>
              <div className="space-y-2">
                <Label>Password</Label>
                <Input
                  type={visibleKeys.includes("sf_password") ? "text" : "password"}
                  value={staticCreds.sf_password}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, sf_password: e.target.value })
                  }
                  className="pr-10"
                />
              </div>
              <div className="space-y-2">
                <Label>Token URL</Label>
                <Input
                  value={staticCreds.sf_token_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, sf_token_url: e.target.value })
                  }
                  placeholder="https://login.salesforce.com/services/oauth2/token"
                />
              </div>
              <div className="space-y-2">
                <Label>API URL</Label>
                <Input
                  value={staticCreds.sf_api_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, sf_api_url: e.target.value })
                  }
                  placeholder="https://instance.salesforce.com"
                />
              </div>
            </div>
          </div>

          {/* Marketing Cloud (importação Salesforce) */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Marketing Cloud (Importação)</h4>
            <p className="text-sm text-muted-foreground">
              Usado pela importação manual do Salesforce. Token URL e API URL são usados para obter o token OAuth2 e consultar a Data Extension.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Client ID</Label>
                <Input
                  value={staticCreds.mkc_client_id}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, mkc_client_id: e.target.value })
                  }
                  placeholder="Client ID Marketing Cloud"
                />
              </div>
              <div className="space-y-2">
                <Label>Client Secret</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("mkc_secret") ? "text" : "password"}
                    value={staticCreds.mkc_client_secret}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, mkc_client_secret: e.target.value })
                    }
                    placeholder="Client Secret"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("mkc_secret")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("mkc_secret") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Token URL</Label>
                <Input
                  value={staticCreds.mkc_token_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, mkc_token_url: e.target.value })
                  }
                  placeholder="https://xxx.auth.marketingcloudapis.com/v2/token"
                />
              </div>
              <div className="space-y-2">
                <Label>API Base URL</Label>
                <Input
                  value={staticCreds.mkc_api_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, mkc_api_url: e.target.value })
                  }
                  placeholder="https://xxx.rest.marketingcloudapis.com"
                />
              </div>
              <div className="space-y-2">
                <Label>Account ID</Label>
                <Input
                  value={staticCreds.mkc_account_id}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, mkc_account_id: e.target.value })
                  }
                  placeholder="ID da conta Marketing Cloud (obrigatório)"
                />
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label>Data Extension Key (Customer Key)</Label>
                <Input
                  value={staticCreds.mkc_de_key}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, mkc_de_key: e.target.value })
                  }
                  placeholder="Tracking_WhatsApp_Importado_FINAL"
                />
                <p className="text-xs text-muted-foreground">
                  Customer Key da Data Extension no Marketing Cloud. O nome exato da DE no painel. Em 404, verifique se a chave existe na sua instância.
                </p>
              </div>
            </div>
          </div>

          {/* Ótima WhatsApp */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Ótima WhatsApp</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Token de Autenticação</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("otima_wpp_token") ? "text" : "password"}
                    value={staticCreds.otima_wpp_token}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, otima_wpp_token: e.target.value })
                    }
                    placeholder="Token de autenticação"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("otima_wpp_token")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("otima_wpp_token") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Token estático para autenticação na API Ótima WhatsApp
                </p>
              </div>
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>Customer Code</Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={async () => {
                      if (!staticCreds.otima_wpp_token) {
                        toast({
                          title: "Token necessário",
                          description: "Configure o token primeiro para buscar customer codes",
                          variant: "destructive",
                        });
                        return;
                      }
                      try {
                        const customers = await getOtimaCustomers('wpp');
                        if (Array.isArray(customers) && customers.length > 0) {
                          // Se houver apenas um, seleciona automaticamente
                          if (customers.length === 1) {
                            setStaticCreds({ ...staticCreds, otima_wpp_customer_code: customers[0] });
                            toast({ title: "Customer code carregado!" });
                          } else {
                            // Se houver múltiplos, mostra um select
                            const selected = prompt(
                              `Customer codes disponíveis:\n${customers.join('\n')}\n\nDigite o código desejado:`
                            );
                            if (selected && customers.includes(selected)) {
                              setStaticCreds({ ...staticCreds, otima_wpp_customer_code: selected });
                              toast({ title: "Customer code selecionado!" });
                            }
                          }
                        } else {
                          toast({
                            title: "Nenhum customer code encontrado",
                            description: "Verifique se o token está correto",
                            variant: "destructive",
                          });
                        }
                      } catch (error: any) {
                        toast({
                          title: "Erro ao buscar customer codes",
                          description: error.message || "Erro desconhecido",
                          variant: "destructive",
                        });
                      }
                    }}
                  >
                    <Link2 className="mr-2 h-4 w-4" />
                    Buscar
                  </Button>
                </div>
                <Input
                  value={staticCreds.otima_wpp_customer_code}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, otima_wpp_customer_code: e.target.value })
                  }
                  placeholder="Código do cliente"
                />
                <p className="text-xs text-muted-foreground">
                  Código do cliente para buscar templates HSM. Use o botão "Buscar" para listar os customer codes disponíveis da API da Ótima. Após salvar, os templates serão carregados automaticamente.
                </p>
              </div>
            </div>
          </div>

          {/* Ótima RCS */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Ótima RCS</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Token de Autenticação</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("otima_rcs_token") ? "text" : "password"}
                    value={staticCreds.otima_rcs_token}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, otima_rcs_token: e.target.value })
                    }
                    placeholder="Token de autenticação"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("otima_rcs_token")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("otima_rcs_token") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Token estático para autenticação na API Ótima RCS
                </p>
              </div>
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>Customer Code</Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={async () => {
                      if (!staticCreds.otima_rcs_token) {
                        toast({
                          title: "Token necessário",
                          description: "Configure o token primeiro para buscar customer codes",
                          variant: "destructive",
                        });
                        return;
                      }
                      try {
                        const customers = await getOtimaCustomers('rcs');
                        if (Array.isArray(customers) && customers.length > 0) {
                          // Se houver apenas um, seleciona automaticamente
                          if (customers.length === 1) {
                            setStaticCreds({ ...staticCreds, otima_rcs_customer_code: customers[0] });
                            toast({ title: "Customer code carregado!" });
                          } else {
                            // Se houver múltiplos, mostra um select
                            const selected = prompt(
                              `Customer codes disponíveis:\n${customers.join('\n')}\n\nDigite o código desejado:`
                            );
                            if (selected && customers.includes(selected)) {
                              setStaticCreds({ ...staticCreds, otima_rcs_customer_code: selected });
                              toast({ title: "Customer code selecionado!" });
                            }
                          }
                        } else {
                          toast({
                            title: "Nenhum customer code encontrado",
                            description: "Verifique se o token está correto",
                            variant: "destructive",
                          });
                        }
                      } catch (error: any) {
                        toast({
                          title: "Erro ao buscar customer codes",
                          description: error.message || "Erro desconhecido",
                          variant: "destructive",
                        });
                      }
                    }}
                  >
                    <Link2 className="mr-2 h-4 w-4" />
                    Buscar
                  </Button>
                </div>
                <Input
                  value={staticCreds.otima_rcs_customer_code}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, otima_rcs_customer_code: e.target.value })
                  }
                  placeholder="Código do cliente"
                />
                <p className="text-xs text-muted-foreground">
                  Código do cliente para buscar templates RCS. Use o botão "Buscar" para listar os customer codes disponíveis da API da Ótima. Após salvar, os templates serão carregados automaticamente.
                </p>
              </div>
            </div>
          </div>
          {/* Gosac Oficial */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Gosac Oficial</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>API Token</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("gosac_oficial_token") ? "text" : "password"}
                    value={staticCreds.gosac_oficial_token}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, gosac_oficial_token: e.target.value })
                    }
                    placeholder="Token de autenticação"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("gosac_oficial_token")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("gosac_oficial_token") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
              <div className="space-y-2">
                <Label>API URL</Label>
                <Input
                  value={staticCreds.gosac_oficial_url}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, gosac_oficial_url: e.target.value })
                  }
                  placeholder="https://api.gosac.com.br"
                />
              </div>
            </div>
          </div>

          {/* Robbu Oficial */}
          <div className="border-b pb-4 space-y-4">
            <h4 className="font-semibold">Robbu Oficial</h4>
            <p className="text-sm text-muted-foreground">
              Ambiente (Company), Login (Username) e Senha (Password) para autenticação na API Robbu. Token Privado Invenio para templates e envio.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Company (Ambiente)</Label>
                <Input
                  value={staticCreds.robbu_company}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, robbu_company: e.target.value })
                  }
                  placeholder="Nome da empresa no Robbu"
                />
              </div>
              <div className="space-y-2">
                <Label>Username (Login)</Label>
                <Input
                  value={staticCreds.robbu_username}
                  onChange={(e) =>
                    setStaticCreds({ ...staticCreds, robbu_username: e.target.value })
                  }
                  placeholder="Usuário de login"
                />
              </div>
              <div className="space-y-2">
                <Label>Password (Senha)</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("robbu_password") ? "text" : "password"}
                    value={staticCreds.robbu_password}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, robbu_password: e.target.value })
                    }
                    placeholder="Senha de login"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("robbu_password")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("robbu_password") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Token Privado Invenio</Label>
                <div className="relative">
                  <Input
                    type={visibleKeys.includes("robbu_token") ? "text" : "password"}
                    value={staticCreds.robbu_invenio_token}
                    onChange={(e) =>
                      setStaticCreds({ ...staticCreds, robbu_invenio_token: e.target.value })
                    }
                    placeholder="Token da página Configurações > Conta"
                    className="pr-10"
                  />
                  <button
                    type="button"
                    onClick={() => toggleKeyVisibility("robbu_token")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    {visibleKeys.includes("robbu_token") ? (
                      <EyeOff className="h-4 w-4" />
                    ) : (
                      <Eye className="h-4 w-4" />
                    )}
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Obtido em inveniocenter.robbu.global/painel/configuracoes/conta
                </p>
              </div>
            </div>
          </div>

          <Button
            onClick={handleSaveStaticCreds}
            disabled={staticCredsMutation.isPending}
            className="w-full gradient-primary hover:opacity-90"
          >
            {staticCredsMutation.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            Salvar Credenciais Estáticas
          </Button>
        </CardContent>
      </Card>

      {/* Dynamic Credentials */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Key className="h-5 w-5 text-primary" />
              Credenciais Dinâmicas
            </div>
            <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
              <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                  <Plus className="mr-2 h-4 w-4" />
                  Nova Credencial
                </Button>
              </DialogTrigger>
              <DialogContent className="max-w-2xl">
                <DialogHeader>
                  <DialogTitle>Criar Nova Credencial</DialogTitle>
                  <DialogDescription>
                    Configure credenciais específicas por provider e ambiente
                  </DialogDescription>
                </DialogHeader>
                <div className="space-y-4 py-4">
                  <div className="space-y-2">
                    <Label>Provider *</Label>
                    <Select
                      value={dynamicCredential.provider}
                      onValueChange={(value) =>
                        setDynamicCredential({ ...dynamicCredential, provider: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Selecione um provider" />
                      </SelectTrigger>
                      <SelectContent>
                        {PROVIDERS.map((provider) => (
                          <SelectItem key={provider.value} value={provider.value}>
                            {provider.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Environment ID *</Label>
                    <Input
                      value={dynamicCredential.env_id}
                      onChange={(e) =>
                        setDynamicCredential({ ...dynamicCredential, env_id: e.target.value })
                      }
                      placeholder="Ex: 3641"
                    />
                    <p className="text-xs text-muted-foreground">
                      O valor idgis_ambiente usado nas campanhas
                    </p>
                  </div>

                  {/* Campos para URL/Token (GOSAC, Noah, GOSAC Oficial, NOAH Oficial) */}
                  {['gosac', 'noah', 'gosac_oficial', 'noah_oficial'].includes(dynamicCredential.provider) && (
                    <>
                      <div className="space-y-2">
                        <Label>API URL *</Label>
                        <Input
                          value={dynamicCredential.url}
                          onChange={(e) =>
                            setDynamicCredential({ ...dynamicCredential, url: e.target.value })
                          }
                          placeholder="https://provider.api.com/endpoint"
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>Token/Key *</Label>
                        <div className="relative">
                          <Input
                            type={visibleKeys.includes("dynamic_token") ? "text" : "password"}
                            value={dynamicCredential.token}
                            onChange={(e) =>
                              setDynamicCredential({ ...dynamicCredential, token: e.target.value })
                            }
                            className="pr-10"
                          />
                          <button
                            type="button"
                            onClick={() => toggleKeyVisibility("dynamic_token")}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                          >
                            {visibleKeys.includes("dynamic_token") ? (
                              <EyeOff className="h-4 w-4" />
                            ) : (
                              <Eye className="h-4 w-4" />
                            )}
                          </button>
                        </div>
                      </div>
                      {dynamicCredential.provider === 'noah_oficial' && (
                        <div className="space-y-2">
                          <Label>Channel IDs (opcional)</Label>
                          <Input
                            value={dynamicCredential.channel_ids}
                            onChange={(e) =>
                              setDynamicCredential({ ...dynamicCredential, channel_ids: e.target.value })
                            }
                            placeholder="Ex: 5 ou 5, 6, 7 (se /channels não existir)"
                          />
                          <p className="text-xs text-muted-foreground">
                            IDs dos canais separados por vírgula. Use se a API não tiver endpoint /channels.
                          </p>
                        </div>
                      )}
                    </>
                  )}

                  {/* Campos para Salesforce */}
                  {dynamicCredential.provider === 'salesforce' && (
                    <>
                      <div className="space-y-2">
                        <Label>Operação Name *</Label>
                        <Input
                          value={dynamicCredential.operacao}
                          onChange={(e) =>
                            setDynamicCredential({ ...dynamicCredential, operacao: e.target.value })
                          }
                          placeholder="BV_VEIC_ADM_Tradicional"
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>Automation ID *</Label>
                        <Input
                          value={dynamicCredential.automation_id}
                          onChange={(e) =>
                            setDynamicCredential({
                              ...dynamicCredential,
                              automation_id: e.target.value,
                            })
                          }
                          placeholder="0e309929-51ae-4e2a-b8d1-ee17c055f42e"
                        />
                      </div>
                    </>
                  )}

                </div>
                <DialogFooter>
                  <Button
                    variant="outline"
                    onClick={() => setShowCreateDialog(false)}
                  >
                    Cancelar
                  </Button>
                  <Button onClick={handleCreateDynamicCredential}>
                    Criar Credencial
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </CardTitle>
          <CardDescription>
            Credenciais configuráveis por provider e ambiente ID
          </CardDescription>
        </CardHeader>
        <CardContent>
          <DynamicCredentialsList />
        </CardContent>
      </Card>

      {/* Custom Providers */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Server className="h-5 w-5 text-primary" />
              Providers Customizados
            </div>
            <CustomProviderDialog
              onSuccess={() => {
                queryClient.invalidateQueries({ queryKey: ['custom-providers'] });
              }}
            />
          </CardTitle>
          <CardDescription>
            Crie providers personalizados com mapeamento de campos customizado
          </CardDescription>
        </CardHeader>
        <CardContent>
          <CustomProvidersList />
        </CardContent>
      </Card>
    </div>
  );
}

// Componente para lista de credenciais dinâmicas
function DynamicCredentialsList() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const { data: credentials, isLoading } = useQuery({
    queryKey: ['dynamic-credentials'],
    queryFn: listCredentials,
  });

  const deleteMutation = useMutation({
    mutationFn: ({ provider, envId }: { provider: string; envId: string }) =>
      deleteCredential(provider, envId),
    onSuccess: () => {
      toast({ title: "Credencial deletada com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['dynamic-credentials'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao deletar",
        description: error.message || "Erro ao deletar credencial",
        variant: "destructive",
      });
    },
  });

  if (isLoading) {
    return <Skeleton className="h-20" />;
  }

  if (!credentials || credentials.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Nenhuma credencial dinâmica criada. Use o botão "Nova Credencial" acima para criar uma.
      </p>
    );
  }

  const getProviderLabel = (provider: string) => {
    const found = PROVIDERS.find((p) => p.value === provider);
    return found ? found.label : provider.toUpperCase();
  };

  return (
    <div className="space-y-4">
      {credentials.map((cred: any, index: number) => (
        <div
          key={`${cred.provider}-${cred.env_id}-${index}`}
          className="flex items-center justify-between p-4 border rounded-lg"
        >
          <div className="flex-1">
            <div className="flex items-center gap-2 mb-1">
              <h4 className="font-semibold">{getProviderLabel(cred.provider)}</h4>
              <Badge variant="secondary">Env ID: {cred.env_id}</Badge>
            </div>
            <div className="text-sm text-muted-foreground space-y-1">
              {cred.data?.url && (
                <p>
                  <span className="font-medium">URL:</span> {cred.data.url}
                </p>
              )}
              {cred.data?.token && (
                <p>
                  <span className="font-medium">Token:</span> ••••••••
                </p>
              )}
              {cred.data?.chave_api && (
                <p>
                  <span className="font-medium">Chave API:</span> ••••••••
                </p>
              )}
              {cred.data?.operacao && (
                <p>
                  <span className="font-medium">Operação:</span> {cred.data.operacao}
                </p>
              )}
              {cred.data?.automation_id && (
                <p>
                  <span className="font-medium">Automation ID:</span> {cred.data.automation_id}
                </p>
              )}
              {cred.data?.company && (
                <p>
                  <span className="font-medium">Company:</span> {cred.data.company}
                </p>
              )}
              {cred.data?.username && (
                <p>
                  <span className="font-medium">Username:</span> {cred.data.username}
                </p>
              )}
              {cred.data?.invenio_private_token && (
                <p>
                  <span className="font-medium">Token Privado:</span> ••••••••
                </p>
              )}
            </div>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              if (
                confirm(
                  `Tem certeza que deseja deletar a credencial do provider "${getProviderLabel(cred.provider)}" com Environment ID "${cred.env_id}"?`
                )
              ) {
                deleteMutation.mutate({ provider: cred.provider, envId: cred.env_id });
              }
            }}
            disabled={deleteMutation.isPending}
          >
            {deleteMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Trash2 className="h-4 w-4 text-destructive" />
            )}
          </Button>
        </div>
      ))}
    </div>
  );
}

// Componente para lista de providers customizados
function CustomProvidersList() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const { data: providers, isLoading } = useQuery({
    queryKey: ['custom-providers'],
    queryFn: listCustomProviders,
  });

  const deleteMutation = useMutation({
    mutationFn: deleteCustomProvider,
    onSuccess: () => {
      toast({ title: "Provider deletado com sucesso!" });
      queryClient.invalidateQueries({ queryKey: ['custom-providers'] });
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao deletar",
        description: error.message || "Erro ao deletar provider",
        variant: "destructive",
      });
    },
  });

  if (isLoading) {
    return <Skeleton className="h-20" />;
  }

  if (!providers || providers.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Nenhum provider customizado criado. Clique em "Novo Provider" para criar um.
      </p>
    );
  }

  return (
    <div className="space-y-4">
      {providers.map((provider: any) => (
        <div
          key={provider.key}
          className="flex items-center justify-between p-4 border rounded-lg"
        >
          <div>
            <h4 className="font-semibold">{provider.name}</h4>
            <p className="text-sm text-muted-foreground">
              Chave: <code className="px-1 py-0.5 bg-muted rounded">{provider.key}</code>
            </p>
            {provider.requires_credentials && (
              <Badge variant="outline" className="mt-1">
                Requer Credenciais
              </Badge>
            )}
          </div>
          <div className="flex gap-2">
            <CustomProviderDialog
              providerKey={provider.key}
              onSuccess={() => {
                queryClient.invalidateQueries({ queryKey: ['custom-providers'] });
              }}
            />
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                if (confirm(`Tem certeza que deseja deletar o provider "${provider.name}"?`)) {
                  deleteMutation.mutate(provider.key);
                }
              }}
            >
              <Trash2 className="h-4 w-4 text-destructive" />
            </Button>
          </div>
        </div>
      ))}
    </div>
  );
}

// Componente para dialog de criar/editar provider customizado
function CustomProviderDialog({
  providerKey,
  onSuccess,
}: {
  providerKey?: string;
  onSuccess?: () => void;
}) {
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [formData, setFormData] = useState({
    provider_key: "",
    provider_name: "",
    json_template: "{}",
    requires_credentials: false,
    credential_fields: [] as string[],
  });

  const { data: existingProvider } = useQuery({
    queryKey: ['custom-provider', providerKey],
    queryFn: () => getCustomProvider(providerKey!),
    enabled: !!providerKey && open,
  });

  useEffect(() => {
    if (existingProvider && open) {
      setFormData({
        provider_key: providerKey || "",
        provider_name: existingProvider.name || "",
        json_template: JSON.stringify(existingProvider.json_template || {}, null, 2),
        requires_credentials: existingProvider.requires_credentials || false,
        credential_fields: existingProvider.credential_fields || [],
      });
    } else if (!providerKey && open) {
      setFormData({
        provider_key: "",
        provider_name: "",
        json_template: "{}",
        requires_credentials: false,
        credential_fields: [],
      });
    }
  }, [existingProvider, providerKey, open]);

  const createMutation = useMutation({
    mutationFn: createCustomProvider,
    onSuccess: () => {
      toast({ title: "Provider criado com sucesso!" });
      setOpen(false);
      onSuccess?.();
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao criar",
        description: error.message || "Erro ao criar provider",
        variant: "destructive",
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: any) => updateCustomProvider(providerKey!, data),
    onSuccess: () => {
      toast({ title: "Provider atualizado com sucesso!" });
      setOpen(false);
      onSuccess?.();
    },
    onError: (error: any) => {
      toast({
        title: "Erro ao atualizar",
        description: error.message || "Erro ao atualizar provider",
        variant: "destructive",
      });
    },
  });

  const handleSubmit = () => {
    try {
      const jsonTemplate = JSON.parse(formData.json_template);

      if (!formData.provider_key || !formData.provider_name) {
        toast({
          title: "Campos obrigatórios",
          description: "Chave e nome do provider são obrigatórios",
          variant: "destructive",
        });
        return;
      }

      const submitData = {
        provider_key: formData.provider_key,
        provider_name: formData.provider_name,
        json_template: jsonTemplate,
        requires_credentials: formData.requires_credentials,
        credential_fields: formData.credential_fields,
      };

      if (providerKey) {
        updateMutation.mutate(submitData);
      } else {
        createMutation.mutate(submitData);
      }
    } catch (error) {
      toast({
        title: "JSON inválido",
        description: "O template JSON não é válido",
        variant: "destructive",
      });
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant={providerKey ? "outline" : "default"} size="sm">
          {providerKey ? (
            <>
              <Save className="mr-2 h-4 w-4" />
              Editar
            </>
          ) : (
            <>
              <Plus className="mr-2 h-4 w-4" />
              Novo Provider
            </>
          )}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {providerKey ? "Editar Provider Customizado" : "Criar Provider Customizado"}
          </DialogTitle>
          <DialogDescription>
            Defina o nome, chave e template JSON do provider. Use placeholders como {"{{NOME}}"}, {"{{TELEFONE}}"}, {"{{CPF_CNPJ}}"}, etc.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label>Chave do Provider (identificador único) *</Label>
            <Input
              value={formData.provider_key}
              onChange={(e) =>
                setFormData({ ...formData, provider_key: e.target.value.toLowerCase().replace(/\s+/g, "_") })
              }
              placeholder="meu_provider"
              disabled={!!providerKey}
            />
            <p className="text-xs text-muted-foreground">
              Apenas letras minúsculas, números e underscore. Não pode ser alterado após criação.
            </p>
          </div>

          <div className="space-y-2">
            <Label>Nome do Provider *</Label>
            <Input
              value={formData.provider_name}
              onChange={(e) => setFormData({ ...formData, provider_name: e.target.value })}
              placeholder="Meu Provider Customizado"
            />
          </div>

          <div className="space-y-2">
            <Label>Template JSON *</Label>
            <Textarea
              value={formData.json_template}
              onChange={(e) => setFormData({ ...formData, json_template: e.target.value })}
              placeholder='{"Cliente": "{{NOME}}", "Phone": "{{TELEFONE}}", "Document": "{{CPF_CNPJ}}"}'
              className="font-mono text-sm"
              rows={10}
            />
            <p className="text-xs text-muted-foreground">
              Use placeholders: {"{{NOME}}"}, {"{{TELEFONE}}"}, {"{{CPF_CNPJ}}"}, {"{{IDGIS_AMBIENTE}}"}, {"{{IDCOB_CONTRATO}}"}, {"{{MENSAGEM}}"}, {"{{DATA_CADASTRO}}"}
            </p>
          </div>

          <div className="flex items-center space-x-2">
            <input
              type="checkbox"
              id="requires_credentials"
              checked={formData.requires_credentials}
              onChange={(e) =>
                setFormData({ ...formData, requires_credentials: e.target.checked })
              }
              className="rounded"
            />
            <Label htmlFor="requires_credentials">Este provider requer credenciais</Label>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={createMutation.isPending || updateMutation.isPending}
          >
            {createMutation.isPending || updateMutation.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {providerKey ? "Atualizar" : "Criar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
