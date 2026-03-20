<?php
session_start();

// Cargar CSRF helper
require_once __DIR__ . '/config/csrf.php';

// Necesitamos acceso a la base de datos para comprobar el esquema
require 'config/db.php';

// Verificar si la tabla budgets tiene las columnas necesarias
$needsMigration = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'budget_amount'");
    if (!($stmt && $stmt->fetch())) {
        $needsMigration = true;
    }
} catch (Exception $e) {
    $needsMigration = true;
}
if ($needsMigration) {
    die('La tabla de presupuestos requiere actualización. Ejecute `php scripts/migrate_budgets_table.php` en la línea de comandos antes de usar este módulo.');
}

// Procesar formularios de gestión de presupuestos AL PRINCIPIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    // Validar token CSRF
    validate_csrf();
    require 'config/db.php';

    $user_id = $_SESSION['user_id'];
    $current_month = date('n');
    $current_year = date('Y');

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_budget':
            $category_id = $_POST['category_id'];
            $amount = floatval($_POST['amount']);

            // Obtener TOTAL INGRESOS del mes (correcto)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ? AND month = ? AND year = ?");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $total_incomes = $stmt->fetchColumn();

            // Obtener total presupuestado actual
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(budget_amount, amount)), 0) FROM budgets WHERE user_id = ? AND month = ? AND year = ?");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $total_budgeted = $stmt->fetchColumn();

            // Calcular DISPONIBLE CORRECTO: Ingresos - Total Presupuestado
            $available_to_budget = $total_incomes - $total_budgeted;

            // Verificar si excede el disponible (solo para mostrar advertencia)
            if ($amount > $available_to_budget) {
                $_SESSION['warning'] = "⚠️ <strong>Advertencia:</strong> Estás asignando más del saldo disponible. Tu déficit será de Bs " .
                    number_format($amount - $available_to_budget, 2, ',', '.') .
                    ". Puedes continuar si lo deseas.";
            }

            // Permitir siempre la asignación
            $stmt = $pdo->prepare("
                INSERT INTO budgets (user_id, category_id, month, year, budget_amount, spent_amount) 
                VALUES (?, ?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE budget_amount = budget_amount + ?
            ");
            $stmt->execute([$user_id, $category_id, $current_month, $current_year, $amount, $amount]);

            header("Location: budgets.php");
            exit;

        case 'update_budget':
            $budget_id = $_POST['budget_id'];
            $new_amount = floatval($_POST['amount']);

            // Obtener datos actuales
            $stmt = $pdo->prepare("SELECT budget_amount FROM budgets WHERE id = ? AND user_id = ?");
            $stmt->execute([$budget_id, $user_id]);
            $current_amount = $stmt->fetchColumn();

            // Obtener TOTAL INGRESOS del mes (correcto)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ? AND month = ? AND year = ?");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $total_incomes = $stmt->fetchColumn();

            // Obtener total presupuestado actual excluyendo el actual
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(budget_amount, amount)), 0) FROM budgets WHERE user_id = ? AND month = ? AND year = ? AND id != ?");
            $stmt->execute([$user_id, $current_month, $current_year, $budget_id]);
            $other_budgeted = $stmt->fetchColumn();

            // Calcular DISPONIBLE CORRECTO: Ingresos - Otros Presupuestados
            $available_to_budget = $total_incomes - $other_budgeted;
            $difference = $new_amount - $current_amount;

            // Verificar si excede el disponible (solo para mostrar advertencia)
            if ($difference > $available_to_budget) {
                $_SESSION['warning'] = "⚠️ <strong>Advertencia:</strong> Esta actualización creará un déficit de Bs " .
                    number_format($difference - $available_to_budget, 2, ',', '.') .
                    ". Puedes continuar si lo deseas.";
            }

            // Permitir siempre la actualización
            $stmt = $pdo->prepare("UPDATE budgets SET budget_amount = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_amount, $budget_id, $user_id]);

            header("Location: budgets.php");
            exit;

        case 'delete_budget':
            $budget_id = $_POST['budget_id'];
            $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
            $stmt->execute([$budget_id, $user_id]);

            header("Location: budgets.php");
            exit;
    }
}

// Ahora incluir los demás archivos
require 'includes/header.php';
require 'config/db.php';
require 'config/exchange_api.php';

