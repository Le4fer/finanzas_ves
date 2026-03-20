<?php
// Mejorar parámetros de cookie de sesión y seguridad
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    validate_csrf();

    // Proceso de login
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    // Buscar usuario activo por email (incluyendo must_change_password)
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, is_active, must_change_password FROM users WHERE email = ? AND is_active = TRUE");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Actualizar último login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Regenerar id de sesión para mitigar fijación de sesión
        session_regenerate_id(true);
        $newSessionId = session_id();

        // Registrar sesión activa usando el nuevo session_id
        $stmt = $pdo->prepare("INSERT INTO active_sessions (user_id, session_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $user['id'],
            $newSessionId,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);

        // Establecer variables de sesión
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['session_id'] = $newSessionId;

        // Verificar si debe cambiar contraseña
        if ($user['must_change_password']) {
            header('Location: change_password_required.php');
            exit();
        }

        header('Location: dashboard.php');
        exit();
    } else {
        $error = "Credenciales incorrectas o usuario inactivo.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Finanzas Personales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="login-card">
                    <div class="login-header">
                        <h3><i class="fas fa-wallet me-2"></i>Finanzas Personales</h3>
                        <p class="text-muted">Inicia sesión en tu cuenta</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrf_input() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Correo electrónico</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="tu@email.com"
                                    required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Contraseña"
                                    required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <p class="mb-2">¿No tienes cuenta?</p>
                        <a href="register.php" class="btn btn-outline-light w-100">Crear cuenta nueva</a>
                    </div>

                </div>
            </div>
        </div>
    </div>
    </div>
</body>

</html>