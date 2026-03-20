<?php
// Iniciar sesión y procesar formularios ANTES de cualquier salida
session_start();
// CSRF helper
require_once __DIR__ . '/config/csrf.php';
require 'check_password_change.php'; // ✅ AGREGAR ESTA LÍNEA

// Procesar formularios de gestión de fondos AL PRINCIPIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    // Validar CSRF para acciones sensibles en el dashboard
    validate_csrf();
    require 'config/db.php';

    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'clear_incomes':
            // Eliminar todos los ingresos
            $stmt = $pdo->prepare("DELETE FROM incomes WHERE user_id = ?");
            $stmt->execute([$user_id]);

            // Recalcular saldo después de eliminar ingresos
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $total_expenses = $stmt->fetchColumn();

            $new_balance = -$total_expenses; // Si solo hay gastos, el balance es negativo
            $stmt = $pdo->prepare("UPDATE users SET current_balance = ?, available_for_spending = ? WHERE id = ?");
            $stmt->execute([$new_balance, -$total_expenses, $user_id]);

            header("Location: dashboard.php");
            exit;

        case 'clear_expenses':
            // Eliminar todos los gastos
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE user_id = ?");
            $stmt->execute([$user_id]);

            // Recalcular saldo después de eliminar gastos
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $total_incomes = $stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE users SET current_balance = ?, available_for_spending = ? WHERE id = ?");
            $stmt->execute([$total_incomes, $total_incomes, $user_id]);

            header("Location: dashboard.php");
            exit;

        case 'clear_all_financial':
            // Resetear todo el estado financiero
            $stmt = $pdo->prepare("UPDATE users SET current_balance = 0, available_for_spending = 0 WHERE id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM incomes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE user_id = ?");
            $stmt->execute([$user_id]);

            // También limpiar presupuestos si existen
            try {
                $stmt = $pdo->prepare("DELETE FROM budgets WHERE user_id = ?");
                $stmt->execute([$user_id]);
            } catch (Exception $e) {
                // Ignorar error si la tabla no existe
            }

            header("Location: dashboard.php");
            exit;

        case 'reset_system_completely':
            // RESETEAR TODO EL SISTEMA COMPLETAMENTE
            // Eliminar todos los registros del usuario
            $stmt = $pdo->prepare("DELETE FROM incomes WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE user_id = ?");
            $stmt->execute([$user_id]);

            // Resetear balances a 0
            $stmt = $pdo->prepare("UPDATE users SET current_balance = 0, available_for_spending = 0 WHERE id = ?");
            $stmt->execute([$user_id]);

            // Eliminar categorías personalizadas del usuario
            try {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE user_id = ?");
                $stmt->execute([$user_id]);
            } catch (Exception $e) {
                // Ignorar si hay error
            }

            // Eliminar presupuestos del usuario
            try {
                $stmt = $pdo->prepare("DELETE FROM budgets WHERE user_id = ?");
                $stmt->execute([$user_id]);
            } catch (Exception $e) {
                // Ignorar si hay error
            }

            header("Location: dashboard.php");
            exit;
    }
}

// Ahora incluir los demás archivos
require 'includes/header.php';
require 'config/db.php';
require 'config/exchange_api.php';
require_once 'config/roles.php';

$user_id = $_SESSION['user_id'];

// Obtener tasa de cambio - SISTEMA NUEVO CON APIS
$api = new ExchangeRateAPI();
$tasa_dolar = $api->getExchangeRate();

// Obtener información del cache para mostrar
$cache_info = $api->getCacheInfo();
$last_update = $cache_info ? $cache_info['date'] : 'Nunca';

