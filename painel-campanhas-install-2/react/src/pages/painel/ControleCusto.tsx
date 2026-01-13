import { Link } from "react-router-dom";
import { DollarSign, FileText, BarChart3 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function ControleCusto() {
  return (
    <div className="space-y-6">
      <PageHeader
        title="Controle de Custo"
        description="Gerencie custos e orçamentos do sistema"
      />

      <div className="grid gap-6 sm:grid-cols-2">
        <Link to="/painel/controle-custo/cadastro">
          <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 cursor-pointer">
            <CardHeader>
              <div className="flex h-14 w-14 items-center justify-center rounded-2xl gradient-primary mb-4">
                <FileText className="h-7 w-7 text-primary-foreground" />
              </div>
              <CardTitle className="text-xl">Cadastro de Custos</CardTitle>
              <CardDescription className="text-base">
                Configure custos por mensagem e defina orçamentos por carteira
              </CardDescription>
            </CardHeader>
          </Card>
        </Link>

        <Link to="/painel/controle-custo/relatorio">
          <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 cursor-pointer">
            <CardHeader>
              <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-info/10 mb-4">
                <BarChart3 className="h-7 w-7 text-info" />
              </div>
              <CardTitle className="text-xl">Relatório de Custos</CardTitle>
              <CardDescription className="text-base">
                Visualize gastos por provedor e carteira com filtros por período
              </CardDescription>
            </CardHeader>
          </Card>
        </Link>
      </div>
    </div>
  );
}
