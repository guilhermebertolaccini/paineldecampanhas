import requests
import time

# Suas credenciais e URL do Coolify
COOLIFY_URL = "https://hetz.taticamarketing.com.br/api/v1"
API_TOKEN = "1|Psb0fCW0NRzV1IKMZk9w9oydg73Ro4B8UUKUPeere2e7bd86"

HEADERS = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json"
}

def fix_coolify_services():
    print("Buscando serviços no Coolify...")
    
    # Endpoint para listar serviços (ajuste conforme a versão da sua API, verifique em /api/docs)
    response = requests.get(f"{COOLIFY_URL}/services", headers=HEADERS)
    
    if response.status_code != 200:
        print(f"Erro ao buscar serviços: {response.text}")
        return

    services = response.json()
    
    for service in services:
        uuid = service.get('uuid')
        name = service.get('name')
        compose_yaml = service.get('docker_compose', '')
        
        # Só mexe se for um serviço que tem healthcheck de 5s
        if 'interval: 5s' in compose_yaml or 'interval: 10s' in compose_yaml:
            print(f"[{name}] Encontrado healthcheck agressivo. Corrigindo...")
            
            # Substitui o intervalo e injeta o init: true no mariadb
            new_yaml = compose_yaml.replace('interval: 5s', 'interval: 120s')
            new_yaml = new_yaml.replace('interval: 10s', 'interval: 120s')
            
            if 'image: mariadb' in new_yaml and 'init: true' not in new_yaml:
                new_yaml = new_yaml.replace('image: mariadb', 'image: mariadb\n    init: true')

            # Envia o YAML corrigido de volta
            update_data = {"docker_compose": new_yaml}
            update_resp = requests.patch(f"{COOLIFY_URL}/services/{uuid}", json=update_data, headers=HEADERS)
            
            if update_resp.status_code == 200:
                print(f"[{name}] YAML atualizado! Disparando deploy...")
                # O deploy recria o container com as novas regras
                #requests.post(f"{COOLIFY_URL}/services/{uuid}/start", headers=HEADERS)
                
                # PAUSA DE 30 SEGUNDOS ENTRE OS DEPLOYS PARA NÃO INFARTAR A CPU
                print("Aguardando 30 segundos para o servidor respirar antes do próximo...")
                time.sleep(30)
            else:
                print(f"[{name}] Erro ao atualizar: {update_resp.text}")

            for container in $(docker ps -q); do
                # Testa se o container tem a estrutura do WordPress silenciosamente
                if docker exec $container test -d /var/www/html/wp-includes 2>/dev/null; then
                    echo "Corrigindo permissões no WordPress do container: $container"
                    
                    # Define o dono correto (www-data / UID 33)
                    docker exec $container chown -R www-data:www-data /var/www/html
                    
                    # Aplica CHMOD 755 para pastas
                    docker exec $container find /var/www/html -type d -exec chmod 755 {} \;
                    
                    # Aplica CHMOD 644 para arquivos
                    docker exec $container find /var/www/html -type f -exec chmod 644 {} \;
    fi
done
echo "Permissões corrigidas com sucesso!"

                

if __name__ == "__main__":
    fix_coolify_services()