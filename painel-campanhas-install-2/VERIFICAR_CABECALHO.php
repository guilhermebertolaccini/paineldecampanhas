<?php
/**
 * Script de Verificação do Cabeçalho do Plugin
 * 
 * Execute este arquivo no navegador para verificar se o cabeçalho está correto
 * URL: http://seusite.com/wp-content/plugins/painel-campanhas/VERIFICAR_CABECALHO.php
 */

// Simula a função get_file_data do WordPress
function get_plugin_data($file) {
    $default_headers = array(
        'Name' => 'Plugin Name',
        'PluginURI' => 'Plugin URI',
        'Version' => 'Version',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'TextDomain' => 'Text Domain',
        'DomainPath' => 'Domain Path',
        'Network' => 'Network',
        'RequiresWP' => 'Requires at least',
        'RequiresPHP' => 'Requires PHP',
        'UpdateURI' => 'Update URI',
    );

    $fp = fopen($file, 'r');
    $file_data = fread($fp, 8192);
    fclose($fp);
    $file_data = str_replace("\r", "\n", $file_data);
    $all_headers = $default_headers;

    foreach ($all_headers as $field => $regex) {
        if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
            $all_headers[$field] = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $match[1]));
        } else {
            $all_headers[$field] = '';
        }
    }

    return $all_headers;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Verificação do Cabeçalho do Plugin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { background: #f0f0f0; padding: 10px; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>Verificação do Cabeçalho do Plugin</h1>
    
    <?php
    $plugin_file = __DIR__ . '/painel-campanhas.php';
    
    if (!file_exists($plugin_file)) {
        echo '<p class="error">❌ Arquivo painel-campanhas.php não encontrado!</p>';
        echo '<p>Localização esperada: ' . $plugin_file . '</p>';
        exit;
    }
    
    echo '<p class="success">✅ Arquivo encontrado: ' . $plugin_file . '</p>';
    
    // Verifica se começa com <?php
    $content = file_get_contents($plugin_file);
    $first_line = substr($content, 0, 5);
    
    if ($first_line !== '<?php') {
        echo '<p class="error">❌ Arquivo não começa com &lt;?php</p>';
        echo '<p>Primeiros caracteres: ' . htmlspecialchars($first_line) . '</p>';
    } else {
        echo '<p class="success">✅ Arquivo começa corretamente com &lt;?php</p>';
    }
    
    // Verifica BOM
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        echo '<p class="error">❌ Arquivo contém BOM (Byte Order Mark) - isso pode causar problemas!</p>';
    } else {
        echo '<p class="success">✅ Arquivo não contém BOM</p>';
    }
    
    // Lê o cabeçalho
    $plugin_data = get_plugin_data($plugin_file);
    
    echo '<h2>Dados do Cabeçalho:</h2>';
    echo '<div class="info">';
    echo '<pre>';
    foreach ($plugin_data as $key => $value) {
        if (!empty($value)) {
            echo $key . ': ' . $value . "\n";
        }
    }
    echo '</pre>';
    echo '</div>';
    
    // Verifica campos obrigatórios
    if (empty($plugin_data['Name'])) {
        echo '<p class="error">❌ Campo "Plugin Name" não encontrado ou vazio!</p>';
    } else {
        echo '<p class="success">✅ Plugin Name: ' . $plugin_data['Name'] . '</p>';
    }
    
    if (empty($plugin_data['Version'])) {
        echo '<p class="error">❌ Campo "Version" não encontrado ou vazio!</p>';
    } else {
        echo '<p class="success">✅ Version: ' . $plugin_data['Version'] . '</p>';
    }
    
    // Mostra primeiras linhas do arquivo
    echo '<h2>Primeiras 20 linhas do arquivo:</h2>';
    echo '<div class="info">';
    echo '<pre>';
    $lines = explode("\n", $content);
    for ($i = 0; $i < min(20, count($lines)); $i++) {
        echo ($i + 1) . ': ' . htmlspecialchars($lines[$i]) . "\n";
    }
    echo '</pre>';
    echo '</div>';
    
    // Verifica estrutura de pastas
    echo '<h2>Estrutura de Pastas:</h2>';
    echo '<div class="info">';
    echo '<p>Diretório atual: ' . __DIR__ . '</p>';
    echo '<p>Nome da pasta: ' . basename(__DIR__) . '</p>';
    
    if (basename(__DIR__) !== 'painel-campanhas') {
        echo '<p class="error">⚠️ A pasta não se chama "painel-campanhas"</p>';
        echo '<p>O WordPress espera que a pasta se chame exatamente "painel-campanhas"</p>';
    } else {
        echo '<p class="success">✅ Nome da pasta está correto</p>';
    }
    echo '</div>';
    ?>
    
    <h2>Próximos Passos:</h2>
    <ol>
        <li>Se todos os itens estão ✅, o cabeçalho está correto</li>
        <li>Se houver ❌, corrija os problemas indicados</li>
        <li>Certifique-se de que a pasta se chama exatamente "painel-campanhas"</li>
        <li>O arquivo deve estar em: wp-content/plugins/painel-campanhas/painel-campanhas.php</li>
    </ol>
</body>
</html>

