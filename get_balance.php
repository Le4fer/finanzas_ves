<?php
session_start();
require 'config/db.php';

header('Content-Type: application/json');

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Usuario no especificado']);
    exit();
}

$user_id = intval($_GET['user_id']);

try {
    $stmt = $pdo->prepare("SELECT current_balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_balance = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'balance' => floatval($current_balance)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>