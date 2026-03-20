<?php
session_start();
require 'config/db.php';
// CSRF helper
require_once __DIR__ . '/config/csrf.php';

// Verificar que el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Verificar si el usuario realmente necesita cambiar la contraseña
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || !$user['must_change_password']) {
    header('Location: dashboard.php');
    exit;
}

// Procesar el cambio de contraseña
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
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
        // Actualizar la contraseña y marcar que ya no necesita cambiarla
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        if ($stmt->execute([$hashed_password, $user_id])) {
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
    <title>Cambio de Contraseña Requerido</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .password-change-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .password-change-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
        }

        .security-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="password-change-container">
        <div class="password-change-card text-center">
            <div class="security-icon">
                <i class="fas fa-shield-alt"></i>
            </div>

            <h2 class="mb-3">Cambio de Contraseña Requerido</h2>
            <p class="text-muted mb-4">
                Por seguridad, debes cambiar tu contraseña antes de acceder al sistema.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <form method="POST" id="passwordForm">
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="new_password" class="form-label">Nueva Contraseña</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required
                        minlength="6" placeholder="Mínimo 6 caracteres">
                    <div class="form-text">La contraseña debe tener al menos 6 caracteres.</div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirmar Nueva Contraseña</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                        minlength="6" placeholder="Repite tu contraseña">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="fas fa-key me-2"></i>Cambiar Contraseña y Acceder
                </button>
            </form>

            <div class="mt-4">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Esta es una medida de seguridad para proteger tu cuenta.
                </small>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('passwordForm').addEventListener('submit', function (e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Las contraseñas no coinciden. Por favor, verifica.');
                return false;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('La contraseña debe tener al menos 6 caracteres.');
                return false;
            }
        });
    </script>
</body>

</html>