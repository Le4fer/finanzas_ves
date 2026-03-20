<?php
require 'includes/header.php';
require_once 'config/roles.php';
require 'config/db.php';
require 'check_password_change.php'; // ✅ AGREGAR ESTA LÍNEA

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
?>

<style>
    .full-height-settings {
        min-height: calc(100vh - 120px);
        background: #f8f9fa;
        padding: 20px;
    }

    .settings-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }

    .settings-card {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        height: 100%;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: none;
    }

    .settings-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .settings-card .card-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
        opacity: 0.8;
    }

    .settings-card .card-title {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 10px;
        color: #2c3e50;
    }

    .settings-card .card-description {
        color: #6c757d;
        margin-bottom: 20px;
        line-height: 1.5;
    }

    .security-card {
        grid-column: 1 / -1;
        background: white;
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
    }

    .security-section {
        margin-bottom: 25px;
    }

    .security-section:last-child {
        margin-bottom: 0;
    }

    .security-section h5 {
        color: #2c3e50;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 10px;
    }

    .security-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .security-list li {
        padding: 8px 0;
        border-bottom: 1px solid #f8f9fa;
        display: flex;
        align-items: center;
    }

    .security-list li:before {
        content: "✓";
        color: #28a745;
        font-weight: bold;
        margin-right: 10px;
    }

    .security-list li:last-child {
        border-bottom: none;
    }

    .admin-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 25px;
        text-align: center;
    }

    .admin-header h1 {
        margin-bottom: 10px;
        font-weight: 700;
    }

    .admin-header p {
        opacity: 0.9;
        margin-bottom: 0;
    }

    .btn-settings {
        padding: 12px 25px;
        border-radius: 10px;
        font-weight: 500;
        transition: all 0.3s ease;
        border: none;
    }

    .btn-settings-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .btn-settings-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        color: white;
    }

    .btn-settings-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-settings-secondary:hover {
        background: #5a6268;
        color: white;
        transform: translateY(-2px);
    }

    .settings-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        background: #ff6b6b;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .settings-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .settings-grid {
            grid-template-columns: 1fr;
        }

        .full-height-settings {
            padding: 15px;
        }

        .settings-card {
            padding: 20px;
        }
    }
</style>

