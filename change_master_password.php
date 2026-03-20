<?php
require 'includes/header.php';

if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    echo "<div class='container mt-5'>
            <div class='alert alert-danger text-center'>
                <h4><i class='fas fa-ban me-2'></i>Acceso Denegado</h4>
                <p>No tienes permisos de administrador para acceder a esta página.</p>
                <a href='dashboard.php' class='btn btn-primary'>Volver al Dashboard</a>
            </div>
          </div>";
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    validate_csrf();
    $current_master_password = $_POST['current_master_password'];
    $new_master_password = $_POST['new_master_password'];
    $confirm_master_password = $_POST['confirm_master_password'];

    // Obtener la contraseña maestra actual
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_master_password'");
    $stmt->execute();
    $current_master_hash = $stmt->fetchColumn();

    if (password_verify($current_master_password, $current_master_hash)) {
        if ($new_master_password === $confirm_master_password) {
            $new_master_hash = password_hash($new_master_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'admin_master_password'");
            $stmt->execute([$new_master_hash]);
            $success = "Contraseña maestra cambiada exitosamente";
        } else {
            $error = "Las nuevas contraseñas no coinciden";
        }
    } else {
        $error = "Contraseña maestra actual incorrecta";
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Cambiar Contraseña Maestra</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrf_input() ?>
                        <div class="mb-3">
                            <label class="form-label">Contraseña Maestra Actual</label>
                            <input type="password" name="current_master_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña Maestra</label>
                            <input type="password" name="new_master_password" class="form-control" required
                                minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirmar Nueva Contraseña Maestra</label>
                            <input type="password" name="confirm_master_password" class="form-control" required
                                minlength="6">
                        </div>
                        <button type="submit" class="btn btn-primary">Cambiar Contraseña Maestra</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>