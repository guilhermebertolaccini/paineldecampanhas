import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { Eye, EyeOff, Loader2, MessageSquare } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useToast } from "@/hooks/use-toast";
import { login } from "@/lib/api";

export default function Login() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const navigate = useNavigate();
  const { toast } = useToast();

  // Verifica se já está logado
  useEffect(() => {
    const currentUser = (window as any).pcAjax?.currentUser;
    if (currentUser && currentUser.id) {
      navigate("/painel/home");
    }
  }, [navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);

    try {
      await login(email, password);
      
      toast({
        title: "Login realizado com sucesso!",
        description: "Bem-vindo ao painel de campanhas.",
      });

      // Recarrega a página para atualizar o estado de autenticação
      window.location.href = (window as any).pcAjax?.homeUrl + '/painel/home';
    } catch (error: any) {
      toast({
        title: "Erro no login",
        description: error.message || "Credenciais inválidas. Tente novamente.",
        variant: "destructive",
      });
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex">
      {/* Left Panel - Form */}
      <div className="flex-1 flex items-center justify-center p-8">
        <div className="w-full max-w-md space-y-8 animate-fade-in">
          {/* Logo */}
          <div className="text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary shadow-glow">
              <MessageSquare className="h-7 w-7 text-primary-foreground" />
            </div>
            <h1 className="mt-6 text-3xl font-bold text-foreground">Painel de Campanhas</h1>
            <p className="mt-2 text-muted-foreground">
              Faça login para acessar o sistema
            </p>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="email">E-mail</Label>
              <Input
                id="email"
                type="email"
                placeholder="seu@email.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                className="h-12"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password">Senha</Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  className="h-12 pr-12"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                >
                  {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                </button>
              </div>
            </div>

            <div className="flex items-center justify-between text-sm">
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" className="rounded border-input" />
                <span className="text-muted-foreground">Lembrar-me</span>
              </label>
              <a href="#" className="text-primary hover:underline font-medium">
                Esqueceu a senha?
              </a>
            </div>

            <Button
              type="submit"
              className="w-full h-12 text-base font-semibold gradient-primary hover:opacity-90 transition-opacity"
              disabled={isLoading}
            >
              {isLoading ? (
                <>
                  <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                  Entrando...
                </>
              ) : (
                "Entrar"
              )}
            </Button>
          </form>
        </div>
      </div>

      {/* Right Panel - Decorative */}
      <div className="hidden lg:flex lg:flex-1 gradient-sidebar items-center justify-center p-12">
        <div className="max-w-lg text-center space-y-6 animate-slide-in">
          <div className="mx-auto h-24 w-24 rounded-3xl bg-sidebar-primary flex items-center justify-center shadow-glow">
            <MessageSquare className="h-12 w-12 text-sidebar-primary-foreground" />
          </div>
          <h2 className="text-3xl font-bold text-sidebar-foreground">
            Gerencie suas campanhas com eficiência
          </h2>
          <p className="text-lg text-sidebar-muted">
            Crie, monitore e aprove campanhas de comunicação em uma única plataforma integrada.
          </p>
          <div className="flex justify-center gap-2 pt-4">
            {[0, 1, 2].map((i) => (
              <div
                key={i}
                className={`h-2 rounded-full transition-all ${
                  i === 0 ? "w-8 bg-primary" : "w-2 bg-sidebar-muted/30"
                }`}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
