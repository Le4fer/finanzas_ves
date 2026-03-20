<?php
// Registro de usuario separado de login
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require 'config/db.php';
require_once 'config/csrf.php';
require_once 'config/mailer.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (empty($name) || empty($email) || empty($password) || empty($confirm)) {
        $error = 'Por favor completa todos los campos del registro.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido.';
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Ya existe un usuario con ese correo.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            try {
                $verification_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } catch (Exception $e) {
                $verification_code = substr(bin2hex(random_bytes(16)), 0, 6);
            }

            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_active, must_change_password, is_verified, verification_code, created_at) VALUES (?, ?, ?, 'user', 1, 1, 0, ?, NOW())");
            try {
                $stmt->execute([$name, $email, $hashed, $verification_code]);
                $sent = sendVerificationEmail($email, $verification_code);
                if ($sent) {
                    $success = 'Registro completado. Te hemos enviado un correo con el código de verificación.';
                } else {
                    $success = 'Registro completado correctamente. No pudimos enviar el email de verificación automáticamente; por favor contacta al administrador.';
                }
            } catch (Exception $e) {
                $error = 'Error al crear el usuario: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Registro - Finanzas Personales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .register-card {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.25);
            padding: 2.5rem;
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-control:focus {
            border-color: #0f172a;
            box-shadow: 0 0 0 0.2rem rgba(15, 23, 42, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
            border: none;
        }

        .btn-outline-primary {
            color: #0f172a;
            border-color: #0f172a;
        }

        .btn-outline-primary:hover {
            background: #0f172a;
            color: white;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="register-card">
                    <div class="register-header">
                        <h2><i class="fas fa-user-plus me-2"></i>Crear Cuenta</h2>
                        <p class="text-muted">Separate login y registro</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrf_input() ?>

                        <div class="mb-3">
                            <label class="form-label">Nombre completo</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Correo electrónico</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" minlength="6" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Confirmar contraseña</label>
                            <input type="password" name="confirm" class="form-control" minlength="6" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mb-2">Registrarme</button>
                        <a href="index.php" class="btn btn-outline-primary w-100">Volver al login</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>

</html>