// Obtener el saldo actual de la base de datos (en Bs)
$stmt = $pdo->prepare("SELECT current_balance, available_for_spending FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$current_balance_bs = $user_data['current_balance'];
$available_for_spending_bs = $user_data['available_for_spending'];

// Convertir a dólares
$current_balance_usd = $current_balance_bs / $tasa_dolar;
$available_for_spending_usd = $available_for_spending_bs / $tasa_dolar;

// Resumen financiero en Bs
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_incomes_bs = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_expenses_bs = $stmt->fetchColumn();

// Convertir a dólares
$total_incomes_usd = $total_incomes_bs / $tasa_dolar;
$total_expenses_usd = $total_expenses_bs / $tasa_dolar;

// Calcular disponible para gastar (no permitir negativo)
$available_to_spend_bs = max(0, $available_for_spending_bs);
$available_to_spend_usd = $available_to_spend_bs / $tasa_dolar;

// Porcentaje usado del total de ingresos
$used_percentage = $total_incomes_bs > 0 ? ($total_expenses_bs / $total_incomes_bs) * 100 : 0;
$remaining_percentage = 100 - $used_percentage;

// Información de presupuestos del mes actual (con manejo de errores)
$current_month = date('n');
$current_year = date('Y');
$budget_count = 0;
$total_budgeted = 0;
$total_spent_budget = 0;
$budget_used_percentage = 0;

try {
    // Verificar si la tabla budgets existe
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'budgets'");
    $stmt->execute();
    $table_exists = $stmt->fetch();

    if ($table_exists) {
        // comprobar si la tabla budgets tiene columnas modernas
        $hasBudgetCols = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'budget_amount'");
            if ($c && $c->fetch()) {
                $hasBudgetCols = true;
            }
        } catch (Exception $e) {
            // ignorar
        }
        if ($hasBudgetCols) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as budget_count, 
                       COALESCE(SUM(COALESCE(budget_amount, amount)), 0) as total_budgeted,
                       COALESCE(SUM(spent_amount), 0) as total_spent
                FROM budgets 
                WHERE user_id = ? AND month = ? AND year = ?
            ");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $budget_info = $stmt->fetch();
            $budget_count = $budget_info['budget_count'];
            $total_budgeted = $budget_info['total_budgeted'];
            $total_spent_budget = $budget_info['total_spent'];
        } else {
            // esquema antiguo, solo contar filas
            $stmt = $pdo->prepare("SELECT COUNT(*) as budget_count FROM budgets WHERE user_id = ? AND month = ? AND year = ?");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $budget_count = $stmt->fetchColumn();
            $total_budgeted = 0;
            $total_spent_budget = 0;
        }

        // Presupuesto utilizado
        $budget_used_percentage = $total_budgeted > 0 ? ($total_spent_budget / $total_budgeted) * 100 : 0;
    }
} catch (Exception $e) {
    // Si hay error, mantener los valores en 0
    error_log("Error al cargar presupuestos: " . $e->getMessage());
}

// Últimas transacciones
$transactions = [];
$stmt = $pdo->prepare("
    SELECT 'Ingreso' as type, amount, month, year, description, '' as category, created_at FROM incomes WHERE user_id = ?
    UNION ALL
    SELECT 'Gasto' as type, e.amount, e.month, e.year, e.description, c.name, e.created_at
    FROM expenses e 
    JOIN categories c ON e.category_id = c.id 
    WHERE e.user_id = ?
    ORDER BY created_at DESC
    LIMIT 15
");
$stmt->execute([$user_id, $user_id]);
$transactions = $stmt->fetchAll();

// Datos para gráfico de tendencias (últimos 6 meses)
$months = [];
$income_data = [];
$expense_data = [];
$current_year = date('Y');
$current_month = date('n');

for ($i = 5; $i >= 0; $i--) {
    $m = $current_month - $i;
    $y = $current_year;
    if ($m <= 0) {
        $m += 12;
        $y--;
    }
    $months[] = "$y-$m";

    // Ingresos
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$user_id, $y, $m]);
    $income_data[] = floatval($stmt->fetchColumn());

    // Gastos
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$user_id, $y, $m]);
    $expense_data[] = floatval($stmt->fetchColumn());
}

