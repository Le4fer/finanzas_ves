<?php
require 'includes/header.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    validate_csrf();
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Verificar contraseña actual
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (password_verify($current_password, $user['password_hash'])) {
        if ($new_password === $confirm_password) {
            // Actualizar contraseña
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $success = "Contraseña cambiada exitosamente";
        } else {
            $error = "Las nuevas contraseñas no coinciden";
        }
    } else {
        $error = "La contraseña actual es incorrecta";
    }
}
?>

<style>
    .password-strength {
        height: 5px;
        border-radius: 5px;
        margin-top: 5px;
        transition: all 0.3s;
    }

    .strength-weak {
        background: #dc3545;
        width: 25%;
    }

    .strength-medium {
        background: #ffc107;
        width: 50%;
    }

    .strength-strong {
        background: #28a745;
        width: 75%;
    }

    .strength-very-strong {
        background: #20c997;
        width: 100%;
    }
</style>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-key me-2"></i>Cambiar Contraseña</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <form method="POST" id="passwordForm">
                        <?= csrf_input() ?>
                        <div class="mb-3">
                            <label class="form-label">Contraseña Actual</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña</label>
                            <input type="password" name="new_password" id="new_password" class="form-control" required
                                minlength="6">
                            <div class="password-strength" id="passwordStrength"></div>
                            <small class="text-muted">
                                La contraseña debe tener al menos 6 caracteres. Recomendamos usar mayúsculas,
                                minúsculas, números y símbolos.
                            </small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirmar Nueva Contraseña</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                                required minlength="6">
                            <div class="mt-1" id="passwordMatch"></div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Recomendaciones de seguridad:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Usa una contraseña única que no uses en otros servicios</li>
                                <li>Combina letras mayúsculas, minúsculas, números y símbolos</li>
                                <li>Evita información personal fácil de adivinar</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-2"></i>Cambiar Contraseña.
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const passwordStrength = document.getElementById('passwordStrength');
        const passwordMatch = document.getElementById('passwordMatch');

        newPassword.addEventListener('input', function () {
            const password = this.value;
            let strength = 0;

            // Longitud
            if (password.length >= 6) strength += 1;
            if (password.length >= 8) strength += 1;

            // Complejidad
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 1;

            // Actualizar indicador visual
            passwordStrength.className = 'password-strength';
            if (password.length === 0) {
                passwordStrength.style.width = '0%';
            } else if (strength <= 2) {
                passwordStrength.className += ' strength-weak';
            } else if (strength <= 4) {
                passwordStrength.className += ' strength-medium';
            } else if (strength <= 5) {
                passwordStrength.className += ' strength-strong';
            } else {
                passwordStrength.className += ' strength-very-strong';
            }

            checkPasswordMatch();
        });

        confirmPassword.addEventListener('input', checkPasswordMatch);

        function checkPasswordMatch() {
            if (newPassword.value && confirmPassword.value) {
                if (newPassword.value === confirmPassword.value) {
                    passwordMatch.innerHTML = '<small class="text-success"><i class="fas fa-check me-1"></i>Las contraseñas coinciden</small>';
                } else {
                    passwordMatch.innerHTML = '<small class="text-danger"><i class="fas fa-times me-1"></i>Las contraseñas no coinciden</small>';
                }
            } else {
                passwordMatch.innerHTML = '';
            }
        }
    });
</script>

<?php require 'includes/footer.php'; ?>