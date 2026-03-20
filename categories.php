<?php
session_start();
require 'config/db.php';
// Detectar si la tabla budgets posee columnas de presupuesto moderno
$hasBudgetCols = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'budget_amount'");
    if ($stmt && $stmt->fetch()) {
        $hasBudgetCols = true;
    }
} catch (Exception $e) {
    // tabla podría no existir; $hasBudgetCols queda en false
}

// Si no existen las columnas, se seguirá mostrando la página pero sin información de presupuesto.
if (!$hasBudgetCols) {
    echo '<div class="alert alert-info">';
    echo 'El esquema de presupuestos no está actualizado. <strong>Ejecuta <code>php scripts/migrate_budgets_table.php</code></strong> en la terminal para habilitar presupuestos.</n';
    echo '</div>';
}
// CSRF helper (validación centralizada)
require_once __DIR__ . '/config/csrf.php';
require 'check_password_change.php';

// Validar CSRF para cualquier petición POST entrante en esta página
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// --- Procesar formulario de creación de categorías ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_category'])) {
    $category_name = trim($_POST['category_name']);
    $category_color = $_POST['category_color'] ?? '#6c757d';

    if (!empty($category_name)) {
        try {
            // Verificar si la categoría ya existe para este usuario
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
            $stmt->execute([$user_id, $category_name]);

            if ($stmt->fetch()) {
                $_SESSION['flash_error'] = "Ya existe una categoría con ese nombre.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $category_name, $category_color]);
                $_SESSION['flash_success'] = "Categoría creada exitosamente.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Error al crear la categoría: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "El nombre de la categoría no puede estar vacío.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- Procesar eliminación de categoría ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = $_POST['category_id'];

    try {
        // Verificar si la categoría tiene gastos asociados
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ? AND user_id = ?");
        $stmt->execute([$category_id, $user_id]);
        $expense_count = $stmt->fetchColumn();

        if ($expense_count > 0) {
            $_SESSION['flash_error'] = "No puedes eliminar una categoría que tiene gastos asociados.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$category_id, $user_id]);
            $_SESSION['flash_success'] = "Categoría eliminada exitosamente.";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar la categoría: " . $e->getMessage();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Obtener mensajes flash de sesión
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';

// Limpiar mensajes flash después de obtenerlos
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// --- Manejo de Filtros y Variables de Tiempo ---
$current_year = date('Y');
$current_month = date('n');

$selected_year = $_GET['year'] ?? $current_year;
$selected_month = $_GET['month'] ?? $current_month;
$selected_category_id = $_GET['category_id'] ?? '';

// Obtener años y meses para los dropdowns
$years = range($current_year - 2, $current_year + 1);
$month_names = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
];

// Obtener categorías del usuario para el filtro y gestión
$stmt = $pdo->prepare("SELECT id, name, color FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$category_options = ['' => 'Todas las Categorías'];
foreach ($categories as $cat) {
    $category_options[$cat['id']] = $cat['name'];
}

// --- Obtener Resumen de Gastos por Categoría CON PRESUPUESTO ---
if ($hasBudgetCols) {
    $sql_summary = "
    SELECT 
        c.name, 
        c.color, 
        COALESCE(SUM(e.amount), 0) as total_spent,
        COALESCE(COALESCE(b.budget_amount, b.amount), 0) as budget_amount,
        CASE 
            WHEN COALESCE(COALESCE(b.budget_amount, b.amount), 0) > 0 
            THEN (COALESCE(SUM(e.amount), 0) / COALESCE(COALESCE(b.budget_amount, b.amount), 0)) * 100 
            ELSE 0 
        END as budget_percentage
    FROM categories c
    LEFT JOIN expenses e ON e.category_id = c.id 
        AND e.user_id = ? 
        AND e.year = ? 
        AND e.month = ?
    LEFT JOIN budgets b ON b.category_id = c.id 
        AND b.user_id = ? 
        AND b.year = ? 
        AND b.month = ?
    WHERE c.user_id = ?
    GROUP BY c.id, c.name, c.color, COALESCE(b.budget_amount, b.amount)
    ORDER BY total_spent DESC
";
} else {
    $sql_summary = "
    SELECT 
        c.name, 
        c.color, 
        COALESCE(SUM(e.amount), 0) as total_spent
    FROM categories c
    LEFT JOIN expenses e ON e.category_id = c.id 
        AND e.user_id = ? 
        AND e.year = ? 
        AND e.month = ?
    WHERE c.user_id = ?
    GROUP BY c.id, c.name, c.color
    ORDER BY total_spent DESC
";
}
$params_summary = [
    $user_id,
    $selected_year,
    $selected_month,  // Para expenses
    $user_id,
    $selected_year,
    $selected_month,  // Para budgets
    $user_id                                    // Para categories
];

$stmt_summary = $pdo->prepare($sql_summary);
$stmt_summary->execute($params_summary);
$category_summary = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

$total_gastos_resumen = array_sum(array_column($category_summary, 'total_spent'));

// Función de formato
function formatBs($amount)
{
    return number_format($amount, 2, ',', '.');
}

// Función para determinar el color del porcentaje (igual que en budgets.php)
function getPercentageColor($percentage)
{
    if ($percentage >= 100)
        return 'text-danger';
    if ($percentage >= 80)
        return 'text-warning';
    return 'text-success';
}

// Función para determinar el ícono del porcentaje
function getPercentageIcon($percentage)
{
    if ($percentage >= 100)
        return 'fas fa-exclamation-triangle';
    if ($percentage >= 80)
        return 'fas fa-exclamation-circle';
    return 'fas fa-check-circle';
}
?>

<style>
    /* ESTILOS IDÉNTICOS AL PRIMER CÓDIGO */
    .full-height-categories {
        min-height: calc(100vh - 120px);
        padding: 0;
    }

    .categories-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto 1fr;
        gap: 20px;
        height: 100%;
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
    .categories-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    /* Container Styles */
    .category-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
    }

    .management-container {
        flex: 1;
        min-height: 400px;
        display: flex;
        flex-direction: column;
    }

    .summary-container {
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

    /* Filter Bar Styles */
    .filter-bar {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
    }

    /* Category Color Badge */
    .category-color-badge {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
        border: 2px solid #fff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* Scrollable Areas */
    .scrollable-content {
        flex: 1;
        overflow-y: auto;
        max-height: 100%;
    }

    .scrollable-table {
        flex: 1;
        overflow-y: auto;
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

    /* Table Styles */
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

    .table-hover tbody tr:hover {
        background-color: rgba(102, 126, 234, 0.05);
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

    /* Button Actions */
    .btn-action {
        padding: 4px 8px;
        font-size: 0.75rem;
        border-radius: 6px;
    }

    /* Items Count */
    .items-count {
        font-size: 0.8rem;
        color: #6c757d;
        margin-left: 10px;
        font-weight: normal;
    }

    /* Badge Styles */
    .category-badge {
        font-size: 0.8rem;
        padding: 4px 8px;
        border-radius: 10px;
    }

    /* Progress bar para porcentaje */
    .budget-progress {
        height: 6px;
        border-radius: 3px;
        background: #e9ecef;
        overflow: hidden;
        margin: 8px 0;
    }

    .budget-progress-bar {
        height: 100%;
        border-radius: 3px;
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

    /* Flash Message Styles - INTEGRADAS EN EL FLUJO */
    .flash-message-container {
        grid-column: 1 / -1;
        margin-bottom: 20px;
    }

    .flash-message {
        border-radius: 12px;
        padding: 18px 25px;
        margin-bottom: 0;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        opacity: 1;
        transform: translateY(0);
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1);
        display: flex;
        align-items: center;
        position: relative;
        overflow: hidden;
    }

    .flash-message.hide {
        opacity: 0;
        transform: translateY(-20px);
        height: 0;
        padding: 0;
        margin: 0;
        overflow: hidden;
    }

    .flash-message::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 5px;
        border-radius: 5px 0 0 5px;
    }

    .flash-success {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        color: white;
    }

    .flash-success::before {
        background: rgba(255, 255, 255, 0.7);
    }

    .flash-error {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
    }

    .flash-error::before {
        background: rgba(255, 255, 255, 0.7);
    }

    .flash-message .flash-icon {
        font-size: 1.5rem;
        margin-right: 15px;
        flex-shrink: 0;
    }

    .flash-message .flash-content {
        flex-grow: 1;
        font-weight: 500;
    }

    .flash-message .btn-close {
        filter: invert(1);
        opacity: 0.8;
        flex-shrink: 0;
        margin-left: 15px;
        padding: 5px;
    }

    .flash-message .btn-close:hover {
        opacity: 1;
    }

    /* Progress bar para el tiempo de desaparición */
    .flash-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: rgba(255, 255, 255, 0.3);
        border-radius: 0 0 12px 12px;
        overflow: hidden;
    }

    .flash-progress-bar {
        height: 100%;
        background: rgba(255, 255, 255, 0.7);
        width: 100%;
        animation: progressBar 5s linear forwards;
    }

    @keyframes progressBar {
        from {
            width: 100%;
        }

        to {
            width: 0%;
        }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .categories-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .sidebar-section,
        .main-section {
            grid-column: 1;
        }

        .btn-action {
            width: 100%;
            margin-bottom: 5px;
        }

        .flash-message {
            padding: 15px 20px;
        }

        .flash-message .flash-icon {
            font-size: 1.3rem;
            margin-right: 12px;
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

    /* List Group Styles */
    .list-group-item {
        border: none;
        border-bottom: 1px solid #f0f0f0;
        padding: 15px 0;
    }

    .list-group-item:last-child {
        border-bottom: none;
    }

    /* Budget Info Styles */
    .budget-info {
        font-size: 0.8rem;
        color: #6c757d;
    }

    .budget-amount {
        color: #667eea;
        font-weight: 500;
    }

    .budget-remaining {
        color: #28a745;
        font-weight: 500;
    }

    .budget-exceeded {
        color: #dc3545;
        font-weight: 500;
    }

    /* Percentage Display */
    .percentage-display {
        font-size: 0.85rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }
</style>

<?php require 'includes/header.php'; ?>

<div class="full-height-categories">
    <!-- Header -->
    <div class="categories-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1">🏷️ Gestión de Categorías</h2>
                <p class="mb-0">Administra tus categorías y visualiza gastos por período</p>
            </div>
        </div>
    </div>

    <!-- Mensajes Flash Integrados -->
    <?php if ($flash_success || $flash_error): ?>
        <div class="flash-message-container">
            <?php if ($flash_success): ?>
                <div class="flash-message flash-success alert fade show" role="alert" id="flash-success">
                    <i class="fas fa-check-circle flash-icon"></i>
                    <div class="flash-content">
                        <?= $flash_success ?>
                    </div>
                    <button type="button" class="btn-close" onclick="hideFlashMessage('flash-success')"
                        aria-label="Cerrar"></button>
                    <div class="flash-progress">
                        <div class="flash-progress-bar"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="flash-message flash-error alert fade show" role="alert" id="flash-error">
                    <i class="fas fa-times-circle flash-icon"></i>
                    <div class="flash-content">
                        <?= $flash_error ?>
                    </div>
                    <button type="button" class="btn-close" onclick="hideFlashMessage('flash-error')"
                        aria-label="Cerrar"></button>
                    <div class="flash-progress">
                        <div class="flash-progress-bar"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="filter-bar mb-4">
        <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filtros</h5>
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="month" class="form-label small mb-0 fw-bold">Mes</label>
                <select class="form-select form-select-sm" id="month" name="month" onchange="this.form.submit()">
                    <?php foreach ($month_names as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $selected_month == $num ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="year" class="form-label small mb-0 fw-bold">Año</label>
                <select class="form-select form-select-sm" id="year" name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="category_id" class="form-label small mb-0 fw-bold">Filtrar por Categoría</label>
                <select class="form-select form-select-sm" id="category_id" name="category_id"
                    onchange="this.form.submit()">
                    <?php foreach ($category_options as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $selected_category_id == $id ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <a href="expenses.php" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fas fa-undo me-1"></i> Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- Main Content Grid -->
    <div class="categories-grid" style="min-height: 600px;">
        <!-- Left Column - Gestión de Categorías -->
        <div class="sidebar-section">
            <div class="category-container management-container">
                <div class="section-title">
                    <i class="fas fa-tags me-2"></i>Gestión de Categorías
                    <span class="badge bg-primary category-badge"><?= count($categories) ?></span>
                </div>

                <div class="scrollable-content">
                    <?php if (!empty($categories)): ?>
                        <div class="scrollable-table">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Color</th>
                                        <th>Nombre</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                        <tr>
                                            <td>
                                                <span class="category-color-badge"
                                                    style="background-color: <?= $category['color'] ?>;"></span>
                                            </td>
                                            <td class="fw-medium"><?= htmlspecialchars($category['name']) ?></td>
                                            <td class="text-end">
                                                <form method="POST" style="display: inline-block;"
                                                    onsubmit="return confirm('¿Estás seguro de que quieres eliminar la categoría: <?= htmlspecialchars($category['name']) ?>?');">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                                    <button type="submit" name="delete_category"
                                                        class="btn btn-outline-danger btn-sm btn-action">
                                                        <i class="fas fa-trash"></i> Eliminar
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-tags"></i>
                            <p>No hay categorías creadas</p>
                            <small>Crea tu primera categoría</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Formulario para crear categorías -->
                <div class="mt-3 pt-3 border-top">
                    <h6 class="mb-3">➕ Crear Nueva Categoría</h6>
                    <form method="POST" class="row g-2">
                        <?= csrf_input() ?>
                        <div class="col-md-5">
                            <input type="text" name="category_name" class="form-control form-control-sm"
                                placeholder="Nombre de categoría" required>
                        </div>
                        <div class="col-md-4">
                            <input type="color" name="category_color" class="form-control form-control-sm"
                                value="#6c757d" title="Seleccionar color">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="create_category" class="btn btn-success btn-sm w-100">
                                <i class="fas fa-plus me-1"></i>Crear
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column - Resumen por Categoría CON PRESUPUESTO -->
        <div class="main-section">
            <div class="category-container summary-container">
                <div class="section-title">
                    <i class="fas fa-chart-bar me-2"></i>Resumen por Categoría
                    <span class="badge bg-primary category-badge"><?= $month_names[$selected_month] ?>
                        <?= $selected_year ?></span>
                </div>
                <div class="scrollable-content">
                    <?php if (!empty($category_summary)): ?>
                        <div class="scrollable-list">
                            <div class="list-group list-group-flush">
                                <?php foreach ($category_summary as $item):
                                    if (!$hasBudgetCols) {
                                        // en esquema antiguo no hay datos de presupuesto
                                        $item['budget_amount'] = 0;
                                        $item['budget_percentage'] = 0;
                                    }
                                    $budget_percentage = $item['budget_percentage'];
                                    $progress_class = $budget_percentage >= 100 ? 'progress-danger' : ($budget_percentage >= 80 ? 'progress-warning' : 'progress-safe');
                                    $remaining = $item['budget_amount'] - $item['total_spent'];
                                    $percentage_color = getPercentageColor($budget_percentage);
                                    $percentage_icon = getPercentageIcon($budget_percentage);
                                    ?>
                                    <div class="list-group-item px-0 py-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="d-flex align-items-center">
                                                <span class="category-color-badge"
                                                    style="background-color: <?= $item['color'] ?>;"></span>
                                                <span class="fw-medium"><?= htmlspecialchars($item['name']) ?></span>
                                            </div>
                                            <div class="text-end">
                                                <div class="percentage-display <?= $percentage_color ?>">
                                                    <i class="<?= $percentage_icon ?>"></i>
                                                    <?= number_format($budget_percentage, 1) ?>%
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Barra de progreso -->
                                        <div class="budget-progress">
                                            <div class="budget-progress-bar <?= $progress_class ?>"
                                                style="width: <?= min($budget_percentage, 100) ?>%"></div>
                                        </div>

                                        <div class="row text-sm mt-2">
                                            <div class="col-6">
                                                <div class="budget-info">
                                                    <strong>Gastado:</strong><br>
                                                    <span class="fw-bold">Bs <?= formatBs($item['total_spent']) ?></span>
                                                </div>
                                            </div>
                                            <div class="col-6 text-end">
                                                <div class="budget-info">
                                                    <strong>Presupuesto:</strong><br>
                                                    <span class="budget-amount">Bs
                                                        <?= formatBs($item['budget_amount']) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="text-center mt-2">
                                            <small class="<?= $remaining >= 0 ? 'budget-remaining' : 'budget-exceeded' ?>">
                                                <strong>
                                                    <?= $remaining >= 0 ? 'Restante: Bs ' . formatBs($remaining) : 'Excedido: Bs ' . formatBs(abs($remaining)) ?>
                                                </strong>
                                            </small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-chart-bar"></i>
                            <p>No hay gastos por categoría</p>
                            <small>No hay gastos para <?= $month_names[$selected_month] ?>     <?= $selected_year ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>

<script>
    // Función para ocultar mensajes flash
    function hideFlashMessage(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.add('hide');
            // Remover completamente después de la animación
            setTimeout(() => {
                if (element.parentNode) {
                    element.parentNode.remove();
                }
            }, 400);
        }
    }

    // Auto-ocultar mensajes después de 5 segundos
    document.addEventListener('DOMContentLoaded', function () {
        // Ocultar mensajes de éxito después de 5 segundos
        const successMessage = document.getElementById('flash-success');
        if (successMessage) {
            setTimeout(() => {
                hideFlashMessage('flash-success');
            }, 5000);
        }

        // Ocultar mensajes de error después de 5 segundos
        const errorMessage = document.getElementById('flash-error');
        if (errorMessage) {
            setTimeout(() => {
                hideFlashMessage('flash-error');
            }, 5000);
        }

        // También permitir cerrar haciendo clic en cualquier parte del mensaje
        const flashMessages = document.querySelectorAll('.flash-message');
        flashMessages.forEach(message => {
            message.addEventListener('click', function (e) {
                if (!e.target.classList.contains('btn-close') &&
                    !e.target.closest('.btn-close')) {
                    hideFlashMessage(this.id);
                }
            });
        });
    });
</script>