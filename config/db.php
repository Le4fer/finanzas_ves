<?php
// config/db.php - lee credenciales desde variables de entorno o usa valores por defecto
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'finanzas_personales';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Si estamos en CLI o en entorno de desarrollo, mostrar el error detallado para depuración
    $appEnv = getenv('APP_ENV') ?: '';
    if (php_sapi_name() === 'cli' || strtolower($appEnv) === 'development') {
        // Mostrar mensaje detallado en CLI o en desarrollo
        die("Conexión fallida: " . $e->getMessage());
    } else {
        // Mensaje genérico en producción
        die('Conexión fallida a la base de datos.');
    }
}
?>