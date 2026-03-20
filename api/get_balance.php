<?php
session_start();
require '../config/db.php';
require '../config/helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Recalcular balance en tiempo real
$balance_data = recalculateUserBalance($pdo, $user_id);

echo json_encode([
    'success' => true,
    'current_balance' => $balance_data['current_balance'],
    'available_for_spending' => $balance_data['available_for_spending'],
    'total_incomes' => $balance_data['total_incomes'],
    'total_expenses' => $balance_data['total_expenses']
]);
?>