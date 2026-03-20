<?php
session_start();
require 'includes/header.php';
require_once 'config/roles.php';
require 'config/db.php';
require 'check_password_change.php';

// Verificar que el usuario es administrador
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

// Definir email del admin supremo
define('SUPER_ADMIN_EMAIL', 'oscarzbranoivan@gmail.com');
$current_user_email = $_SESSION['user_email'] ?? '';
$is_super_admin = ($current_user_email === SUPER_ADMIN_EMAIL);

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF (incluido en includes/header.php)
    validate_csrf();
    if (isset($_POST['create_user'])) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$name, $email, $password, $role]);
            $success = "Usuario creado exitosamente. El usuario deberá cambiar su contraseña en el primer acceso.";
        } catch (Exception $e) {
            $error = "Error al crear usuario: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_user'])) {
        $user_id = $_POST['user_id'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Verificar protección del admin supremo
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $target_user = $stmt->fetch();
        
        if ($target_user['email'] === SUPER_ADMIN_EMAIL && !$is_super_admin) {
            $error = "No tienes permisos para modificar al Administrador Supremo del sistema.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $email, $role, $is_active, $user_id]);
                $success = "Usuario actualizado exitosamente";
            } catch (Exception $e) {
                $error = "Error al actualizar usuario: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['reset_user_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        $admin_confirmation = $_POST['admin_confirmation'];

        // Verificar que la contraseña de confirmación del admin sea correcta
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $admin = $stmt->fetch();

        if (password_verify($admin_confirmation, $admin['password_hash'])) {
            // Verificar permisos para restablecer contraseña
            $stmt = $pdo->prepare("SELECT role, email FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $target_user = $stmt->fetch();
            
            // Solo el super admin puede restablecer contraseñas de otros administradores
            if ($target_user['role'] === 'admin' && $target_user['email'] !== SUPER_ADMIN_EMAIL && !$is_super_admin) {
                $error = "Solo el Administrador Supremo puede restablecer contraseñas de otros administradores.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                $success = "Contraseña restablecida exitosamente. El usuario deberá cambiarla en su próximo acceso.";
            }
        } else {
            $error = "Contraseña de administrador incorrecta";
        }
    } elseif (isset($_POST['delete_user'])) {
        $user_id = $_POST['user_id'];
        
        // Verificar que el usuario actual sea super admin
        if (!$is_super_admin) {
            $error = "Solo el Administrador Supremo puede eliminar usuarios del sistema.";
        } else {
            // Verificar que no se intente eliminar al super admin
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $target_user = $stmt->fetch();
            
            if ($target_user['email'] === SUPER_ADMIN_EMAIL) {
                $error = "No puedes eliminar tu propia cuenta de Administrador Supremo.";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $success = "Usuario eliminado exitosamente del sistema";
                } catch (Exception $e) {
                    $error = "Error al eliminar usuario: " . $e->getMessage();
                }
            }
        }
    }
}

// Obtener usuarios
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();

// Estadísticas para las tarjetas
$total_users = count($users);
$active_users = count(array_filter($users, fn($u) => $u['is_active']));
$admin_users = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$inactive_users = count(array_filter($users, fn($u) => !$u['is_active']));
?>

<!-- TODO EL CSS SE MANTIENE EXACTAMENTE IGUAL -->
<style>
    .full-height-management {
        min-height: calc(100vh - 80px);
        padding: 0;
    }

    .management-container {
        display: flex;
        flex-direction: column;
        height: 100%;
        gap: 20px;
        padding: 20px;
    }

    .management-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 0;
    }

    .management-header h1 {
        margin-bottom: 10px;
        font-weight: 700;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 0;
    }

    .stat-card {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: none;
        height: 150px;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 5px;
        line-height: 1;
    }

    .stat-label {
        font-size: 0.9rem;
        color: #6c757d;
        font-weight: 500;
    }

    .stat-icon {
        font-size: 2rem;
        margin-bottom: 15px;
        opacity: 0.7;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 20px;
        flex: 1;
        min-height: 550px;
    }

    .quick-actions-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
    }

    .users-table-container {
        background: white;
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .section-title {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 25px;
        color: #2c3e50;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 15px;
    }

    .user-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 1.1rem;
    }

    .user-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }

    .status-active {
        background: #28a745;
    }

    .status-inactive {
        background: #dc3545;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn-action {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        transition: all 0.3s ease;
    }

    .btn-action-sm {
        padding: 4px 8px;
        font-size: 0.75rem;
    }

    .security-notice {
        border-left: 4px solid #dc3545;
        background: #fff5f5;
        padding: 15px;
        border-radius: 8px;
    }

    .table-responsive {
        flex: 1;
        overflow: auto;
    }

    .table thead th {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 15px 12px;
        font-weight: 600;
    }

    .table tbody td {
        padding: 15px 12px;
        vertical-align: middle;
        border-bottom: 1px solid #f0f0f0;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 15px;
        flex: 1;
    }

    .quick-action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        border: 2px solid #f8f9fa;
        border-radius: 10px;
        transition: all 0.3s ease;
        text-decoration: none;
        color: #495057;
        background: white;
    }

    .quick-action-btn:hover {
        border-color: #667eea;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        color: #495057;
        text-decoration: none;
    }

    .quick-action-btn i {
        font-size: 2rem;
        margin-bottom: 10px;
        color: #667eea;
    }

    /* Badges personalizados */
    .badge-admin {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
    }

    .badge-user {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    }

    /* Modal styles consistentes con dashboard */
    .modal-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px 15px 0 0;
    }

    .modal-content {
        border-radius: 15px;
        border: none;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    /* Scroll personalizado */
    .scrollable-table {
        flex: 1;
        overflow-y: auto;
    }

    .scrollable-table .table {
        margin-bottom: 0;
    }

    .scrollable-table::-webkit-scrollbar {
        width: 6px;
    }

    .scrollable-table::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .scrollable-table::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .scrollable-table::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .management-container {
            padding: 15px;
            gap: 15px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .management-header {
            padding: 20px;
        }

        .users-table-container {
            padding: 20px;
        }

        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (min-width: 1400px) {
        .stat-card {
            height: 160px;
        }

        .content-grid {
            min-height: 600px;
        }
    }

    /* ✅ NUEVO: Ajustes para aumentar la altura general */
    .main-content {
        margin-left: 250px;
        min-height: 100vh;
        background-color: #f8f9fa;
        width: calc(100% - 250px);
    }

    body {
        overflow-x: hidden;
    }
</style>

<div class="full-height-management">
    <div class="management-container">
        <!-- Header -->
        <div class="management-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-users-cog me-3"></i>Gestión de Usuarios</h1>
                    <p class="mb-0">Administra todos los usuarios del sistema y sus permisos</p>
                    <?php if ($is_super_admin): ?>
                        <div class="mt-2">
                            <span class="badge bg-warning fs-6">
                                <i class="fas fa-crown me-1"></i>Administrador Supremo
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-user-plus me-2"></i>Crear Nuevo Usuario
                </button>
            </div>
        </div>

        <!-- Alertas -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon text-primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number text-primary"><?= $total_users ?></div>
                <div class="stat-label">Total Usuarios</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon text-success">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number text-success"><?= $active_users ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon text-danger">
                    <i class="fas fa-crown"></i>
                </div>
                <div class="stat-number text-danger"><?= $admin_users ?></div>
                <div class="stat-label">Administradores</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon text-warning">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-number text-warning"><?= $inactive_users ?></div>
                <div class="stat-label">Usuarios Inactivos</div>
            </div>
        </div>

        <!-- Contenido Principal -->
        <div class="content-grid">
            <!-- Acciones Rápidas -->
            <div class="quick-actions-container">
                <h5 class="section-title"><i class="fas fa-bolt me-2 text-warning"></i>Acciones Rápidas</h5>
                <div class="quick-actions-grid">
                    <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        <i class="fas fa-user-plus"></i>
                        <span>Nuevo Usuario</span>
                    </button>

                    <a href="settings.php" class="quick-action-btn">
                        <i class="fas fa-cog"></i>
                        <span>Ajustes Sistema</span>
                    </a>

                    <a href="dashboard.php" class="quick-action-btn">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Volver al Dashboard</span>
                    </a>
                </div>
            </div>

            <!-- Lista de Usuarios -->
            <div class="users-table-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="section-title mb-0"><i class="fas fa-list me-2"></i>Lista de Usuarios del Sistema</h5>
                    <span class="badge bg-primary fs-6"><?= $total_users ?> usuarios</span>
                </div>

                <div class="scrollable-table">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Información</th>
                                <th>Estado</th>
                                <th>Último Acceso</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                $is_current_user = ($user['id'] == $_SESSION['user_id']);
                                $is_super_admin_user = ($user['email'] === SUPER_ADMIN_EMAIL);
                                $can_delete_user = ($is_super_admin && !$is_super_admin_user && !$is_current_user);
                                $can_reset_password = ($is_super_admin || $user['role'] !== 'admin' || $is_current_user);
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-3">
                                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($user['name']) ?></strong>
                                                <div class="text-muted small"><?= htmlspecialchars($user['email']) ?></div>
                                                <?php if ($is_super_admin_user): ?>
                                                    <span class="badge bg-warning mt-1">
                                                        <i class="fas fa-crown me-1"></i>Admin Supremo
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <span
                                                class="badge badge-<?= $user['role'] === 'admin' ? 'admin' : 'user' ?> mb-1">
                                                <?= $user['role'] === 'admin' ? 'Administrador' : 'Usuario Normal' ?>
                                            </span>
                                            <div class="text-muted small">
                                                <i class="fas fa-calendar me-1"></i>
                                                Creado: <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-status">
                                            <span
                                                class="status-dot <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                                            <span class="fw-medium"><?= $user['is_active'] ? 'Activo' : 'Inactivo' ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <div class="text-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <?= date('d/m/Y H:i', strtotime($user['last_login'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted">
                                                <i class="fas fa-times-circle me-1"></i>
                                                Nunca ha accedido
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-action btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#editUserModal" data-userid="<?= $user['id'] ?>"
                                                data-name="<?= htmlspecialchars($user['name']) ?>"
                                                data-email="<?= htmlspecialchars($user['email']) ?>"
                                                data-role="<?= $user['role'] ?>" data-active="<?= $user['is_active'] ?>"
                                                <?= ($is_super_admin_user && !$is_super_admin) ? 'disabled' : '' ?>>
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            
                                            <button class="btn btn-action btn-outline-warning" data-bs-toggle="modal"
                                                data-bs-target="#resetPasswordModal" data-userid="<?= $user['id'] ?>"
                                                data-name="<?= htmlspecialchars($user['name']) ?>"
                                                <?= (!$can_reset_password) ? 'disabled' : '' ?>>
                                                <i class="fas fa-key"></i> Contraseña
                                            </button>
                                            
                                            <?php if ($user['id'] != $_SESSION['user_id'] && !$is_super_admin_user): ?>
                                                <button
                                                    class="btn btn-action btn-<?= $user['is_active'] ? 'outline-danger' : 'outline-success' ?>"
                                                    onclick="toggleUserStatus(<?= $user['id'] ?>, <?= $user['is_active'] ?>)">
                                                    <i class="fas fa-<?= $user['is_active'] ? 'ban' : 'check' ?>"></i>
                                                    <?= $user['is_active'] ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            <?php elseif ($is_super_admin_user): ?>
                                                <span class="badge bg-warning">Protegido</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Tú</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_user): ?>
                                                <form method="POST" style="display: inline;">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" name="delete_user" class="btn btn-action btn-outline-danger" 
                                                        onclick="return confirm('¿Estás seguro de que quieres ELIMINAR permanentemente a <?= htmlspecialchars($user['name']) ?>? Esta acción no se puede deshacer.')">
                                                        <i class="fas fa-trash"></i> Eliminar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Los modales se mantienen exactamente igual -->
<!-- Modal: Crear Usuario -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_input() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nombre Completo</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Contraseña Temporal</label>
                                <input type="password" name="password" class="form-control" required minlength="6">
                                <small class="text-muted">El usuario deberá cambiar esta contraseña en su primer
                                    acceso</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Rol del Usuario</label>
                                <select name="role" class="form-select" required>
                                    <option value="user">Usuario Normal</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Información:</strong> El usuario recibirá una contraseña temporal y deberá
                        <strong>cambiarla obligatoriamente</strong> en su primer acceso al sistema.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="create_user" class="btn btn-primary">Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Usuario -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_input() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="mb-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rol</label>
                        <select name="role" id="edit_role" class="form-select">
                            <option value="user">Usuario Normal</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_active" value="1">
                        <label class="form-check-label" for="edit_active">Usuario activo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="update_user" class="btn btn-warning">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Restablecer Contraseña -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_input() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Restablecer Contraseña</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="reset_user_id">

                    <div class="alert alert-warning security-notice">
                        <i class="fas fa-shield-alt me-2"></i>
                        <strong>Procedimiento de Seguridad:</strong> Esta acción requiere verificación de identidad.
                    </div>

                    <p>Estás a punto de restablecer la contraseña para: <strong id="reset_user_name"></strong></p>

                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                        <small class="text-muted">La nueva contraseña que el usuario usará para acceder</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirmación de Administrador</label>
                        <input type="password" name="admin_confirmation" class="form-control" required>
                        <small class="text-muted">Ingresa TU contraseña de administrador para autorizar este
                            cambio</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="reset_user_password" class="btn btn-danger">Restablecer
                        Contraseña</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Script para los modales
    document.addEventListener('DOMContentLoaded', function () {
        // Modal de edición
        const editModal = document.getElementById('editUserModal');
        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('edit_user_id').value = button.getAttribute('data-userid');
            document.getElementById('edit_name').value = button.getAttribute('data-name');
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            document.getElementById('edit_role').value = button.getAttribute('data-role');
            document.getElementById('edit_active').checked = button.getAttribute('data-active') === '1';
        });

        // Modal de restablecimiento
        const resetModal = document.getElementById('resetPasswordModal');
        resetModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('reset_user_id').value = button.getAttribute('data-userid');
            document.getElementById('reset_user_name').textContent = button.getAttribute('data-name');
        });

        // Efectos hover para tarjetas de estadísticas
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            card.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-8px)';
                this.style.boxShadow = '0 12px 30px rgba(0,0,0,0.15)';
            });

            card.addEventListener('mouseleave', function () {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.05)';
            });
        });
    });

    // Función para activar/desactivar usuario
    function toggleUserStatus(userId, isCurrentlyActive) {
        const action = isCurrentlyActive ? 'desactivar' : 'activar';
        const actionText = isCurrentlyActive ? 'desactivación' : 'activación';

        if (confirm(`¿Estás seguro de que quieres ${action} este usuario?\n\nEl usuario ${isCurrentlyActive ? 'perderá acceso inmediato al sistema' : 'podrá acceder al sistema nuevamente'}.`)) {
            fetch('api/toggle_user_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user_id=${userId}&action=${isCurrentlyActive ? 'deactivate' : 'activate'}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`Usuario ${action === 'activar' ? 'activado' : 'desactivado'} exitosamente`, 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Error: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error al cambiar el estado del usuario', 'error');
                });
        }
    }

    // Función para mostrar notificaciones
    function showNotification(message, type) {
        // Crear elemento de notificación
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show`;
        notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
    `;
        notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

        document.body.appendChild(notification);

        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }

    // Búsqueda en tiempo real (puede ser implementada posteriormente)
    function setupSearch() {
        const searchInput = document.getElementById('userSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function (e) {
                const searchTerm = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
    }

    // Inicializar búsqueda si existe el campo
    setupSearch();
</script>

<?php require 'includes/footer.php'; ?>