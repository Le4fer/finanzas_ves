<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    // RUTA MODIFICADA: Redirección a la raíz
    header('Location: /index.php');
    exit();
}

// Incluir la base de datos y roles UNA SOLA VEZ
// RUTA MODIFICADA: Usamos ../ para subir de archivos_php/ a la raíz
require_once './config/db.php';
require_once './config/roles.php';

// Actualizar última actividad de la sesión
if (isset($_SESSION['user_id']) && isset($_SESSION['session_id'])) {
    // Asumimos que updateSessionActivity es una función definida en otro lugar 
    // (posiblemente en db.php o un archivo de utilidades incluido).
    updateSessionActivity($_SESSION['user_id'], $_SESSION['session_id']);
}

// Verificar que el usuario aún existe y está activo
try {
    $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        // Usuario no existe o está inactivo
        session_destroy();
        // RUTA MODIFICADA: Redirección a la raíz
        header('Location: /index.php?error=user_inactive');
        exit();
    }

    // Actualizar información de sesión si es necesario
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];

} catch (Exception $e) {
    error_log("Error en auth.php: " . $e->getMessage());
}
?>