<div class="full-height-settings">
    <!-- Header de Administración -->
    <div class="admin-header">
        <h1><i class="fas fa-crown me-3"></i>Panel de Administración</h1>
        <p>Gestión completa del sistema y configuración avanzada</p>
    </div>

    <!-- Grid de Configuraciones -->
    <div class="settings-grid">
        <!-- Gestión de Usuarios -->
        <div class="settings-card">
            <div class="card-icon text-primary">
                <i class="fas fa-users-cog"></i>
            </div>
            <h3 class="card-title">Gestión de Usuarios</h3>
            <p class="card-description">
                Administra todos los usuarios del sistema, sus permisos, estados y accesos.
                Crea nuevos usuarios, modifica roles y gestiona contraseñas.
            </p>
            <a href="user_management.php" class="btn btn-settings btn-settings-primary w-100">
                <i class="fas fa-users me-2"></i>Gestionar Usuarios
            </a>
        </div>

        <!-- Tasa de Cambio -->
        <div class="settings-card">
            <div class="card-icon text-success">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <h3 class="card-title">Tasa de Cambio</h3>
            <p class="card-description">
                Configuración de APIs para tasas de cambio automáticas.
                Monitorea y ajusta las fuentes de datos para conversiones monetarias.
            </p>
            <button class="btn btn-settings btn-settings-secondary w-100" disabled>
                <i class="fas fa-cog me-2"></i>Configurar (Próximamente)
            </button>
            <span class="settings-badge">Próximamente</span>
        </div>

        <!-- Respaldos -->
        <div class="settings-card">
            <div class="card-icon text-info">
                <i class="fas fa-database"></i>
            </div>
            <h3 class="card-title">Sistema de Respaldos</h3>
            <p class="card-description">
                Realiza respaldos completos de la base de datos.
                Programa respaldos automáticos y gestiona puntos de restauración.
            </p>
            <button class="btn btn-settings btn-settings-secondary w-100" disabled>
                <i class="fas fa-download me-2"></i>Respaldos (Próximamente)
            </button>
            <span class="settings-badge">Próximamente</span>
        </div>
    </div>

    <!-- Panel de Seguridad -->
    <div class="security-card">
        <div class="text-center mb-4">
            <h2 class="text-dark"><i class="fas fa-shield-alt me-2"></i>Configuración de Seguridad</h2>
            <p class="text-muted">Políticas y configuraciones de seguridad del sistema</p>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="security-section">
                    <h5><i class="fas fa-key me-2 text-warning"></i>Políticas de Contraseñas</h5>
                    <ul class="security-list">
                        <li>Longitud mínima: 6 caracteres</li>
                        <li>Los usuarios deben cambiar su contraseña en el primer acceso</li>
                        <li>Solo administradores pueden restablecer contraseñas</li>
                        <li>Encriptación segura con bcrypt</li>
                        <li>Verificación de identidad para operaciones sensibles</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-section">
                    <h5><i class="fas fa-user-lock me-2 text-info"></i>Control de Accesos</h5>
                    <ul class="security-list">
                        <li>Sesiones automáticas después de 30 minutos de inactividad</li>
                        <li>Registro de sesiones activas en tiempo real</li>
                        <li>Control de roles y permisos granulares</li>
                        <li>Acceso restringido por IP (próximamente)</li>
                        <li>Auditoría de actividades de usuarios</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="security-section">
                    <h5><i class="fas fa-chart-line me-2 text-success"></i>Estadísticas del Sistema</h5>
                    <div class="row text-center">
                        <div class="col-md-3 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h4 class="text-primary mb-1"><?= date('H:i') ?></h4>
                                <small class="text-muted">Hora del Sistema</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h4 class="text-success mb-1"><?= phpversion() ?></h4>
                                <small class="text-muted">Versión PHP</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h4 class="text-info mb-1">Online</h4>
                                <small class="text-muted">Estado del Sistema</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h4 class="text-warning mb-1">100%</h4>
                                <small class="text-muted">Disponibilidad</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="row">
        <div class="col-12">
            <div class="settings-card">
                <h5 class="card-title mb-4"><i class="fas fa-bolt me-2 text-warning"></i>Acciones Rápidas</h5>
                <div class="row text-center">
                    <div class="col-md-2 mb-3">
                        <a href="dashboard.php" class="btn btn-outline-primary w-100 py-3">
                            <i class="fas fa-tachometer-alt fa-2x mb-2"></i><br>
                            Dashboard
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="user_management.php" class="btn btn-outline-success w-100 py-3">
                            <i class="fas fa-users fa-2x mb-2"></i><br>
                            Usuarios
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="system_logs.php" class="btn btn-outline-info w-100 py-3">
                            <i class="fas fa-clipboard-list fa-2x mb-2"></i><br>
                            Registros
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <button class="btn btn-outline-warning w-100 py-3" disabled>
                            <i class="fas fa-cogs fa-2x mb-2"></i><br>
                            Ajustes Avanzados
                        </button>
                    </div>
                    <div class="col-md-2 mb-3">
                        <button class="btn btn-outline-danger w-100 py-3" disabled>
                            <i class="fas fa-database fa-2x mb-2"></i><br>
                            Respaldos
                        </button>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="logout.php" class="btn btn-outline-dark w-100 py-3">
                            <i class="fas fa-sign-out-alt fa-2x mb-2"></i><br>
                            Cerrar Sesión
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Efectos de hover mejorados para las tarjetas
    document.addEventListener('DOMContentLoaded', function () {
        const cards = document.querySelectorAll('.settings-card');

        cards.forEach(card => {
            card.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-8px)';
                this.style.boxShadow = '0 12px 30px rgba(0,0,0,0.15)';
            });

            card.addEventListener('mouseleave', function () {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.05)';
            });
        });

        // Mostrar notificación para funciones próximamente
        const upcomingButtons = document.querySelectorAll('button:disabled');
        upcomingButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                showNotification('Esta función estará disponible próximamente', 'info');
            });
        });
    });

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

    // Actualizar la hora del sistema cada minuto
    function updateSystemTime() {
        const now = new Date();
        const timeElement = document.querySelector('.text-primary.mb-1');
        if (timeElement) {
            timeElement.textContent = now.toLocaleTimeString('es-VE', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
        }
    }

    setInterval(updateSystemTime, 60000);
</script>

<?php require 'includes/footer.php'; ?>