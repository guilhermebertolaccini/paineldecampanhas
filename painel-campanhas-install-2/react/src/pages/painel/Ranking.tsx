import { useQuery } from "@tanstack/react-query";
import { Trophy, TrendingUp, Users, Send } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { getRanking } from "@/lib/api";

interface Plataforma {
  plataforma: string;
  total: number;
  enviados: number;
}

interface Usuario {
  user_id: string;
  user_name: string;
  user_email: string;
  total_envios: number;
  enviados: number;
  erros: number;
  pendentes: number;
  plataformas: Plataforma[];
}

export default function Ranking() {
  const { data, isLoading } = useQuery({
    queryKey: ['ranking'],
    queryFn: getRanking,
  });

  const ranking: Usuario[] = data?.ranking || [];

  const getMedalColor = (position: number) => {
    switch (position) {
      case 1:
        return "text-yellow-500";
      case 2:
        return "text-gray-400";
      case 3:
        return "text-orange-600";
      default:
        return "text-slate-400";
    }
  };

  const getMedalBg = (position: number) => {
    switch (position) {
      case 1:
        return "bg-yellow-50 border-yellow-200";
      case 2:
        return "bg-gray-50 border-gray-200";
      case 3:
        return "bg-orange-50 border-orange-200";
      default:
        return "";
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800">
      <PageHeader title="Ranking de Disparos" />

      <main className="container mx-auto px-4 py-8 max-w-7xl">
        <div className="mb-6">
          <div className="flex items-center gap-3 mb-2">
            <Trophy className="w-8 h-8 text-yellow-500" />
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">
              Ranking de Usuários
            </h2>
          </div>
          <p className="text-slate-600 dark:text-slate-400">
            Top usuários com mais envios de mensagens por plataforma
          </p>
        </div>

        {isLoading ? (
          <div className="grid gap-4">
            {[1, 2, 3, 4, 5].map((i) => (
              <Card key={i}>
                <CardContent className="p-6">
                  <Skeleton className="h-24 w-full" />
                </CardContent>
              </Card>
            ))}
          </div>
        ) : ranking.length === 0 ? (
          <Card>
            <CardContent className="p-12 text-center">
              <Users className="w-16 h-16 mx-auto text-slate-400 mb-4" />
              <h3 className="text-lg font-semibold mb-2">Nenhum dado disponível</h3>
              <p className="text-slate-600">
                Não há envios registrados ainda
              </p>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4">
            {ranking.map((usuario, index) => {
              const position = index + 1;
              const taxaSucesso = usuario.total_envios > 0
                ? ((usuario.enviados / usuario.total_envios) * 100).toFixed(1)
                : '0.0';

              return (
                <Card
                  key={usuario.user_id}
                  className={`hover:shadow-lg transition-shadow ${position <= 3 ? getMedalBg(position) + ' border-2' : ''}`}
                >
                  <CardHeader className="pb-3">
                    <div className="flex justify-between items-start">
                      <div className="flex items-center gap-4 flex-1">
                        <div className={`flex items-center justify-center w-12 h-12 rounded-full ${position <= 3 ? 'bg-white shadow-md' : 'bg-slate-100 dark:bg-slate-800'}`}>
                          {position <= 3 ? (
                            <Trophy className={`w-6 h-6 ${getMedalColor(position)}`} />
                          ) : (
                            <span className="text-lg font-bold text-slate-600 dark:text-slate-400">
                              {position}
                            </span>
                          )}
                        </div>
                        <div className="flex-1">
                          <CardTitle className="text-lg flex items-center gap-2">
                            {usuario.user_name}
                            {position === 1 && (
                              <Badge className="bg-yellow-500 hover:bg-yellow-600">
                                Líder
                              </Badge>
                            )}
                          </CardTitle>
                          <CardDescription className="text-sm">
                            {usuario.user_email}
                          </CardDescription>
                        </div>
                      </div>
                    </div>
                  </CardHeader>

                  <CardContent>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                      <div className="bg-white dark:bg-slate-800 rounded-lg p-3 border">
                        <div className="flex items-center gap-2 mb-1">
                          <Send className="w-4 h-4 text-blue-500" />
                          <span className="text-xs font-medium text-slate-600 dark:text-slate-400">
                            Total
                          </span>
                        </div>
                        <p className="text-2xl font-bold text-slate-900 dark:text-white">
                          {usuario.total_envios.toLocaleString('pt-BR')}
                        </p>
                      </div>

                      <div className="bg-white dark:bg-slate-800 rounded-lg p-3 border border-green-200">
                        <div className="flex items-center gap-2 mb-1">
                          <TrendingUp className="w-4 h-4 text-green-500" />
                          <span className="text-xs font-medium text-slate-600 dark:text-slate-400">
                            Enviados
                          </span>
                        </div>
                        <p className="text-2xl font-bold text-green-600">
                          {usuario.enviados.toLocaleString('pt-BR')}
                        </p>
                      </div>

                      <div className="bg-white dark:bg-slate-800 rounded-lg p-3 border border-orange-200">
                        <span className="text-xs font-medium text-slate-600 dark:text-slate-400 block mb-1">
                          Pendentes
                        </span>
                        <p className="text-2xl font-bold text-orange-600">
                          {usuario.pendentes.toLocaleString('pt-BR')}
                        </p>
                      </div>

                      <div className="bg-white dark:bg-slate-800 rounded-lg p-3 border border-red-200">
                        <span className="text-xs font-medium text-slate-600 dark:text-slate-400 block mb-1">
                          Taxa Sucesso
                        </span>
                        <p className="text-2xl font-bold text-blue-600">
                          {taxaSucesso}%
                        </p>
                      </div>
                    </div>

                    {/* Plataformas */}
                    {usuario.plataformas && usuario.plataformas.length > 0 && (
                      <div>
                        <h4 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                          Envios por Plataforma:
                        </h4>
                        <div className="flex flex-wrap gap-2">
                          {usuario.plataformas.map((plataforma) => (
                            <Badge
                              key={plataforma.plataforma}
                              variant="outline"
                              className="text-xs"
                            >
                              <strong>{plataforma.plataforma}</strong>: {plataforma.total.toLocaleString('pt-BR')}
                              {plataforma.enviados > 0 && (
                                <span className="text-green-600 ml-1">
                                  ({plataforma.enviados} ✓)
                                </span>
                              )}
                            </Badge>
                          ))}
                        </div>
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </main>
    </div>
  );
}
