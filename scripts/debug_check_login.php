<?php
// scripts/debug_check_login.php
// Uso: php debug_check_login.php <email> <password>
if ($argc < 3) {
    echo "Uso: php debug_check_login.php <email> <password>\n";
    exit(1);
}

$email = $argv[1];
$password = $argv[2];

// Load .env for CLI if present
if (file_exists(__DIR__ . '/../config/load_env.php')) {
    require_once __DIR__ . '/../config/load_env.php';
}
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare('SELECT id, email, password_hash, is_active, must_change_password FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "Usuario con email '$email' no encontrado en la base de datos.\n";
    exit(1);
}

echo "Usuario encontrado: id={$user['id']}, email={$user['email']}, is_active={$user['is_active']}, must_change_password={$user['must_change_password']}\n";
$stored = $user['password_hash'];
echo "Password almacenado (hash): $stored\n";

$ok = password_verify($password, $stored);
if ($ok) {
    echo "password_verify => TRUE: la contraseña coincide con el hash almacenado.\n";
} else {
    echo "password_verify => FALSE: la contraseña NO coincide con el hash almacenado.\n";
    // Mostrar hash info
    $algo = password_get_info($stored);
    echo "Info del hash: algorithm=" . $algo['algoName'] . ", options=" . json_encode($algo['options']) . "\n";
}

// También comprobar si hay espacios invisibles en email
if ($email !== trim($user['email'])) {
    echo "Nota: el email guardado difiere de la entrada (posibles espacios). Guardado='{$user['email']}', entrada='$email'\n";
}

// Mostrar permisos y otras comprobaciones
// Comprobar si existe fila en active_sessions para este usuario (opcional)
$stmt2 = $pdo->prepare('SELECT COUNT(*) FROM active_sessions WHERE user_id = ?');
$stmt2->execute([$user['id']]);
$count = $stmt2->fetchColumn();
echo "Active sessions registradas para este usuario: $count\n";

?>