<?php
session_start();
require 'config/db.php';
// CSRF helper
require_once __DIR__ . '/config/csrf.php';

// Verificar que el usuario está logueado y que debe cambiar la contraseña
if (!isset($_SESSION['user_id']) || !isset($_SESSION['force_password_change'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    validate_csrf();
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Por favor, completa todos los campos.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($new_password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        // Actualizar la contraseña y quitar la marca de cambio de contraseña
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $user_id = $_SESSION['user_id'];

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        if ($stmt->execute([$hashed_password, $user_id])) {
            // Eliminar la variable de sesión que fuerza el cambio de contraseña
            unset($_SESSION['force_password_change']);
            $success = 'Contraseña cambiada exitosamente. Serás redirigido al dashboard.';
            // Redirigir después de 2 segundos
            header('Refresh: 2; URL=dashboard.php');
        } else {
            $error = 'Error al cambiar la contraseña. Intenta de nuevo.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambio de Contraseña Obligatorio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header text-center">
                        <h4><i class="fas fa-key me-2"></i>Cambio de Contraseña Obligatorio</h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Por seguridad, debes cambiar tu contraseña antes de acceder al sistema.
                        </p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Nueva Contraseña</label>
                                <input type="password" class="form-control" id="new_password" name="new_password"
                                    required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirmar Nueva Contraseña</label>
                                <input type="password" class="form-control" id="confirm_password"
                                    name="confirm_password" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Cambiar Contraseña</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>