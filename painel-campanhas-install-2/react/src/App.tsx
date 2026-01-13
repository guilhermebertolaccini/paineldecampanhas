import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { HashRouter, Routes, Route, Navigate, useLocation } from "react-router-dom";
import { useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import NotFound from "./pages/NotFound";

// Painel pages
import Login from "./pages/painel/Login";
import Dashboard from "./pages/painel/Dashboard";
import Campanhas from "./pages/painel/Campanhas";
import NovaCampanha from "./pages/painel/NovaCampanha";
import CampanhaArquivo from "./pages/painel/CampanhaArquivo";
import CampanhasRecorrentes from "./pages/painel/CampanhasRecorrentes";
import AprovarCampanhas from "./pages/painel/AprovarCampanhas";
import Mensagens from "./pages/painel/Mensagens";
import Relatorios from "./pages/painel/Relatorios";
import ControleCusto from "./pages/painel/ControleCusto";
import CadastroCusto from "./pages/painel/CadastroCusto";
import RelatorioCusto from "./pages/painel/RelatorioCusto";
import Configuracoes from "./pages/painel/Configuracoes";
import Blocklist from "./pages/painel/Blocklist";
import ApiManager from "./pages/painel/ApiManager";
import Iscas from "./pages/painel/Iscas";
import Ranking from "./pages/painel/Ranking";

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
});

// Componente para sincronizar rota com página atual do WordPress
function RouterSync() {
  const location = useLocation();
  
  useEffect(() => {
    // Se tiver página atual do WordPress, navega para ela
    const currentPage = (window as any).pcAjax?.currentPage;
    if (currentPage && location.pathname === '/') {
      const routeMap: Record<string, string> = {
        'login': '/#/painel/login',
        'home': '/#/painel/home',
        'campanhas': '/#/painel/campanhas',
        'nova-campanha': '/#/painel/nova-campanha',
        'campanha-arquivo': '/#/painel/campanha-arquivo',
        'campanhas-recorrentes': '/#/painel/campanhas-recorrentes',
        'aprovar-campanhas': '/#/painel/aprovar-campanhas',
        'mensagens': '/#/painel/mensagens',
        'relatorios': '/#/painel/relatorios',
        'controle-custo': '/#/painel/controle-custo',
        'controle-custo-cadastro': '/#/painel/controle-custo/cadastro',
        'controle-custo-relatorio': '/#/painel/controle-custo/relatorio',
        'configuracoes': '/#/painel/configuracoes',
        'blocklist': '/#/painel/blocklist',
        'api-manager': '/#/painel/api-manager',
        'iscas': '/#/painel/iscas',
        'ranking': '/#/painel/ranking',
      };
      
      const targetRoute = routeMap[currentPage];
      if (targetRoute) {
        window.location.hash = targetRoute.replace('/#', '');
      }
    }
  }, [location]);

  return null;
}

const App = () => {
  // Detecta página inicial baseado na URL do WordPress ou hash
  const getInitialRoute = () => {
    // Tenta pegar da URL atual
    const hash = window.location.hash.replace('#', '');
    if (hash && hash !== '/') {
      return hash;
    }
    
    // Tenta pegar do WordPress
    const currentPage = (window as any).pcAjax?.currentPage;
    if (currentPage) {
      const routeMap: Record<string, string> = {
        'login': '/painel/login',
        'home': '/painel/home',
        'campanhas': '/painel/campanhas',
        'nova-campanha': '/painel/nova-campanha',
        'campanha-arquivo': '/painel/campanha-arquivo',
        'campanhas-recorrentes': '/painel/campanhas-recorrentes',
        'aprovar-campanhas': '/painel/aprovar-campanhas',
        'mensagens': '/painel/mensagens',
        'relatorios': '/painel/relatorios',
        'controle-custo': '/painel/controle-custo',
        'controle-custo-cadastro': '/painel/controle-custo/cadastro',
        'controle-custo-relatorio': '/painel/controle-custo/relatorio',
        'configuracoes': '/painel/configuracoes',
        'blocklist': '/painel/blocklist',
        'api-manager': '/painel/api-manager',
        'iscas': '/painel/iscas',
        'ranking': '/painel/ranking',
      };
      return routeMap[currentPage] || '/painel/home';
    }
    
    return '/painel/home';
  };

  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <Toaster />
        <Sonner />
        <HashRouter>
          <RouterSync />
          <Routes>
            {/* Redirect root to dashboard */}
            <Route path="/" element={<Navigate to={getInitialRoute()} replace />} />
            
            {/* Login - without layout */}
            <Route path="/painel/login" element={<Login />} />
            
            {/* Dashboard routes with layout */}
            <Route path="/painel" element={<DashboardLayout />}>
              <Route path="home" element={<Dashboard />} />
              <Route path="campanhas" element={<Campanhas />} />
              <Route path="nova-campanha" element={<NovaCampanha />} />
              <Route path="campanha-arquivo" element={<CampanhaArquivo />} />
              <Route path="campanhas-recorrentes" element={<CampanhasRecorrentes />} />
              <Route path="aprovar-campanhas" element={<AprovarCampanhas />} />
              <Route path="mensagens" element={<Mensagens />} />
              <Route path="relatorios" element={<Relatorios />} />
              <Route path="controle-custo" element={<ControleCusto />} />
              <Route path="controle-custo/cadastro" element={<CadastroCusto />} />
              <Route path="controle-custo/relatorio" element={<RelatorioCusto />} />
              <Route path="configuracoes" element={<Configuracoes />} />
              <Route path="blocklist" element={<Blocklist />} />
              <Route path="api-manager" element={<ApiManager />} />
              <Route path="iscas" element={<Iscas />} />
              <Route path="ranking" element={<Ranking />} />
            </Route>
            
            {/* Catch-all */}
            <Route path="*" element={<NotFound />} />
          </Routes>
        </HashRouter>
      </TooltipProvider>
    </QueryClientProvider>
  );
};

export default App;
