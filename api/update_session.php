<?php
session_start();
require '../config/db.php';

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE active_sessions SET last_activity = NOW() WHERE user_id = ? AND session_id = ?");
    $stmt->execute([$_SESSION['user_id'], session_id()]);
}
echo json_encode(['success' => true]);
?>