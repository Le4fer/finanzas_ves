<?php
// update_balance.php
function updateUserBalance($user_id, $pdo)
{
    // Recalcular balance basado en todos los registros (en Bs)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_incomes = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_expenses = $stmt->fetchColumn();

    $current_balance = $total_incomes - $total_expenses;

    // Actualizar el balance en la base de datos
    $stmt = $pdo->prepare("UPDATE users SET current_balance = ? WHERE id = ?");
    $stmt->execute([$current_balance, $user_id]);

    return $current_balance;
}

function updateBalanceOnExpense($user_id, $amount, $pdo)
{
    // Obtener balance actual (en Bs)
    $stmt = $pdo->prepare("SELECT current_balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_balance = $stmt->fetchColumn();

    // Restar el nuevo gasto (en Bs)
    $new_balance = $current_balance - $amount;

    // Actualizar en la base de datos
    $stmt = $pdo->prepare("UPDATE users SET current_balance = ? WHERE id = ?");
    $stmt->execute([$new_balance, $user_id]);

    return $new_balance;
}

function updateBalanceOnIncome($user_id, $amount, $pdo)
{
    // Obtener balance actual (en Bs)
    $stmt = $pdo->prepare("SELECT current_balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_balance = $stmt->fetchColumn();

    // Sumar el nuevo ingreso (en Bs)
    $new_balance = $current_balance + $amount;

    // Actualizar en la base de datos
    $stmt = $pdo->prepare("UPDATE users SET current_balance = ? WHERE id = ?");
    $stmt->execute([$new_balance, $user_id]);

    return $new_balance;
}
?>