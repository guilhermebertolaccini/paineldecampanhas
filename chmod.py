
COOLIFY_URL = "https://hetz.taticamarketing.com.br/api/v1"
API_TOKEN = "1|Psb0fCW0NRzV1IKMZk9w9oydg73Ro4B8UUKUPeere2e7bd86"

HEADERS = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json"
}

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