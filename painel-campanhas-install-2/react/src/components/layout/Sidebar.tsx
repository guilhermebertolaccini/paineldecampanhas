import { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  FileText,
  PlusCircle,
  Upload,
  RefreshCw,
  CheckCircle,
  MessageSquare,
  BarChart3,
  DollarSign,
  Settings,
  Shield,
  Key,
  ChevronDown,
  LogOut,
  Menu,
  X,
  Fish,
  Trophy,
  Activity,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

interface NavItem {
  label: string;
  href: string;
  icon: React.ElementType;
  adminOnly?: boolean;
  children?: { label: string; href: string }[];
}

const navItems: NavItem[] = [
  { label: "Dashboard", href: "/painel/home", icon: LayoutDashboard },
  { label: "Minhas Campanhas", href: "/painel/campanhas", icon: FileText },
  { label: "Nova Campanha", href: "/painel/nova-campanha", icon: PlusCircle },
  { label: "Campanha via Arquivo", href: "/painel/campanha-arquivo", icon: Upload },
  { label: "Campanhas Recorrentes", href: "/painel/campanhas-recorrentes", icon: RefreshCw },
  { label: "Aprovar Campanhas", href: "/painel/aprovar-campanhas", icon: CheckCircle, adminOnly: true },
  { label: "Templates de Mensagem", href: "/painel/mensagens", icon: MessageSquare },
  { label: "Relatórios", href: "/painel/relatorios", icon: BarChart3 },
  { label: "Ranking de Disparo", href: "/painel/ranking", icon: Trophy },
  {
    label: "Controle de Custo",
    href: "/painel/controle-custo",
    icon: DollarSign,
    children: [
      { label: "Cadastro", href: "/painel/controle-custo/cadastro" },
      { label: "Relatório", href: "/painel/controle-custo/relatorio" },
    ],
  },
  { label: "Configurações", href: "/painel/configuracoes", icon: Settings, adminOnly: true },
  { label: "Cadastro de Iscas", href: "/painel/iscas", icon: Fish },
  { label: "Blocklist", href: "/painel/blocklist", icon: Shield, adminOnly: true },
  { label: "Saúde das Linhas", href: "/painel/saude-linhas", icon: Activity },
  { label: "API Manager", href: "/painel/api-manager", icon: Key, adminOnly: true },
];

export function Sidebar() {
  const location = useLocation();
  const [expandedItems, setExpandedItems] = useState<string[]>(["Controle de Custo"]);
  const [isMobileOpen, setIsMobileOpen] = useState(false);

  const toggleExpanded = (label: string) => {
    setExpandedItems((prev) =>
      prev.includes(label) ? prev.filter((item) => item !== label) : [...prev, label]
    );
  };

  const handleLogout = async () => {
    try {
      // Chama o endpoint de logout
      await fetch('/wordpress/wp-admin/admin-ajax.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'pc_logout',
        }),
        credentials: 'include', // Importante para enviar cookies
      });
    } catch (error) {
      console.error('Erro ao fazer logout:', error);
    } finally {
      // SEMPRE redireciona para o login do WordPress, independente do resultado
      // Isso garante que o usuário seja deslogado mesmo que o AJAX falhe
      window.location.href = '/wordpress/wp-login.php?loggedout=true&redirect_to=' + encodeURIComponent(window.location.origin + '/wordpress/wp-admin/');
    }
  };

  const isActive = (href: string) => location.pathname === href;
  const isParentActive = (item: NavItem) =>
    item.children?.some((child) => location.pathname === child.href);

  return (
    <>
      {/* Mobile Toggle */}
      <Button
        variant="ghost"
        size="icon"
        className="fixed top-4 left-4 z-50 lg:hidden"
        onClick={() => setIsMobileOpen(!isMobileOpen)}
      >
        {isMobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
      </Button>

      {/* Overlay */}
      {isMobileOpen && (
        <div
          className="fixed inset-0 bg-foreground/20 backdrop-blur-sm z-40 lg:hidden"
          onClick={() => setIsMobileOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={cn(
          "fixed left-0 top-0 z-40 h-screen w-64 gradient-sidebar border-r border-sidebar-border transition-transform duration-300 lg:translate-x-0 flex flex-col",
          isMobileOpen ? "translate-x-0" : "-translate-x-full"
        )}
      >
        {/* Logo */}
        <div className="flex h-16 items-center gap-3 border-b border-sidebar-border px-6 shrink-0">
          <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-sidebar-primary">
            <MessageSquare className="h-5 w-5 text-sidebar-primary-foreground" />
          </div>
          <span className="text-lg font-semibold text-sidebar-foreground">Campanhas</span>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto sidebar-scroll px-3 py-4">
          <ul className="space-y-1">
            {navItems.map((item) => (
              <li key={item.label}>
                {item.children ? (
                  <div>
                    <button
                      onClick={() => toggleExpanded(item.label)}
                      className={cn(
                        "flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-sm font-medium transition-colors",
                        isParentActive(item)
                          ? "bg-sidebar-accent text-sidebar-primary"
                          : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground"
                      )}
                    >
                      <div className="flex items-center gap-3">
                        <item.icon className="h-5 w-5" />
                        <span>{item.label}</span>
                      </div>
                      <ChevronDown
                        className={cn(
                          "h-4 w-4 transition-transform",
                          expandedItems.includes(item.label) && "rotate-180"
                        )}
                      />
                    </button>
                    {expandedItems.includes(item.label) && (
                      <ul className="mt-1 ml-4 space-y-1 border-l border-sidebar-border pl-4">
                        {item.children.map((child) => (
                          <li key={child.href}>
                            <Link
                              to={child.href}
                              onClick={() => setIsMobileOpen(false)}
                              className={cn(
                                "block rounded-lg px-3 py-2 text-sm transition-colors no-underline",
                                isActive(child.href)
                                  ? "bg-sidebar-accent text-sidebar-primary font-medium"
                                  : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground"
                              )}
                            >
                              {child.label}
                            </Link>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                ) : (
                  <Link
                    to={item.href}
                    onClick={() => setIsMobileOpen(false)}
                    className={cn(
                      "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors no-underline",
                      isActive(item.href)
                        ? "bg-sidebar-accent text-sidebar-primary"
                        : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground"
                    )}
                  >
                    <item.icon className="h-5 w-5" />
                    <span>{item.label}</span>
                    {item.adminOnly && (
                      <span className="ml-auto text-[10px] font-medium uppercase tracking-wide text-sidebar-primary bg-sidebar-accent px-1.5 py-0.5 rounded">
                        Admin
                      </span>
                    )}
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </nav>

        {/* User Section */}
        <div className="border-t border-sidebar-border p-4 shrink-0">
          <div className="flex items-center gap-3 rounded-lg bg-sidebar-accent p-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-sidebar-primary text-sidebar-primary-foreground text-sm font-semibold">
              AD
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-sidebar-foreground truncate">Admin</p>
              <p className="text-xs text-sidebar-muted truncate">admin@empresa.com</p>
            </div>
            <button
              onClick={handleLogout}
              className="text-sidebar-muted hover:text-sidebar-foreground transition-colors"
              title="Sair"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
