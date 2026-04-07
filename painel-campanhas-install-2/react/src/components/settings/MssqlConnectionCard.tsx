import { useState, useEffect } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Server, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useToast } from "@/hooks/use-toast";
import {
  getMssqlSettings,
  runMssqlOperationalSyncNow,
  saveMssqlSettings,
  type MssqlSettingsResponse,
} from "@/lib/api";

export function userCanManageOptions(): boolean {
  const w = window as unknown as {
    pcAjax?: { canManageOptions?: boolean; currentUser?: { isAdmin?: boolean } };
  };
  return Boolean(w.pcAjax?.canManageOptions ?? w.pcAjax?.currentUser?.isAdmin);
}

/**
 * Credenciais MSSQL / DB_DIGITAL (WP options). Exibido no API Manager.
 */
export function MssqlConnectionCard() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [mssqlForm, setMssqlForm] = useState({
    enabled: false,
    host: "",
    port: "1433",
    database: "",
    user: "",
    password: "",
    viewsCatalog: "",
    linkedPrefix: "",
  });

  const { data: mssqlSettings, isLoading: mssqlSettingsLoading } = useQuery({
    queryKey: ["mssql-settings"],
    queryFn: getMssqlSettings,
    enabled: userCanManageOptions(),
  });

  useEffect(() => {
    if (!mssqlSettings) return;
    const s = mssqlSettings as MssqlSettingsResponse;
    setMssqlForm((prev) => ({
      ...prev,
      enabled: s.pc_mssql_enabled === "1",
      host: s.pc_mssql_host || "",
      port: s.pc_mssql_port || "1433",
      database: s.pc_mssql_database || "",
      user: s.pc_mssql_user || "",
      password: "",
      viewsCatalog: s.pc_mssql_views_info_schema_catalog || "",
      linkedPrefix: s.pc_mssql_linked_four_part_prefix || "",
    }));
  }, [mssqlSettings]);

  const saveMssqlMutation = useMutation({
    mutationFn: () =>
      saveMssqlSettings({
        pc_mssql_enabled: mssqlForm.enabled ? "1" : "0",
        pc_mssql_host: mssqlForm.host,
        pc_mssql_port: mssqlForm.port,
        pc_mssql_database: mssqlForm.database,
        pc_mssql_user: mssqlForm.user,
        pc_mssql_password: mssqlForm.password,
        pc_mssql_views_info_schema_catalog: mssqlForm.viewsCatalog,
        pc_mssql_linked_four_part_prefix: mssqlForm.linkedPrefix,
      }),
    onSuccess: (res) => {
      toast({
        title: res?.message || "MSSQL salvo",
        description: "As credenciais foram gravadas nas opções do WordPress.",
      });
      queryClient.invalidateQueries({ queryKey: ["mssql-settings"] });
      queryClient.invalidateQueries({ queryKey: ["available-bases"] });
      queryClient.invalidateQueries({ queryKey: ["line-health"] });
      setMssqlForm((f) => ({ ...f, password: "" }));
    },
    onError: (error: Error) => {
      toast({
        title: "Erro ao salvar MSSQL",
        description: error.message,
        variant: "destructive",
      });
    },
  });

  const syncNowMutation = useMutation({
    mutationFn: runMssqlOperationalSyncNow,
    onSuccess: (res) => {
      toast({
        title: res?.message || "Sincronização MSSQL",
        description: res?.warning
          ? res.warning
          : `Último espelho: ${res?.last_mirror_sync || "—"} · Snapshot: ${res?.last_snapshot_refresh || "—"}`,
      });
      queryClient.invalidateQueries({ queryKey: ["line-health"] });
      queryClient.invalidateQueries({ queryKey: ["available-bases"] });
    },
    onError: (error: Error) => {
      toast({
        title: "Falha na sincronização MSSQL",
        description: error.message,
        variant: "destructive",
      });
    },
  });

  const mssqlWpOverrides = mssqlSettings?.wp_config_override;
  const hasAnyMssqlWpOverride =
    mssqlWpOverrides && Object.values(mssqlWpOverrides).some(Boolean);

  if (!userCanManageOptions()) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-muted">
            <Server className="h-5 w-5 text-primary" />
          </div>
          <div>
            <CardTitle className="text-lg">Conexão de Dados Externa (MSSQL / DB_DIGITAL)</CardTitle>
            <CardDescription>
              Credenciais para listar views <code className="text-xs">VW_BASE%</code> e telemetria. Exige extensão{" "}
              <code className="text-xs">pdo_sqlsrv</code> no PHP. As tabelas{" "}
              <code className="text-xs">PC_WP_MIRROR_ROWS</code> e{" "}
              <code className="text-xs">PC_LINE_HEALTH_SNAPSHOT</code> são criadas automaticamente no banco MSSQL
              configurado na primeira sincronização bem-sucedida — não é preciso criar manualmente no servidor (ex.:{" "}
              <span className="text-xs">.26</span>).
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-6">
        {hasAnyMssqlWpOverride && (
          <Alert>
            <AlertTitle>Constantes em wp-config.php</AlertTitle>
            <AlertDescription>
              Há <code className="text-xs">define(&apos;PC_MSSQL_*&apos;)</code> ativos: eles prevalecem sobre os campos
              abaixo.
            </AlertDescription>
          </Alert>
        )}
        {mssqlSettingsLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <>
            <div className="flex flex-row items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="pc-mssql-enabled">Integração MSSQL ativa</Label>
                <p className="text-sm text-muted-foreground">
                  Quando desligada, o painel ignora SQL Server para bases remotas e saúde das linhas.
                </p>
              </div>
              <Switch
                id="pc-mssql-enabled"
                checked={mssqlForm.enabled}
                onCheckedChange={(v) => setMssqlForm((f) => ({ ...f, enabled: v }))}
              />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="pc-mssql-host">Host</Label>
                <Input
                  id="pc-mssql-host"
                  autoComplete="off"
                  value={mssqlForm.host}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, host: e.target.value }))}
                  placeholder="10.103.2.26"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="pc-mssql-port">Porta</Label>
                <Input
                  id="pc-mssql-port"
                  inputMode="numeric"
                  value={mssqlForm.port}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, port: e.target.value }))}
                  placeholder="1433"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="pc-mssql-database">Banco</Label>
                <Input
                  id="pc-mssql-database"
                  autoComplete="off"
                  value={mssqlForm.database}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, database: e.target.value }))}
                  placeholder="DB_DIGITAL"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="pc-mssql-user">Usuário</Label>
                <Input
                  id="pc-mssql-user"
                  autoComplete="off"
                  value={mssqlForm.user}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, user: e.target.value }))}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="pc-mssql-password">Senha</Label>
                <Input
                  id="pc-mssql-password"
                  type="password"
                  autoComplete="new-password"
                  value={mssqlForm.password}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, password: e.target.value }))}
                  placeholder={
                    mssqlSettings?.has_saved_password
                      ? "Senha já salva — digite uma nova para alterar"
                      : "Senha do SQL Server"
                  }
                />
                {mssqlSettings?.has_saved_password && (
                  <p className="text-xs text-muted-foreground">
                    Valor atual não é exibido por segurança. Deixe em branco para manter a senha já armazenada.
                  </p>
                )}
              </div>
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="pc-mssql-views-catalog">Prefixo catálogo (Info Schema / linked server)</Label>
                <Input
                  id="pc-mssql-views-catalog"
                  autoComplete="off"
                  value={mssqlForm.viewsCatalog}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, viewsCatalog: e.target.value }))}
                  placeholder="[SRV27].[DB_DIGITAL]"
                />
                <p className="text-xs text-muted-foreground">
                  Opcional: lista <code className="text-xs">VW_BASE%</code> no servidor remoto via four-part name.
                </p>
              </div>
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="pc-mssql-linked-prefix">Prefixo four-part (leitura de dados)</Label>
                <Input
                  id="pc-mssql-linked-prefix"
                  autoComplete="off"
                  value={mssqlForm.linkedPrefix}
                  onChange={(e) => setMssqlForm((f) => ({ ...f, linkedPrefix: e.target.value }))}
                  placeholder="[SRV27].[DB_DIGITAL].[dbo]"
                />
                <p className="text-xs text-muted-foreground">
                  Usado em <code className="text-xs">SELECT</code> sobre views remotas.
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <Button
                type="button"
                onClick={() => saveMssqlMutation.mutate()}
                disabled={saveMssqlMutation.isPending}
                className="gradient-primary hover:opacity-90"
              >
                {saveMssqlMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Salvar conexão MSSQL
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => syncNowMutation.mutate()}
                disabled={syncNowMutation.isPending || !mssqlForm.enabled}
                title={
                  !mssqlForm.enabled
                    ? "Ative a integração MSSQL acima para sincronizar."
                    : "Cria as tabelas de espelho no SQL Server se não existirem e copia os dados do WordPress."
                }
              >
                {syncNowMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Sincronizar espelho agora
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
