<?php
// scripts/test_csrf_login.php
// Uso (CLI): php test_csrf_login.php <base_url> <email> <password>
// Ejemplo: php test_csrf_login.php http://localhost/finanzas_ves test@example.com password123

// Load .env for CLI if present (not required for curl but harmless)
if (file_exists(__DIR__ . '/../config/load_env.php')) {
    require_once __DIR__ . '/../config/load_env.php';
}

if ($argc < 4) {
    echo "Uso: php test_csrf_login.php <base_url> <email> <password>\n";
    exit(1);
}

$base = rtrim($argv[1], '/');
$email = $argv[2];
$password = $argv[3];

$loginPage = $base . '/index.php';
$cookieFile = sys_get_temp_dir() . '/fp_cookies.txt';

// 1) GET login page to retrieve CSRF token
$ch = curl_init($loginPage);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode !== 200) {
    echo "Error obteniendo la página de login: HTTP $httpCode\n";
    exit(1);
}
curl_close($ch);

// 2) Extraer token CSRF del HTML (campo hidden)
if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m)) {
    $token = $m[1];
    echo "Token CSRF encontrado: $token\n";
} else {
    echo "No se encontró token CSRF en la página de login.\n";
    exit(1);
}

// 3) Enviar POST de login usando el token y las cookies
$postUrl = $base . '/index.php';
$postFields = http_build_query([
    'csrf_token' => $token,
    'email' => $email,
    'password' => $password
]);

$ch = curl_init($postUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

echo "POST login: HTTP $httpCode\n";
echo "URL final después del POST: $finalUrl\n";

// Heurística: si redirige a dashboard.php, login exitoso
if (strpos($finalUrl, 'dashboard.php') !== false) {
    echo "Login exitoso (redirigido a dashboard).\n";
} else {
    // Mostrar una parte de la respuesta para debug
    echo "Login probablemente falló. Fragmento de respuesta:\n";
    echo substr(strip_tags($response), 0, 800) . "\n";
}

// Limpieza opcional: borrar cookies
// unlink($cookieFile);

?>