$user_id = $_SESSION['user_id'];
$current_month = date('n');
$current_year = date('Y');

// Obtener tasa de cambio actual
$api = new ExchangeRateAPI();
$tasa_dolar = $api->getExchangeRate();

// Obtener TOTAL INGRESOS del mes (CORRECTO)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM incomes WHERE user_id = ? AND month = ? AND year = ?");
$stmt->execute([$user_id, $current_month, $current_year]);
$total_incomes = $stmt->fetchColumn();

// Obtener categorías del usuario
$stmt = $pdo->prepare("SELECT id, name, color FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

// Obtener presupuestos del mes actual
$stmt = $pdo->prepare("
    SELECT b.*, c.name as category_name, c.color 
    FROM budgets b 
    JOIN categories c ON b.category_id = c.id 
    WHERE b.user_id = ? AND b.month = ? AND b.year = ?
    ORDER BY c.name
");
$stmt->execute([$user_id, $current_month, $current_year]);
$budgets = $stmt->fetchAll();

// Calcular total presupuestado
$total_budgeted = 0;
foreach ($budgets as $budget) {
    $total_budgeted += $budget['budget_amount'];
}

// Calcular disponible para presupuestar CORRECTO: Ingresos - Total Presupuestado
$available_to_budget = $total_incomes - $total_budgeted;

// Obtener mensajes de sesión
$error = $_SESSION['error'] ?? '';
$warning = $_SESSION['warning'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error']);
unset($_SESSION['warning']);
unset($_SESSION['success']);

// Función para formatear montos
function formatBs($amount)
{
    return number_format($amount, 2, ',', '.');
}

function formatUsd($amount, $tasa)
{
    return number_format($amount / $tasa, 2, ',', '.');
}
?>

<style>
    .full-height-budgets {
        min-height: calc(100vh - 120px);
        padding: 0;
    }

    .budgets-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto 1fr;
        gap: 20px;
        height: 100%;
    }

    .kpi-section {
        grid-column: 1 / -1;
    }

    .sidebar-section {
        grid-column: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .main-section {
        grid-column: 2;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Header Styles */
    .budget-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    /* KPI Cards */
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

    .kpi-card.income {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }

    .kpi-card.budgeted {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    }

    .kpi-card.available {
        background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        color: #2c3e50;
    }

    .kpi-card.used {
        background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
    }

    .kpi-card.danger {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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

    /* Container Styles */
    .budget-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
    }

    .form-container {
        height: auto;
        min-height: 400px;
    }

    .budgets-container {
        flex: 1;
        min-height: 600px;
        display: flex;
        flex-direction: column;
    }

    .info-container {
        height: auto;
    }

    .section-title {
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 20px;
        color: #2c3e50;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 12px;
    }

    /* Budget Cards */
    .budget-card {
        border: 1px solid #e0e0e0;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
        transition: all 0.3s ease;
        background: white;
    }

    .budget-card:hover {
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .budget-progress {
        height: 8px;
        border-radius: 4px;
        background: #e9ecef;
        overflow: hidden;
        margin: 15px 0;
    }

    .budget-progress-bar {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    .progress-safe {
        background: linear-gradient(90deg, #28a745, #43e97b);
    }

    .progress-warning {
        background: linear-gradient(90deg, #ffd700, #ffed4a);
    }

    .progress-danger {
        background: linear-gradient(90deg, #dc3545, #f5576c);
    }

    .budget-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .btn-edit,
    .btn-delete {
        padding: 8px 16px;
        font-size: 0.85rem;
        border-radius: 8px;
        flex: 1;
    }

    .category-color {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 10px;
        border: 2px solid #fff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* Form Styles */
    .form-control,
    .form-select {
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        padding: 10px 15px;
        font-size: 0.9rem;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }

    /* Alert Styles */
    .alert-container {
        grid-column: 1 / -1;
    }

    .warning-badge {
        background: linear-gradient(135deg, #ffd700, #ffed4a);
        color: #856404;
        border: 1px solid #ffeaa7;
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 0.8rem;
        margin-left: 10px;
    }

    .amount-info {
        background-color: #f8f9fa;
        border-left: 4px solid #667eea;
        padding: 10px 15px;
        border-radius: 5px;
        margin-top: 10px;
        font-size: 0.85rem;
    }

    .amount-info .available-amount {
        color: #28a745;
        font-weight: bold;
    }

    .amount-info .deficit-amount {
        color: #dc3545;
        font-weight: bold;
    }

    /* Scrollable Areas */
    .scrollable-content {
        flex: 1;
        overflow-y: auto;
        max-height: 100%;
    }

    .scrollable-budgets {
        flex: 1;
        overflow-y: auto;
    }

    /* Personalizar la barra de scroll */
    .scrollable-content::-webkit-scrollbar,
    .scrollable-budgets::-webkit-scrollbar {
        width: 6px;
    }

    .scrollable-content::-webkit-scrollbar-track,
    .scrollable-budgets::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb,
    .scrollable-budgets::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb:hover,
    .scrollable-budgets::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* No Data Placeholder */
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

    /* Info List */
    .info-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .info-list li {
        padding: 10px 0;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        font-size: 0.9rem;
    }

    .info-list li:last-child {
        border-bottom: none;
    }

    .info-list li i {
        margin-right: 10px;
        width: 20px;
        text-align: center;
        color: #667eea;
    }

    /* Badge Styles */
    .budget-badge {
        font-size: 0.8rem;
        padding: 4px 8px;
        border-radius: 10px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .budgets-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .sidebar-section,
        .main-section {
            grid-column: 1;
        }

        .kpi-card {
            height: 120px;
            padding: 15px;
        }

        .kpi-number {
            font-size: 1.5rem;
        }

        .budget-actions {
            flex-direction: column;
        }
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

    /* Contador de items */
    .items-count {
        font-size: 0.8rem;
        color: #6c757d;
        margin-left: 10px;
        font-weight: normal;
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
    }
</style>

<div class="full-height-budgets">
    <!-- Header -->
    <div class="budget-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1">💰 Presupuestos Mensuales</h2>
                <p class="mb-0">Gestiona tus presupuestos por categoría para <?= date('F Y') ?></p>
            </div>
            <div class="col-md-4 text-end">
                <div class="exchange-rate">
                    <small>Tasa de Cambio</small>
                    <div class="rate">1 USD = Bs <?= formatBs($tasa_dolar) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alertas -->
    <?php if ($available_to_budget < 0): ?>
        <div class="alert-container">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Presupuesto Excedido:</strong> Has asignado más del saldo disponible. Tu déficit actual es de Bs
                <?= formatBs(abs($available_to_budget)) ?>.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert-container">
            <div class="alert alert-danger">
                <i class="fas fa-times-circle me-2"></i>
                <?= $error ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($warning)): ?>
        <div class="alert-container">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= $warning ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert-container">
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?= $success ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI Section -->
    <div class="kpi-section mb-4">
        <div class="row g-3">
            <!-- Total Ingresos -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card income">
                    <div class="kpi-label">Total Ingresos</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($total_incomes) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($total_incomes, $tasa_dolar) ?> USD
                    </div>
                    <i class="fas fa-dollar-sign kpi-icon"></i>
                </div>
            </div>

            <!-- Total Presupuestado -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card budgeted">
                    <div class="kpi-label">Total Presupuestado</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($total_budgeted) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($total_budgeted, $tasa_dolar) ?> USD
                    </div>
                    <i class="fas fa-chart-pie kpi-icon"></i>
                </div>
            </div>

            <!-- Disponible para Presupuestar -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card available <?= $available_to_budget < 0 ? 'danger' : '' ?>">
                    <div class="kpi-label">
                        Disponible para Presupuestar
                        <?php if ($available_to_budget < 0): ?>
                            <span class="warning-badge">Déficit</span>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= formatBs($available_to_budget) ?>
                    </div>
                    <div class="kpi-subamount">
                        <span class="kpi-currency">$</span><?= formatUsd($available_to_budget, $tasa_dolar) ?> USD
                    </div>
                    <i class="fas fa-wallet kpi-icon"></i>
                </div>
            </div>

            <!-- Utilizado -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card used">
                    <div class="kpi-label">Presupuesto Utilizado</div>
                    <div class="kpi-number">
                        <?= $total_incomes > 0 ? round(($total_budgeted / $total_incomes) * 100, 1) : 0 ?>%
                    </div>
                    <div class="kpi-subamount">
                        Bs <?= formatBs($total_budgeted) ?>
                    </div>
                    <i class="fas fa-chart-line kpi-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="budgets-grid" style="min-height: 600px;">
        <!-- Left Column - Sidebar -->
        <div class="sidebar-section">
            <!-- Formulario para agregar presupuesto -->
            <div class="budget-container form-container">
                <div class="section-title">➕ Agregar Presupuesto</div>
                <form method="POST" id="budgetForm">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_budget">

                    <div class="mb-3">
                        <label class="form-label">Categoría</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Seleccionar categoría...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>">
                                    <?php if ($cat['color']): ?>
                                        <span class="category-color" style="background-color: <?= $cat['color'] ?>"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Monto (Bs)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00"
                            min="0.01" required id="amountInput">

                        <!-- Información del monto -->
                        <div class="amount-info">
                            <div class="mb-2">
                                <i class="fas fa-wallet text-primary me-1"></i>
                                <strong>Disponible para presupuestar:</strong>
                                <span class="available-amount">Bs <?= formatBs($available_to_budget) ?></span>
                            </div>

                            <div id="deficitWarning" class="d-none">
                                <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                <strong>Déficit estimado:</strong>
                                <span class="deficit-amount" id="deficitAmount">Bs 0,00</span>
                            </div>

                            <div id="withinLimit" class="d-none">
                                <i class="fas fa-check-circle text-success me-1"></i>
                                <span>Dentro del límite disponible</span>
                            </div>
                        </div>

                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Puedes ingresar cualquier monto. Se mostrará advertencia si excedes el disponible.
                        </small>
                    </div>

                    <button type="submit" class="btn btn-success w-100" id="submitBtn">
                        <i class="fas fa-plus me-2"></i>Agregar Presupuesto
                    </button>
                </form>
            </div>

            <!-- Información -->
            <div class="budget-container info-container">
                <div class="section-title">💡 Cómo Funciona</div>
                <ul class="info-list">
                    <li><i class="fas fa-check"></i> Asigna presupuestos a cada categoría</li>
                    <li><i class="fas fa-chart-bar"></i> El sistema rastrea tus gastos automáticamente</li>
                    <li><i class="fas fa-exclamation-triangle"></i> Recibe alertas cuando te acerques al límite</li>
                    <li><i class="fas fa-sync"></i> Los presupuestos se reinician cada mes</li>
                    <li><i class="fas fa-chart-line"></i> Mejora tu control financiero mensual</li>
                </ul>
            </div>
        </div>

        <!-- Right Column - Main Content -->
        <div class="main-section">
            <!-- Lista de presupuestos -->
            <div class="budget-container budgets-container">
                <div class="section-title">
                    📋 Presupuestos Asignados
                    <span class="badge bg-primary budget-badge"><?= count($budgets) ?></span>
                </div>

                <div class="scrollable-content">
                    <?php if (empty($budgets)): ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-chart-pie"></i>
                            <p>No hay presupuestos asignados</p>
                            <small>Comienza asignando tu primer presupuesto</small>
                        </div>
                    <?php else: ?>
                        <div class="scrollable-budgets">
                            <?php foreach ($budgets as $budget):
                                $percentage = $budget['budget_amount'] > 0 ? ($budget['spent_amount'] / $budget['budget_amount']) * 100 : 0;
                                $progress_class = $percentage >= 100 ? 'progress-danger' : ($percentage >= 80 ? 'progress-warning' : 'progress-safe');
                                $remaining = $budget['budget_amount'] - $budget['spent_amount'];
                                ?>
                                <div class="budget-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center">
                                            <?php if ($budget['color']): ?>
                                                <span class="category-color"
                                                    style="background-color: <?= $budget['color'] ?>"></span>
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($budget['category_name']) ?></strong>
                                        </div>
                                        <span class="badge bg-<?= $remaining >= 0 ? 'success' : 'danger' ?> budget-badge">
                                            Bs <?= formatBs($remaining) ?>
                                        </span>
                                    </div>

                                    <div class="budget-progress">
                                        <div class="budget-progress-bar <?= $progress_class ?>"
                                            style="width: <?= min($percentage, 100) ?>%"></div>
                                    </div>

                                    <div class="row text-sm">
                                        <div class="col-6">
                                            <small class="text-success">
                                                <strong>Gastado:</strong><br>
                                                Bs <?= formatBs($budget['spent_amount']) ?>
                                            </small>
                                        </div>
                                        <div class="col-6 text-end">
                                            <small class="text-primary">
                                                <strong>Presupuesto:</strong><br>
                                                Bs <?= formatBs($budget['budget_amount']) ?>
                                            </small>
                                        </div>
                                    </div>

                                    <div class="text-center text-muted small mt-2">
                                        <?= number_format($percentage, 1) ?>% utilizado
                                    </div>

                                    <div class="budget-actions">
                                        <button type="button" class="btn btn-outline-primary btn-edit" data-bs-toggle="modal"
                                            data-bs-target="#editBudgetModal" data-budget-id="<?= $budget['id'] ?>"
                                            data-budget-amount="<?= $budget['budget_amount'] ?>"
                                            data-category-name="<?= htmlspecialchars($budget['category_name']) ?>">
                                            <i class="fas fa-edit me-1"></i>Editar
                                        </button>

                                        <form method="POST" class="flex-fill">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="delete_budget">
                                            <input type="hidden" name="budget_id" value="<?= $budget['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-delete w-100"
                                                onclick="return confirm('¿Eliminar presupuesto de <?= htmlspecialchars($budget['category_name']) ?>?')">
                                                <i class="fas fa-trash me-1"></i>Eliminar
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para editar presupuesto -->
<div class="modal fade" id="editBudgetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Presupuesto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editBudgetForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_budget">
                <input type="hidden" name="budget_id" id="editBudgetId">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Categoría</label>
                        <input type="text" class="form-control" id="editCategoryName" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nuevo Monto (Bs)</label>
                        <input type="number" step="0.01" name="amount" id="editBudgetAmount" class="form-control"
                            min="0.01" required>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Puedes ingresar cualquier monto. El sistema te alertará si excedes el disponible.
                        </small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Modal de edición
    document.addEventListener('DOMContentLoaded', function () {
        const editModal = document.getElementById('editBudgetModal');

        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const budgetId = button.getAttribute('data-budget-id');
            const budgetAmount = button.getAttribute('data-budget-amount');
            const categoryName = button.getAttribute('data-category-name');

            document.getElementById('editBudgetId').value = budgetId;
            document.getElementById('editBudgetAmount').value = budgetAmount;
            document.getElementById('editCategoryName').value = categoryName;
        });

        // Validación en tiempo real para el formulario de agregar
        const amountInput = document.getElementById('amountInput');
        const deficitWarning = document.getElementById('deficitWarning');
        const withinLimit = document.getElementById('withinLimit');
        const deficitAmount = document.getElementById('deficitAmount');
        const submitBtn = document.getElementById('submitBtn');

        // Saldo disponible desde PHP
        const availableAmount = <?= $available_to_budget ?>;

        amountInput.addEventListener('input', function () {
            const enteredAmount = parseFloat(this.value) || 0;

            if (enteredAmount > availableAmount) {
                const deficit = enteredAmount - availableAmount;
                deficitAmount.textContent = 'Bs ' + formatNumber(deficit);
                deficitWarning.classList.remove('d-none');
                withinLimit.classList.add('d-none');

                // Cambiar color del botón a warning
                submitBtn.className = 'btn btn-warning w-100';
                submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Agregar (excede disponible)';
            } else {
                deficitWarning.classList.add('d-none');
                withinLimit.classList.remove('d-none');

                // Restaurar color normal del botón
                submitBtn.className = 'btn btn-success w-100';
                submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Agregar Presupuesto';
            }
        });

        // Confirmación si excede el disponible
        document.getElementById('budgetForm').addEventListener('submit', function (e) {
            const enteredAmount = parseFloat(amountInput.value) || 0;

            if (enteredAmount > availableAmount) {
                const deficit = enteredAmount - availableAmount;
                const confirmMsg = `⚠️ Estás asignando Bs ${formatNumber(enteredAmount)} pero solo dispones de Bs ${formatNumber(availableAmount)}.\n\nEsto creará un déficit de Bs ${formatNumber(deficit)}.\n\n¿Deseas continuar?`;

                if (!confirm(confirmMsg)) {
                    e.preventDefault();
                }
            }
        });

        // Función para formatear números
        function formatNumber(num) {
            return num.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    });

    // Actualizar automáticamente cada 30 segundos
    setInterval(function () {
        location.reload();
    }, 30000);
</script>

<?php require 'includes/footer.php'; ?>