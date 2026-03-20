<?php
session_start();
// RUTA MODIFICADA: Ahora sube un nivel (../) para encontrar config/
require './config/db.php';

// Eliminar sesión activa de la base de datos si existe
if (isset($_SESSION['user_id']) && isset($_SESSION['session_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = ? AND session_id = ?");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['session_id']]);
    } catch (Exception $e) {
        // Si hay error, continuar con el logout de todas formas
        error_log("Error al eliminar sesión activa: " . $e->getMessage());
    }
}

session_destroy();
// RUTA MODIFICADA: Redirecciona a la raíz del sitio, donde está index.php
header('Location: index.php');
exit();
?>