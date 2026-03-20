<?php
// Evitar redeclaración de funciones
if (!function_exists('hasPermission')) {

// Configuración de roles y permisos
define('ROLE_ADMIN', 'admin');
define('ROLE_USER', 'user');

// Función para verificar permisos
function hasPermission($requiredRole = ROLE_USER) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $userRole = $_SESSION['user_role'];
    
    // Los administradores tienen acceso a todo
    if ($userRole === ROLE_ADMIN) {
        return true;
    }
    
    // Para usuarios normales, solo pueden acceder a lo suyo
    return $userRole === $requiredRole;
}

// Función para verificar si es administrador
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_ADMIN;
}

// Middleware para proteger rutas
function requireRole($requiredRole = ROLE_USER) {
    if (!hasPermission($requiredRole)) {
        header('HTTP/1.0 403 Forbidden');
        echo "<div class='container mt-5'>
                <div class='alert alert-danger text-center'>
                    <h4><i class='fas fa-ban me-2'></i>Acceso Denegado</h4>
                    <p>No tienes permisos suficientes para acceder a esta página.</p>
                    <a href='dashboard.php' class='btn btn-primary'>Volver al Dashboard</a>
                </div>
              </div>";
        exit;
    }
}

// Función para registrar actividad de sesión
function updateSessionActivity($user_id, $session_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE active_sessions SET last_activity = NOW() WHERE user_id = ? AND session_id = ?");
        $stmt->execute([$user_id, $session_id]);
    } catch (Exception $e) {
        error_log("Error actualizando actividad de sesión: " . $e->getMessage());
    }
}

} // Cierre del if !function_exists
?>