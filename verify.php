<?php
// verify.php
session_start();
// RUTA MODIFICADA: Subimos un nivel (../) para encontrar config/db.php
require './config/db.php';
// CSRF helper
require_once __DIR__ . '/config/csrf.php';

$error = '';
$success = '';

// Obtener el ID del usuario desde la URL
$userId = $_GET['user_id'] ?? null;

if (!$userId || !is_numeric($userId)) {
    // RUTA MODIFICADA: Redirige a la raíz del sitio, donde está index.php
    header('Location: /index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    validate_csrf();
    // Sanitizamos la entrada del código para prevenir ataques básicos
    $submittedCode = filter_var($_POST['code'], FILTER_SANITIZE_STRING);

    // 1. Buscar el usuario, el código guardado y que NO esté verificado
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND verification_code = ? AND is_verified = 0");
    $stmt->execute([$userId, $submittedCode]);
    $user = $stmt->fetch();

    if ($user) {
        // 2. Si el código coincide, actualizar el estado
        // Limpiamos el código de verificación al verificar la cuenta
        $updateStmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_code = NULL WHERE id = ?");
        $updateStmt->execute([$userId]);

        $success = "¡Cuenta verificada exitosamente, compadre! Serás redirigido para iniciar sesión.";
        // RUTA MODIFICADA: Redirecciona a la raíz del sitio, donde está index.php
        header('Refresh: 5; URL=/index.php?verified=true');

    } else {
        $error = "Código de verificación incorrecto o expirado. Asegúrate de haber ingresado el código de 6 dígitos correcto.";
    }
}

// Si la verificación viene por link con código en GET, intentar verificar directamente
if (!empty($_GET['code'])) {
    $submittedCode = filter_var($_GET['code'], FILTER_SANITIZE_STRING);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND verification_code = ? AND is_verified = 0");
    $stmt->execute([$userId, $submittedCode]);
    $user = $stmt->fetch();
    if ($user) {
        $updateStmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_code = NULL WHERE id = ?");
        $updateStmt->execute([$userId]);
        $success = "¡Cuenta verificada exitosamente! Serás redirigido para iniciar sesión.";
        header('Refresh: 3; URL=/index.php?verified=true');
        // Mostrar la página con mensaje de éxito
    } else {
        $error = "Código de verificación inválido o expirado.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Verificación de Correo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card p-4 text-center" style="width: 100%; max-width: 400px;">
            <h3 class="mb-4">Verifica tu Correo 📧</h3>
            <p>Hemos enviado un código de 6 dígitos a tu dirección de correo.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php else: ?>
                <form method="POST">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <input type="text" name="code" class="form-control form-control-lg text-center"
                            placeholder="Ingresa el Código de 6 dígitos" required maxlength="6" pattern="\d{6}">
                    </div>
                    <button type="submit" class="btn btn-success w-100">Verificar Cuenta</button>
                </form>
            <?php endif; ?>

            <div class="mt-3">
                ¿No recibiste el código? <a href="/archivos_php/resend_code.php">Haz clic aquí para reenviar</a>.
            </div>
            <div class="mt-2">
                <a href="/index.php">Volver al Inicio de Sesión</a>
            </div>
        </div>
    </div>
</body>

</html>