// Datos para gráfico de categorías (gastos por categoría del mes actual)
$stmt = $pdo->prepare("
    SELECT c.name as category_name, c.color, COALESCE(SUM(e.amount), 0) as total 
    FROM categories c 
    LEFT JOIN expenses e ON c.id = e.category_id AND e.user_id = ? AND e.month = ? AND e.year = ?
    WHERE c.user_id = ?
    GROUP BY c.id, c.name, c.color
    HAVING total > 0
");
$stmt->execute([$user_id, $current_month, $current_year, $user_id]);
$category_data = $stmt->fetchAll();

// Preparar datos para el gráfico de categorías
$category_labels = [];
$category_amounts = [];
$category_colors = [];
$category_percentages = [];

$total_category_expenses = 0;
foreach ($category_data as $cat) {
    $total_category_expenses += $cat['total'];
}

foreach ($category_data as $cat) {
    $category_labels[] = $cat['category_name'];
    $category_amounts[] = floatval($cat['total']);
    $category_colors[] = $cat['color'] ?: '#' . substr(md5($cat['category_name']), 0, 6);
    $percentage = $total_category_expenses > 0 ? ($cat['total'] / $total_category_expenses) * 100 : 0;
    $category_percentages[] = round($percentage, 1);
}

// Si el usuario es administrador, obtener sesiones activas y estadísticas
$active_sessions = [];
$total_users = 0;
$active_users_count = 0;
if (isAdmin()) {
    try {
        // Obtener sesiones activas
        $stmt = $pdo->prepare("
            SELECT u.name, u.email, u.role, a.login_time, a.last_activity, a.ip_address 
            FROM active_sessions a 
            JOIN users u ON a.user_id = u.id 
            WHERE a.last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ORDER BY a.last_activity DESC
        ");
        $stmt->execute();
        $active_sessions = $stmt->fetchAll();

        // Obtener estadísticas de usuarios
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(is_active = 1) as active FROM users");
        $stmt->execute();
        $user_stats = $stmt->fetch();
        $total_users = $user_stats['total'];
        $active_users_count = $user_stats['active'];

    } catch (Exception $e) {
        // Si hay error, ignorar (puede que las tablas no existan aún)
        error_log("Error al cargar datos de administrador: " . $e->getMessage());
    }
}

// Función para formatear las etiquetas de meses
function formatMonthLabel($monthString)
{
    $parts = explode('-', $monthString);
    $year = $parts[0];
    $month = $parts[1];
    $monthNames = [
        '1' => 'Ene',
        '2' => 'Feb',
        '3' => 'Mar',
        '4' => 'Abr',
        '5' => 'May',
        '6' => 'Jun',
        '7' => 'Jul',
        '8' => 'Ago',
        '9' => 'Sep',
        '10' => 'Oct',
        '11' => 'Nov',
        '12' => 'Dic'
    ];
    return $monthNames[$month] . " '" . substr($year, 2);
}

// Función para formatear montos en Bs
function formatBs($amount)
{
    return number_format($amount, 2, ',', '.');
}

function formatUsd($amount)
{
    return number_format($amount, 2, ',', '.');
}
?>

<style>
    .full-height-dashboard {
        min-height: calc(100vh - 120px);
        padding: 0;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto 1fr;
        gap: 20px;
        height: 100%;
    }

    .kpi-section {
        grid-column: 1 / -1;
    }

    .charts-section {
        grid-column: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .sidebar-section {
        grid-column: 2;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .kpi-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        height: 140px;
        position: relative;
        overflow: hidden;
    }

    .kpi-card.danger {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }

    .kpi-card.success {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }

    .kpi-card.warning {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    }

    .kpi-card.info {
        background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
    }

    .kpi-card.budget {
        background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        color: #2c3e50;
    }

    .kpi-card.admin {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        color: white;
    }

    .kpi-card.users {
        background: linear-gradient(135deg, #a8e6cf 0%, #56ab2f 100%);
        color: #2c3e50;
    }

    .kpi-number {
        font-size: 1.8rem;
        font-weight: bold;
        margin-bottom: 5px;
        line-height: 1;
    }

    .kpi-currency {
        font-size: 1rem;
        opacity: 0.9;
        margin-right: 2px;
    }

    .kpi-subamount {
        font-size: 0.85rem;
        opacity: 0.8;
        margin-top: 3px;
    }

    .kpi-label {
        font-size: 0.9rem;
        opacity: 0.9;
        font-weight: 500;
        margin-bottom: 5px;
    }

    .kpi-icon {
        position: absolute;
        bottom: 15px;
        right: 20px;
        font-size: 2.2rem !important;
        opacity: 0.3;
    }

    .kpi-actions {
        position: absolute;
        bottom: 10px;
        left: 20px;
        display: flex;
        gap: 5px;
    }

    .btn-clear {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.7rem;
        transition: all 0.3s;
    }

    .btn-clear:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
    }

    .chart-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        height: 320px;
        display: flex;
        flex-direction: column;
    }

    .transactions-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        flex: 1;
        min-height: 320px;
        display: flex;
        flex-direction: column;
    }

    .categories-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        height: 320px;
        display: flex;
        flex-direction: column;
    }

    .section-title {
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 20px;
        color: #2c3e50;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 12px;
    }

    /* Estilos para las áreas con scroll */
    .scrollable-content {
        flex: 1;
        overflow-y: auto;
        max-height: 100%;
    }

    .scrollable-table {
        flex: 1;
        overflow-y: auto;
    }

    .scrollable-table .table {
        margin-bottom: 0;
    }

    .scrollable-list {
        flex: 1;
        overflow-y: auto;
    }

    /* Personalizar la barra de scroll */
    .scrollable-content::-webkit-scrollbar,
    .scrollable-table::-webkit-scrollbar,
    .scrollable-list::-webkit-scrollbar {
        width: 6px;
    }

    .scrollable-content::-webkit-scrollbar-track,
    .scrollable-table::-webkit-scrollbar-track,
    .scrollable-list::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb,
    .scrollable-table::-webkit-scrollbar-thumb,
    .scrollable-list::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb:hover,
    .scrollable-table::-webkit-scrollbar-thumb:hover,
    .scrollable-list::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Mejorar la tabla */
    .table-sm td,
    .table-sm th {
        padding: 12px 8px;
        font-size: 0.9rem;
        vertical-align: middle;
        border-bottom: 1px solid #f0f0f0;
    }

    .table-sm thead th {
        background-color: #f8f9fa;
        position: sticky;
        top: 0;
        z-index: 10;
        font-weight: 600;
        color: #495057;
    }

    .no-data-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #6c757d;
        text-align: center;
        flex: 1;
    }

    .no-data-placeholder i {
        font-size: 3.5rem;
        margin-bottom: 15px;
        opacity: 0.4;
    }

    .no-data-placeholder p {
        font-size: 1rem;
        margin-bottom: 5px;
    }

    .no-data-placeholder small {
        font-size: 0.85rem;
    }

    .chart-canvas-container {
        flex: 1;
        position: relative;
    }

    /* Asegurar que ocupe todo el espacio disponible */
    .main-content {
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
        background-color: #f8f9fa;
        width: calc(100% - 250px);
    }

    /* Tasa de cambio */
    .exchange-rate {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .exchange-rate small {
        opacity: 0.8;
    }

    .exchange-rate .rate {
        font-size: 1.3rem;
        font-weight: bold;
        margin: 5px 0;
    }

    /* Indicadores de moneda */
    .currency-bs {
        color: #28a745;
        font-weight: bold;
    }

    .currency-usd {
        color: #17a2b8;
        font-weight: bold;
    }

    /* Responsive */
    @media (min-width: 1400px) {

        .chart-container,
        .transactions-container,
        .categories-container {
            height: 350px;
        }
    }

    /* Contador de items */
    .items-count {
        font-size: 0.8rem;
        color: #6c757d;
        margin-left: 10px;
        font-weight: normal;
    }

    /* Botón de actualización */
    .btn-update {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        transition: all 0.3s;
    }

    .btn-update:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
    }

    .btn-update:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* Mejorar responsive para KPI cards */
    @media (max-width: 768px) {
        .kpi-card {
            height: 120px;
            padding: 15px;
        }

        .kpi-number {
            font-size: 1.5rem;
        }

        .kpi-actions {
            position: relative;
            bottom: auto;
            left: auto;
            margin-top: 10px;
        }
    }

    /* Estilos para la sección de sesiones activas (admin) */
    .active-sessions-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
    }

    .session-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .session-item:last-child {
        border-bottom: none;
    }

    .session-user {
        display: flex;
        align-items: center;
    }

    .session-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        margin-right: 10px;
    }

    .session-details {
        flex: 1;
    }

    .session-status {
        display: flex;
        align-items: center;
        color: #28a745;
        font-weight: bold;
    }

    .session-status .dot {
        width: 8px;
        height: 8px;
        background: #28a745;
        border-radius: 50%;
        margin-right: 5px;
    }

    /* Panel de Administración */
    .admin-panel {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    .admin-panel h4 {
        color: white;
        margin-bottom: 20px;
    }

    .admin-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .admin-stat-card {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        padding: 15px;
        text-align: center;
        backdrop-filter: blur(10px);
    }

    .admin-stat-number {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
    }

    .admin-stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    .admin-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }

    .admin-btn {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 12px;
        border-radius: 10px;
        text-align: center;
        transition: all 0.3s;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    .admin-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
        text-decoration: none;
    }

    .admin-btn i {
        font-size: 1.5rem;
        margin-bottom: 8px;
    }

    .admin-btn span {
        font-size: 0.9rem;
    }

    /* Estilos para el modal de reset */
    .modal-reset .modal-content {
        border-radius: 15px;
        border: none;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .modal-reset .modal-header {
        background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        color: white;
        border-radius: 15px 15px 0 0;
        border: none;
    }

    .modal-reset .modal-body {
        padding: 30px;
    }

    .modal-reset .modal-footer {
        border-top: 1px solid #f0f0f0;
        padding: 20px 30px;
    }

    .warning-icon {
        font-size: 4rem;
        color: #ff4b2b;
        margin-bottom: 20px;
    }

    .reset-warning-list {
        background: #fff8e1;
        border-radius: 10px;
        padding: 20px;
        margin: 20px 0;
    }

    .reset-warning-list ul {
        margin-bottom: 0;
        padding-left: 20px;
    }

    .reset-warning-list li {
        margin-bottom: 8px;
        color: #d32f2f;
    }

    .reset-warning-list li:last-child {
        margin-bottom: 0;
    }

    .btn-confirm-reset {
        background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-confirm-reset:hover {
        background: linear-gradient(135deg, #ff2b4e 0%, #ff3300 100%);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 65, 108, 0.4);
    }

    .btn-cancel-reset {
        background: #f8f9fa;
        color: #6c757d;
        border: 1px solid #dee2e6;
        padding: 12px 30px;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-cancel-reset:hover {
        background: #e9ecef;
        color: #495057;
    }
</style>

<div class="full-height-dashboard">
    <!-- Tasa de Cambio -->
    <div class="exchange-rate">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small>Tasa de Cambio (Actualizada Automáticamente)</small>
                <div class="rate">1 USD = Bs <?= formatBs($tasa_dolar) ?></div>
                <small>Última actualización: <?= $last_update ?></small>
            </div>
            <button class="btn-update" onclick="updateExchangeRate()" id="updateRateBtn">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
        </div>
    </div>

    <!-- Panel de Administración (Solo para admins) -->
    <?php if (isAdmin()): ?>
        <div class="admin-panel">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0">
                    <i class="fas fa-crown me-2"></i>Panel de Administración
                </h4>
                <span class="badge bg-warning">Administrador</span>
            </div>

            <div class="admin-stats">
                <div class="admin-stat-card">
                    <div class="admin-stat-number"><?= $total_users ?></div>
                    <div class="admin-stat-label">Usuarios Totales</div>
                </div>
                <div class="admin-stat-card">
                    <div class="admin-stat-number"><?= $active_users_count ?></div>
                    <div class="admin-stat-label">Usuarios Activos</div>
                </div>
                <div class="admin-stat-card">
                    <div class="admin-stat-number"><?= count($active_sessions) ?></div>
                    <div class="admin-stat-label">Sesiones Activas</div>
                </div>
            </div>

            <div class="admin-actions">
                <a href="user_management.php" class="admin-btn">
                    <i class="fas fa-users-cog"></i>
                    <span>Gestión de Usuarios</span>
                </a>
                <a href="settings.php" class="admin-btn">
                    <i class="fas fa-cog"></i>
                    <span>Ajustes del Sistema</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI Section -->
    <div class="kpi-section mb-4">
        <div class="row g-3">
            <!-- Saldo Disponible para Gastar -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card success">
                    <div class="kpi-label">Disponible para Gastar</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($available_to_spend_bs) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($available_to_spend_usd) ?> USD
                    </div>
                    <i class="fas fa-wallet kpi-icon"></i>
                </div>
            </div>

            <!-- Total Ingresos -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card">
                    <div class="kpi-label">Total Ingresos</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($total_incomes_bs) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($total_incomes_usd) ?> USD
                    </div>
                    <i class="fas fa-dollar-sign kpi-icon"></i>
                </div>
            </div>

            <!-- Total Gastos -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card danger">
                    <div class="kpi-label">Total Gastado</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($total_expenses_bs) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($total_expenses_usd) ?> USD
                    </div>
                    <i class="fas fa-receipt kpi-icon"></i>
                </div>
            </div>

            <!-- Estado Financiero -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card warning">
                    <div class="kpi-label">Estado Financiero</div>
                    <div class="kpi-number"><?= $available_to_spend_bs > 0 ? 'Saldo Positivo' : 'Sin Saldo' ?></div>
                    <div class="kpi-subamount">
                        <?= number_format($remaining_percentage, 1) ?>% disponible
                    </div>
                    <i class="fas fa-chart-line kpi-icon"></i>
                </div>
            </div>

            <!-- Información de Presupuestos -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card budget">
                    <div class="kpi-label">Presupuestos Activos</div>
                    <div class="kpi-number"><?= $budget_count ?></div>
                    <div class="kpi-subamount">
                        <?= number_format($budget_used_percentage, 1) ?>% utilizado
                    </div>
                    <i class="fas fa-chart-pie kpi-icon"></i>
                </div>
            </div>

            <!-- Rol del Usuario -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="kpi-card <?= isAdmin() ? 'admin' : 'info' ?>">
                    <div class="kpi-label">Tu Rol en el Sistema</div>
                    <div class="kpi-number"><?= isAdmin() ? 'Administrador' : 'Usuario' ?></div>
                    <div class="kpi-subamount">
                        <?= isAdmin() ? 'Acceso total' : 'Acceso limitado' ?>
                    </div>
                    <i class="fas fa-user-tie kpi-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body py-3">
                    <div class="row text-center">
                        <div class="col-md-2 mb-2 mb-md-0">
                            <a href="add_expense.php" class="btn btn-danger btn-sm w-100">
                                <i class="fas fa-minus-circle me-1"></i>Registrar Gasto
                            </a>
                        </div>
                        <div class="col-md-2 mb-2 mb-md-0">
                            <a href="add_income.php" class="btn btn-success btn-sm w-100">
                                <i class="fas fa-plus-circle me-1"></i>Agregar Ingreso
                            </a>
                        </div>
                        <div class="col-md-2 mb-2 mb-md-0">
                            <a href="categories.php" class="btn btn-info btn-sm w-100">
                                <i class="fas fa-tags me-1"></i>Categorías
                            </a>
                        </div>
                        <div class="col-md-2 mb-2 mb-md-0">
                            <a href="budgets.php" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-chart-pie me-1"></i>Presupuestos
                            </a>
                        </div>
                        <div class="col-md-2 mb-2 mb-md-0">
                            <a href="expenses.php" class="btn btn-warning btn-sm w-100">
                                <i class="fas fa-list me-1"></i>Ver Gastos
                            </a>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-dark btn-sm w-100" data-bs-toggle="modal"
                                data-bs-target="#resetSystemModal">
                                <i class="fas fa-broom me-1"></i>Resetear Todo
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección de Sesiones Activas para Administradores -->
    <?php if (isAdmin() && !empty($active_sessions)): ?>
        <div class="active-sessions-container">
            <div class="section-title">
                <i class="fas fa-plug me-2"></i>Sesiones Activas en Tiempo Real
                <span class="items-count">(<?= count($active_sessions) ?> usuarios conectados)</span>
            </div>
            <div class="scrollable-content">
                <?php foreach ($active_sessions as $session): ?>
                    <div class="session-item">
                        <div class="session-user">
                            <div class="session-avatar">
                                <?= strtoupper(substr($session['name'], 0, 1)) ?>
                            </div>
                            <div class="session-details">
                                <strong><?= htmlspecialchars($session['name']) ?></strong>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($session['email']) ?>
                                    <span class="badge bg-<?= $session['role'] === 'admin' ? 'danger' : 'secondary' ?> ms-1">
                                        <?= $session['role'] ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="session-status">
                            <span class="dot"></span>
                            Conectado desde <?= date('H:i', strtotime($session['login_time'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content Grid -->
    <div class="dashboard-grid" style="min-height: 600px;">
        <!-- Left Column - Charts -->
        <div class="charts-section">
            <!-- Tendencias Chart -->
            <div class="chart-container">
                <div class="section-title">Tendencias (Bs)</div>
                <div class="chart-canvas-container">
                    <canvas id="financeChart"></canvas>
                </div>
            </div>

            <!-- Distribución Gastos Chart -->
            <div class="chart-container">
                <div class="section-title">Distribución de Gastos</div>
                <div class="chart-canvas-container">
                    <?php if (!empty($category_data)): ?>
                        <canvas id="categoryChart"></canvas>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-chart-pie"></i>
                            <p>No hay gastos este mes</p>
                            <small>Registra tu primer gasto</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column - Sidebar Content con Scroll -->
        <div class="sidebar-section">
            <!-- Últimas Transacciones con Scroll -->
            <div class="transactions-container">
                <div class="section-title">
                    Últimas Transacciones
                    <span class="items-count">(<?= count($transactions) ?>)</span>
                </div>
                <div class="scrollable-content">
                    <?php if (!empty($transactions)): ?>
                        <div class="scrollable-table">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Fecha</th>
                                        <th>Monto (Bs)</th>
                                        <th>Descripción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $t): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $t['type'] == 'Ingreso' ? 'success' : 'danger' ?>">
                                                    <?= $t['type'] == 'Ingreso' ? 'Ing' : 'Gas' ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap">
                                                <?= $t['year'] ?>-<?= str_pad($t['month'], 2, '0', STR_PAD_LEFT) ?>
                                            </td>
                                            <td>
                                                <strong class="<?= $t['type'] == 'Ingreso' ? 'text-success' : 'text-danger' ?>">
                                                    Bs <?= formatBs($t['amount']) ?>
                                                </strong>
                                            </td>
                                            <td class="text-truncate" style="max-width: 150px;"
                                                title="<?= htmlspecialchars($t['description'] ?: $t['category']) ?>">
                                                <?= htmlspecialchars($t['description'] ?: $t['category']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-receipt"></i>
                            <p>No hay transacciones</p>
                            <small>Registra tu primera transacción</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resumen Categorías con Scroll -->
            <div class="categories-container">
                <div class="section-title">
                    Resumen Categorías (Bs)
                    <span class="items-count">(<?= count($category_data) ?>)</span>
                </div>
                <div class="scrollable-content">
                    <?php if (!empty($category_data)): ?>
                        <div class="scrollable-list">
                            <div class="list-group list-group-flush">
                                <?php foreach ($category_data as $index => $cat): ?>
                                    <div class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <span class="badge me-3"
                                                style="background-color: <?= $category_colors[$index] ?>; width: 16px; height: 16px; border-radius: 4px;"></span>
                                            <span class="fw-medium"><?= htmlspecialchars($cat['category_name']) ?></span>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-dark">Bs <?= formatBs($cat['total']) ?></div>
                                            <small class="text-muted"><?= $category_percentages[$index] ?>%</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-tags"></i>
                            <p>No hay categorías con gastos</p>
                            <small>Crea categorías y registra gastos</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Resetear Sistema -->
<div class="modal fade modal-reset" id="resetSystemModal" tabindex="-1" aria-labelledby="resetSystemModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resetSystemModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Reinicio del Sistema
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="warning-icon">
                    <i class="fas fa-radiation-alt"></i>
                </div>
                <h4 class="text-danger mb-4">¡ADVERTENCIA!</h4>
                <p class="lead">¿Estás seguro de que deseas reiniciar completamente el sistema?</p>
                <p>Esta acción NO se puede deshacer y eliminará todos tus datos.</p>

                <div class="reset-warning-list">
                    <h6 class="mb-3">Se eliminarán permanentemente:</h6>
                    <ul class="text-start">
                        <li>Todos los ingresos registrados</li>
                        <li>Todos los gastos registrados</li>
                        <li>Todas las categorías personalizadas</li>
                        <li>Todos los presupuestos configurados</li>
                    </ul>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Nota:</strong> Tu cuenta de usuario NO será eliminada, solo los datos financieros.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel-reset" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <form method="POST" action="">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="reset_system_completely">
                    <button type="submit" class="btn btn-confirm-reset">
                        <i class="fas fa-broom me-2"></i>Sí, Resetear Todo
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Gráfico de Tendencias
    const ctx = document.getElementById('financeChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map('formatMonthLabel', $months)) ?>,
            datasets: [
                {
                    label: 'Ingresos (Bs)',
                    data: <?= json_encode($income_data) ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                },
                {
                    label: 'Gastos (Bs)',
                    data: <?= json_encode($expense_data) ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        callback: function (value) {
                            return 'Bs ' + value.toLocaleString();
                        },
                        font: {
                            size: 11
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            size: 12
                        },
                        usePointStyle: true,
                        padding: 15
                    }
                }
            }
        }
    });

    // Gráfico de Distribución por Categorías
    <?php if (!empty($category_data)): ?>
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($category_labels) ?>,
                datasets: [{
                    data: <?= json_encode($category_amounts) ?>,
                    backgroundColor: <?= json_encode($category_colors) ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return label + ': Bs ' + value.toLocaleString() + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    <?php endif; ?>

    // Función para actualizar la tasa de cambio
    function updateExchangeRate() {
        const btn = document.getElementById('updateRateBtn');
        const originalHtml = btn.innerHTML;

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
        btn.disabled = true;

        fetch('api/update_exchange_rate.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar mensaje de éxito y recargar
                    showNotification('Tasa actualizada exitosamente: Bs ' + data.rate, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Error: ' + data.error, 'error');
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al actualizar tasa', 'error');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            });
    }

    // Función para mostrar notificaciones
    function showNotification(message, type) {
        // Crear elemento de notificación
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
        notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
    `;
        notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

        document.body.appendChild(notification);

        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }

    // Actualizar automáticamente cada 5 minutos (300000 ms)
    setInterval(updateExchangeRate, 300000);

    // Actualizar saldo automáticamente cada 30 segundos
    setInterval(function () {
        fetch('api/get_balance.php?user_id=<?= $user_id ?>')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const availableBs = Math.max(0, data.available_for_spending);
                    const availableUsd = availableBs / <?= $tasa_dolar ?>;

                    // Actualizar saldo disponible
                    const balanceElement = document.querySelector('.kpi-card.success .kpi-number');
                    const usdElement = document.querySelector('.kpi-card.success .kpi-subamount');

                    if (balanceElement && usdElement) {
                        balanceElement.innerHTML = '<span class="kpi-currency">Bs</span>' + availableBs.toLocaleString('es-VE', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });

                        usdElement.innerHTML = '<span class="kpi-currency">$</span>' + availableUsd.toLocaleString('es-VE', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }) + ' USD';
                    }
                }
            })
            .catch(error => console.error('Error actualizando saldo:', error));
    }, 30000);

    // Script para manejar el modal de resetear sistema
    document.addEventListener('DOMContentLoaded', function () {
        const resetModal = document.getElementById('resetSystemModal');

        if (resetModal) {
            resetModal.addEventListener('shown.bs.modal', function () {
                // Enfocar el botón de cancelar por seguridad
                document.querySelector('.btn-cancel-reset').focus();
            });

            // Prevenir envío accidental con Enter
            resetModal.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.target.classList.contains('btn-confirm-reset')) {
                    e.preventDefault();
                }
            });
        }
    });
</script>

<?php require 'includes/footer.php'; ?>