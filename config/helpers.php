<?php
function recalculateUserBalance($pdo, $user_id) {
    // Calcular totales en tiempo real
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_incomes = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_expenses = $stmt->fetchColumn();

    $current_balance = $total_incomes - $total_expenses;
    $available_for_spending = max(0, $current_balance);

    // Actualizar base de datos para consistencia
    $stmt = $pdo->prepare("UPDATE users SET current_balance = ?, available_for_spending = ? WHERE id = ?");
    $stmt->execute([$current_balance, $available_for_spending, $user_id]);

    return [
        'current_balance' => $current_balance,
        'available_for_spending' => $available_for_spending,
        'total_incomes' => $total_incomes,
        'total_expenses' => $total_expenses
    ];
}
?>