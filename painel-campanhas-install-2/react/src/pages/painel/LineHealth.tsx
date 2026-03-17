import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { Activity, Shield, Info, AlertCircle, CheckCircle2, Clock, Wallet } from "lucide-react";
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
import { getWalletsHealth } from "@/lib/api";

export default function LineHealth() {
    const [connections, setConnections] = useState<any[]>([]);

    const { data: rawData, isLoading, isError } = useQuery({
        queryKey: ['wallets-health'],
        queryFn: getWalletsHealth,
    });

    useEffect(() => {
        if (rawData === undefined) return;
        if (rawData && typeof rawData === 'object' && !Array.isArray(rawData) && rawData.connections) {
            setConnections(Array.isArray(rawData.connections) ? rawData.connections : []);
        } else if (Array.isArray(rawData)) {
            setConnections(rawData);
        } else {
            setConnections([]);
        }
    }, [rawData]);

    const getStatusBadge = (conn: any) => {
        const status = conn.status;
        const type = conn.type;
        if (!status && type) {
            return <Badge className="bg-blue-500 font-medium whitespace-nowrap"><CheckCircle2 className="w-3 h-3 mr-1" /> {String(type).toUpperCase()}</Badge>;
        }
        switch (String(status || '').toUpperCase()) {
            case 'CONNECTED':
                return <Badge className="bg-green-500 font-medium whitespace-nowrap"><CheckCircle2 className="w-3 h-3 mr-1" /> Conectado</Badge>;
            case 'DISCONNECTED':
                return <Badge variant="destructive" className="font-medium whitespace-nowrap"><AlertCircle className="w-3 h-3 mr-1" /> Desconectado</Badge>;
            default:
                return <Badge variant="secondary" className="font-medium whitespace-nowrap"><Clock className="w-3 h-3 mr-1" /> {status ? String(status) : 'Ativo'}</Badge>;
        }
    };

    const getRestrictionDisplay = (restriction: any) => {
        if (!restriction || restriction === 'NONE') {
            return <span className="text-green-600 text-xs font-bold uppercase tracking-tight">Normal</span>;
        }
        if (typeof restriction === 'object' && restriction !== null && Array.isArray(restriction.restriction_info)) {
            const types = restriction.restriction_info
                .map((r: any) => String(r.restriction_type || '').replace('RESTRICTED_', ''))
                .join(', ');
            return <Badge variant="destructive" className="font-bold text-[10px] max-w-[140px] truncate" title={types}>{types}</Badge>;
        }
        return <Badge variant="destructive" className="font-bold text-[10px]">{String(restriction)}</Badge>;
    };

    const getLimitBadge = (limit: any) => {
        if (!limit) return <Badge variant="outline">N/A</Badge>;
        const s = String(limit);
        const color = s.includes('1K') || s.includes('1k') ? 'bg-blue-500'
            : s.includes('10K') || s.includes('10k') ? 'bg-purple-500'
            : 'bg-emerald-500';
        return <Badge className={`${color} font-medium`}>{s}</Badge>;
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Saúde das Linhas"
                description="Status e saúde das conexões em todas as carteiras registradas."
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
                            {connections.filter((c: any) => c.status === 'CONNECTED' || (!c.status && c.type)).length}
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
                            {connections.filter((c: any) => c.accountRestriction && c.accountRestriction !== 'NONE').length}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Visão Consolidada</CardTitle>
                    <CardDescription>
                        Status das conexões mapeadas através das carteiras e provedores.
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
                            <p>Nenhuma conexão encontrada ou credenciais não configuradas.</p>
                        </div>
                    ) : (
                        <div className="rounded-md border overflow-hidden">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="font-bold">Nome / ID</TableHead>
                                        <TableHead className="font-bold">Carteira</TableHead>
                                        <TableHead className="font-bold text-center">Provedor</TableHead>
                                        <TableHead className="font-bold text-center">Status</TableHead>
                                        <TableHead className="font-bold text-center">Limite</TableHead>
                                        <TableHead className="font-bold text-center">Restrição</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {connections.map((conn: any, index: number) => (
                                        <TableRow key={`${conn.id}-${index}`}>
                                            <TableCell className="font-medium">
                                                <div className="font-bold">{String(conn.name ?? '')}</div>
                                                <div className="text-[10px] uppercase tracking-wider text-muted-foreground font-mono">{String(conn.id ?? '')}</div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center text-xs font-semibold">
                                                    <Wallet className="w-3 h-3 mr-1 text-muted-foreground" />
                                                    {String(conn.wallet_name ?? '—')}
                                                </div>
                                                <div className="text-[10px] text-muted-foreground font-mono">ID: {String(conn.id_ambient ?? '')}</div>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary" className="text-[10px] uppercase">{String(conn.provider ?? '')}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">{getStatusBadge(conn)}</TableCell>
                                            <TableCell className="text-center">{getLimitBadge(conn.messagingLimit)}</TableCell>
                                            <TableCell className="text-center">
                                                {conn.provider === 'Noah Oficial' ? (
                                                    <Badge
                                                        variant={conn.qualityRating === 'GREEN' ? 'default' : conn.qualityRating === 'YELLOW' || conn.qualityRating === 'RED' ? 'destructive' : 'secondary'}
                                                        className="text-[10px]"
                                                    >
                                                        {conn.qualityRating || '—'}
                                                    </Badge>
                                                ) : (
                                                    getRestrictionDisplay(conn.accountRestriction)
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
