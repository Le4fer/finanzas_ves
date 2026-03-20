<?php
// config/csrf.php - helper mínimo para protección CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            // Fallback menos seguro si random_bytes no está disponible
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return $_SESSION['csrf_token'];
}

function csrf_input()
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function validate_csrf()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = $_POST['csrf_token'] ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($submitted) || empty($stored) || !hash_equals($stored, $submitted)) {
            // Responder con código 400 y terminar ejecución
            http_response_code(400);
            // Mensaje simple (se puede personalizar)
            die('Error: validación CSRF fallida. Si esto persiste, recarga la página y vuelve a intentar.');
        }
    }
}

?>