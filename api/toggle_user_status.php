<?php
session_start();
require '../config/db.php';
require '../config/roles.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $action = $_POST['action'];
    
    // Prevenir que el admin se desactive a sí mismo
    if ($user_id == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'No puedes desactivar tu propia cuenta']);
        exit;
    }
    
    try {
        $is_active = $action === 'activate' ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$is_active, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>