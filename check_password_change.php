<?php
// check_password_change.php - Middleware para verificar cambio de contraseña
if (isset($_SESSION['user_id'])) {
    require 'config/db.php';

    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    // Si el usuario necesita cambiar contraseña y no está en la página de cambio
    if ($user && $user['must_change_password'] && basename($_SERVER['PHP_SELF']) !== 'change_password_required.php') {
        header('Location: change_password_required.php');
        exit;
    }
}
?>