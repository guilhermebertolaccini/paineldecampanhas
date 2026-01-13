import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Eye, MoreHorizontal } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

export interface Campaign {
  id: string;
  name: string;
  status: "pending" | "approved" | "sent" | "denied" | "scheduled";
  provider: string;
  quantity: number;
  createdAt: string;
  user: string;
}

interface CampaignTableProps {
  campaigns: Campaign[];
  showActions?: boolean;
}

const statusConfig = {
  pending: { label: "Pendente", variant: "warning" as const },
  approved: { label: "Aprovado", variant: "info" as const },
  sent: { label: "Enviado", variant: "success" as const },
  denied: { label: "Negado", variant: "destructive" as const },
  scheduled: { label: "Agendado", variant: "secondary" as const },
};

export function CampaignTable({ campaigns, showActions = true }: CampaignTableProps) {
  return (
    <div className="rounded-xl border bg-card overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-muted/50 hover:bg-muted/50">
            <TableHead className="font-semibold">Campanha</TableHead>
            <TableHead className="font-semibold">Status</TableHead>
            <TableHead className="font-semibold">Fornecedor</TableHead>
            <TableHead className="font-semibold text-right">Quantidade</TableHead>
            <TableHead className="font-semibold">Criado em</TableHead>
            <TableHead className="font-semibold">Usuário</TableHead>
            {showActions && <TableHead className="font-semibold w-[100px]">Ações</TableHead>}
          </TableRow>
        </TableHeader>
        <TableBody>
          {campaigns.map((campaign, index) => (
            <TableRow
              key={campaign.id}
              className="animate-fade-in"
              style={{ animationDelay: `${index * 50}ms` }}
            >
              <TableCell className="font-medium">{campaign.name}</TableCell>
              <TableCell>
                <StatusBadge status={campaign.status} />
              </TableCell>
              <TableCell className="text-muted-foreground">{campaign.provider}</TableCell>
              <TableCell className="text-right font-medium">
                {campaign.quantity.toLocaleString("pt-BR")}
              </TableCell>
              <TableCell className="text-muted-foreground">{campaign.createdAt}</TableCell>
              <TableCell className="text-muted-foreground">{campaign.user}</TableCell>
              {showActions && (
                <TableCell>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="h-8 w-8">
                        <MoreHorizontal className="h-4 w-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem>
                        <Eye className="mr-2 h-4 w-4" />
                        Ver detalhes
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              )}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function StatusBadge({ status }: { status: Campaign["status"] }) {
  const config = statusConfig[status] || { label: status, variant: "secondary" as const };
  return (
    <Badge
      variant={config.variant}
      className={cn(
        "font-medium",
        config.variant === "warning" && "bg-warning/10 text-warning border-warning/20 hover:bg-warning/20",
        config.variant === "success" && "bg-success/10 text-success border-success/20 hover:bg-success/20",
        config.variant === "info" && "bg-info/10 text-info border-info/20 hover:bg-info/20",
        config.variant === "destructive" && "bg-destructive/10 text-destructive border-destructive/20 hover:bg-destructive/20"
      )}
    >
      {config.label}
    </Badge>
  );
}
