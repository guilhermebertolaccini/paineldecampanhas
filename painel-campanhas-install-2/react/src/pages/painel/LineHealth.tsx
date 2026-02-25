import { useQuery } from "@tanstack/react-query";
import { Activity, Shield, Info, AlertCircle, CheckCircle2, Clock } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { getGosacOficialConnections } from "@/lib/api";

export default function LineHealth() {
    const { data: connections = [], isLoading } = useQuery({
        queryKey: ['gosac-connections'],
        queryFn: getGosacOficialConnections,
    });

    const getStatusBadge = (status: string) => {
        switch (status?.toUpperCase()) {
            case 'CONNECTED':
                return <Badge className="bg-green-500 font-medium whitespace-nowrap"><CheckCircle2 className="w-3 h-3 mr-1" /> Conectado</Badge>;
            case 'DISCONNECTED':
                return <Badge variant="destructive" className="font-medium whitespace-nowrap"><AlertCircle className="w-3 h-3 mr-1" /> Desconectado</Badge>;
            default:
                return <Badge variant="secondary" className="font-medium whitespace-nowrap"><Clock className="w-3 h-3 mr-1" /> {status || 'Desconhecido'}</Badge>;
        }
    };

    const getLimitBadge = (limit: string) => {
        if (!limit) return <Badge variant="outline">N/A</Badge>;
        const color = limit.includes('1k') ? 'bg-blue-500' : limit.includes('10k') ? 'bg-purple-500' : 'bg-emerald-500';
        return <Badge className={`${color} font-medium`}>{limit}</Badge>;
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Saúde das Linhas"
                description="Monitore os limites de mensagens e restrições das contas Gosac Oficial."
            />

            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card className="bg-gradient-to-br from-blue-500/10 to-indigo-500/10 border-blue-200/50">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Total de Conexões</CardTitle>
                        <Activity className="h-4 w-4 text-blue-600" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{connections.length}</div>
                    </CardContent>
                </Card>

                <Card className="bg-gradient-to-br from-emerald-500/10 to-teal-500/10 border-emerald-200/50">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Linhas Ativas</CardTitle>
                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">
                            {connections.filter((c: any) => c.status === 'CONNECTED').length}
                        </div>
                    </CardContent>
                </Card>

                <Card className="bg-gradient-to-br from-amber-500/10 to-orange-500/10 border-amber-200/50">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Restrições</CardTitle>
                        <Shield className="h-4 w-4 text-amber-600" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">
                            {connections.filter((c: any) => c.accountRestriction !== 'NONE' && c.accountRestriction).length}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Conexões Oficiais</CardTitle>
                    <CardDescription>
                        Detalhes de limites e status das conexões Gosac Oficial integradas.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <div className="space-y-2">
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                        </div>
                    ) : connections.length === 0 ? (
                        <div className="text-center py-10 text-muted-foreground">
                            <Info className="w-10 h-10 mx-auto mb-2 opacity-20" />
                            <p>Nenhuma conexão encontrada ou credencial não configurada.</p>
                        </div>
                    ) : (
                        <div className="rounded-md border overflow-hidden">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="font-bold">Nome / ID</TableHead>
                                        <TableHead className="font-bold text-center">Ambiente</TableHead>
                                        <TableHead className="font-bold text-center">Status</TableHead>
                                        <TableHead className="font-bold text-center">Limite Diário</TableHead>
                                        <TableHead className="font-bold text-center">Restrição</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {connections.map((conn: any) => (
                                        <TableRow key={conn.id}>
                                            <TableCell className="font-medium">
                                                <div className="font-bold">{conn.name}</div>
                                                <div className="text-[10px] uppercase tracking-wider text-muted-foreground font-mono">{conn.id}</div>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="outline" className="font-mono text-[10px]">{conn.env_id}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">{getStatusBadge(conn.status)}</TableCell>
                                            <TableCell className="text-center">{getLimitBadge(conn.messagingLimit)}</TableCell>
                                            <TableCell className="text-center">
                                                {conn.accountRestriction === 'NONE' || !conn.accountRestriction ? (
                                                    <span className="text-green-600 text-xs font-bold uppercase">Nenhuma</span>
                                                ) : (
                                                    <Badge variant="destructive" className="font-bold text-[10px]">{conn.accountRestriction}</Badge>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
