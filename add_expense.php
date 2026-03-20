<?php
session_start();
require 'config/db.php';
// Cargar CSRF helper para validar formularios POST
require_once __DIR__ . '/config/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Obtener categorías del usuario
$stmt = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

// Obtener datos del usuario para el nuevo sistema
$stmt = $pdo->prepare("SELECT current_balance, available_for_spending FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$current_balance = $user_data['current_balance'];
$available_for_spending = $user_data['available_for_spending'];

// Obtener meses y años para el dropdown
$current_year = date('Y');
$current_month = date('n');
$years = range($current_year - 1, $current_year + 1);

// Inicializar variables para el presupuesto de la categoría
$category_budget_remaining = 0;
$selected_category_id = $_POST['category_id'] ?? '';
$selected_category_name = '';

// Verificar si la tabla budgets ya tiene las columnas nuevas
$hasBudgetCols = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'budget_amount'");
    if ($stmt && $stmt->fetch()) {
        $hasBudgetCols = true;
    }
} catch (Exception $e) {
    // la tabla podría no existir aún o no tener columnas, ignorar
}

// Si se ha seleccionado una categoría, obtener su presupuesto disponible
if ($selected_category_id) {
    // Obtener nombre de la categoría
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$selected_category_id]);
    $category_data = $stmt->fetch();
    $selected_category_name = $category_data['name'] ?? '';
    
    if ($hasBudgetCols) {
        // Obtener presupuesto de la categoría
        $stmt = $pdo->prepare("
            SELECT COALESCE(budget_amount, amount) AS budget_amount, COALESCE(spent_amount,0) AS spent_amount
            FROM budgets 
            WHERE user_id = ? AND category_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$user_id, $selected_category_id, $current_month, $current_year]);
        $budget_info = $stmt->fetch();
        
        if ($budget_info) {
            $category_budget_remaining = $budget_info['budget_amount'] - $budget_info['spent_amount'];
        }
    }

    $category_id = intval($_POST['category_id']);
    $description = trim($_POST['description']);
    $month = intval($_POST['month']);
    $year = intval($_POST['year']);

    if ($amount <= 0) {
        $error = "El monto debe ser mayor a 0.";
    } elseif ($category_id <= 0) {
        $error = "Debes seleccionar una categoría.";
    } elseif ($month < 1 || $month > 12) {
        $error = "Mes inválido.";
    } else {
        try {
            // Verificar presupuesto de la categoría si la tabla ya está migrada
            $category_remaining = 0;
            if ($hasBudgetCols) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(budget_amount, amount) AS budget_amount, COALESCE(spent_amount,0) AS spent_amount
                    FROM budgets 
                    WHERE user_id = ? AND category_id = ? AND month = ? AND year = ?
                ");
                $stmt->execute([$user_id, $category_id, $month, $year]);
                $budget_data = $stmt->fetch();
                if ($budget_data) {
                    $category_remaining = $budget_data['budget_amount'] - $budget_data['spent_amount'];
                    if ($amount > $category_remaining) {
                        $error = "❌ No tienes suficiente presupuesto en esta categoría. Disponible: Bs " . number_format($category_remaining, 2, ',', '.');
                    }
                } else {
                    $error = "❌ Esta categoría no tiene presupuesto asignado para el mes seleccionado.";
                }
            }
            
            // Si no hay error de presupuesto, proceder con el gasto
            if (empty($error)) {
                // Iniciar transacción para asegurar consistencia
                $pdo->beginTransaction();
                
                // 1. Insertar el gasto
                $stmt = $pdo->prepare("INSERT INTO expenses (user_id, category_id, amount, month, year, description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $category_id, $amount, $month, $year, $description]);
                
                // 2. Actualizar el saldo actual (RESTAR el gasto)
                require 'update_balance.php';
                $new_balance = updateBalanceOnExpense($user_id, $amount, $pdo);
                
                // 3. ACTUALIZACIÓN NUEVA: Restar del available_for_spending
                $stmt = $pdo->prepare("UPDATE users SET available_for_spending = available_for_spending - ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                
                    // 4. NUEVO: Actualizar el presupuesto de la categoría si la columna existe
                if ($hasBudgetCols) {
                    $stmt = $pdo->prepare("
                        UPDATE budgets 
                        SET spent_amount = spent_amount + ? 
                        WHERE user_id = ? AND category_id = ? AND month = ? AND year = ?
                    ");
                    $stmt->execute([$amount, $user_id, $category_id, $month, $year]);
                }
                
                // Confirmar transacción
                $pdo->commit();
                
                // Obtener el nombre de la categoría para el mensaje
                $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $category_name = $stmt->fetchColumn();
                
                // Calcular nuevo saldo disponible en la categoría
                $new_category_remaining = $category_remaining - $amount;
                
                $success = "✅ Gasto registrado exitosamente!<br>";
                $success .= "• Se descontó Bs " . number_format($amount, 2, ',', '.') . " de la categoría <strong>" . htmlspecialchars($category_name) . "</strong><br>";
                $success .= "• Saldo restante en la categoría: <strong>Bs " . number_format($new_category_remaining, 2, ',', '.') . "</strong>";
                
                // Verificar si se superó el presupuesto
                if ($new_category_remaining <= 0) {
                    $success .= "<br>⚠️ <strong>Alerta:</strong> Has agotado el presupuesto de esta categoría.";
                }
                
                // Actualizar variables locales
                $available_for_spending -= $amount;
                $current_balance -= $amount;
                
                // Actualizar el presupuesto restante para la categoría seleccionada
                if ($selected_category_id == $category_id) {
                    $category_budget_remaining = $new_category_remaining;
                }
                
                // Limpiar campos excepto la categoría seleccionada
                $_POST['amount'] = '';
                $_POST['description'] = '';
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "❌ Error al registrar el gasto: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Gasto - Finanzas Personales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .full-height-expense {
            min-height: calc(100vh - 120px);
            padding: 0;
        }

        .expense-grid {
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto auto 1fr;
            gap: 20px;
            height: 100%;
        }

        .kpi-section {
            grid-column: 1 / -1;
        }

        .form-section {
            grid-column: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* KPI Cards */
        .kpi-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            height: 140px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card.balance {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .kpi-card.available {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .kpi-card.budget {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #2c3e50;
        }

        .kpi-card.warning {
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

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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

        /* Form Styles */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 10px 15px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Alert Styles */
        .alert-container {
            grid-column: 1 / -1;
        }

        /* Badge Styles */
        .budget-badge {
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 10px;
        }

        /* Budget Info */
        .budget-info {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            border-left: 4px solid #667eea;
        }

        /* Validation Styles */
        .validation-message {
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }

        .validation-error {
            color: #bd767dff;
            font-weight: 500;
        }

        .validation-success {
            color: #28a745;
            font-weight: 500;
        }

        .btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .kpi-card {
                height: 120px;
                padding: 15px;
            }
            
            .kpi-number {
                font-size: 1.5rem;
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

        .warning-text {
            color: #ffc107;
            font-weight: bold;
        }

        .success-text {
            color: #28a745;
            font-weight: bold;
        }

        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">
<?php require 'includes/header.php'; ?>

<div class="full-height-expense">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">💰 Registrar Nuevo Gasto</h2>
            <p class="mb-0 text-muted">Registra tus gastos por categoría y controla tu presupuesto</p>
        </div>
    </div>

    <!-- Alertas -->
    <?php if (!empty($error)): ?>
        <div class="alert-container">
            <div class="alert alert-danger">
                <i class="fas fa-times-circle me-2"></i>
                <?= $error ?>
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
            <!-- Saldo Total -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card balance">
                    <div class="kpi-label">Saldo Total</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= number_format($current_balance, 2, ',', '.') ?>
                    </div>
                    <div class="kpi-subamount">Balance general de tu cuenta</div>
                    <i class="fas fa-money-bill-wave kpi-icon"></i>
                </div>
            </div>

            <!-- Disponible para Gastar -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card available">
                    <div class="kpi-label">Disponible General</div>
                    <div class="kpi-number">
                        <span class="kpi-currency">Bs</span><?= number_format($available_for_spending, 2, ',', '.') ?>
                    </div>
                    <div class="kpi-subamount">Saldo total disponible</div>
                    <i class="fas fa-wallet kpi-icon"></i>
                </div>
            </div>

            <!-- Información de Presupuestos -->
            <?php
            // Verificar si hay presupuestos activos para este mes
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as budget_count 
                FROM budgets 
                WHERE user_id = ? AND month = ? AND year = ?
            ");
            $stmt->execute([$user_id, $current_month, $current_year]);
            $budget_count = $stmt->fetchColumn();
            ?>
            
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card budget">
                    <div class="kpi-label">Presupuestos Activos</div>
                    <div class="kpi-number"><?= $budget_count ?></div>
                    <div class="kpi-subamount">Categorías con presupuesto</div>
                    <i class="fas fa-chart-pie kpi-icon"></i>
                </div>
            </div>

            <!-- Estado -->
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card <?= $budget_count > 0 ? 'available' : 'warning' ?>">
                    <div class="kpi-label">Sistema</div>
                    <div class="kpi-number"><?= $budget_count > 0 ? 'Activo' : 'Sin Presupuestos' ?></div>
                    <div class="kpi-subamount">
                        <?= $budget_count > 0 ? 'Presupuestos configurados' : 'Configura presupuestos' ?>
                    </div>
                    <i class="fas fa-info-circle kpi-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="expense-grid" style="min-height: 400px;">
        <!-- Form Section -->
        <div class="form-section">
            <div class="form-container">
                <div class="section-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Formulario de Gasto por Categoría
                </div>
                
                <form method="POST" id="expenseForm">
                    <?= csrf_input() ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Categoría *</label>
                                <select class="form-select" id="category_id" name="category_id" required <?= empty($categories) ? 'disabled' : '' ?>>
                                    <option value="">Selecciona una categoría</option>
                                    <?php foreach ($categories as $cat): 
                                        // Verificar si esta categoría tiene presupuesto (si la tabla ya está migrada)
                                        $has_budget = false;
                                        $remaining = 0;
                                        if ($hasBudgetCols) {
                                            $stmt = $pdo->prepare("
                                                SELECT COALESCE(budget_amount, amount) AS budget_amount, COALESCE(spent_amount,0) AS spent_amount 
                                                FROM budgets 
                                                WHERE user_id = ? AND category_id = ? AND month = ? AND year = ?
                                            ");
                                            $stmt->execute([$user_id, $cat['id'], $current_month, $current_year]);
                                            $budget_info = $stmt->fetch();
                                            $has_budget = $budget_info !== false;
                                            if ($has_budget) {
                                                $remaining = $budget_info['budget_amount'] - $budget_info['spent_amount'];
                                            }
                                        }
                                    ?>
                                        <option value="<?= $cat['id'] ?>" 
                                                <?= ($_POST['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>
                                                data-budget-remaining="<?= $remaining ?>"
                                                data-has-budget="<?= $has_budget ? 'true' : 'false' ?>">
                                            <?= htmlspecialchars($cat['name']) ?>
                                            <?php if ($has_budget): ?>
                                                (Presupuesto: Bs <?= number_format($remaining, 2, ',', '.') ?>)
                                            <?php else: ?>
                                                (Sin presupuesto)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($categories)): ?>
                                    <div class="text-danger small mt-1">
                                        No tienes categorías. <a href="categories.php">Crea una categoría primero</a>.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Monto a Gastar (Bs) *</label>
                                <input type="number" step="0.01" min="0.01" 
                                       class="form-control" id="amount" name="amount" 
                                       value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required
                                       placeholder="Ej: 150.50">
                                <div class="form-text">
                                    Este monto se descontará del <strong>presupuesto de la categoría seleccionada</strong>.
                                </div>
                                <!-- Mensaje de validación en tiempo real -->
                                <div id="amountValidation" class="validation-message"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Información del presupuesto de la categoría -->
                    <?php if ($selected_category_id && $category_budget_remaining > 0): ?>
                        <div class="budget-info">
                            <div class="row">
                                <div class="col-12">
                                    <strong>💰 Presupuesto disponible en "<?= htmlspecialchars($selected_category_name) ?>":</strong>
                                    <div class="fs-5 success-text mt-1">
                                        Bs <?= number_format($category_budget_remaining, 2, ',', '.') ?>
                                    </div>
                                    <small class="text-muted">Puedes gastar hasta este monto en la categoría seleccionada</small>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($selected_category_id && $category_budget_remaining <= 0): ?>
                        <div class="alert alert-warning mt-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>La categoría "<?= htmlspecialchars($selected_category_name) ?>" no tiene presupuesto disponible.</strong>
                            Asigna un presupuesto en la página de <a href="budgets.php" class="alert-link">gestión de presupuestos</a>.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mt-2">
                            <i class="fas fa-info-circle me-2"></i>
                            Selecciona una categoría para ver el presupuesto disponible.
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="description" class="form-label">Descripción del gasto</label>
                        <textarea class="form-control" id="description" name="description" rows="2" 
                                  placeholder="Describe en qué gastaste este dinero..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="month" class="form-label">Mes *</label>
                                <select class="form-select" id="month" name="month" required>
                                    <?php
                                    $month_names = [
                                        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                                        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                                        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
                                    ];
                                    foreach ($month_names as $num => $name): ?>
                                        <option value="<?= $num ?>" <?= ($_POST['month'] ?? $current_month) == $num ? 'selected' : '' ?>>
                                            <?= $name ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="year" class="form-label">Año *</label>
                                <select class="form-select" id="year" name="year" required>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?= $y ?>" <?= ($_POST['year'] ?? $current_year) == $y ? 'selected' : '' ?>>
                                            <?= $y ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-danger btn-lg" id="submitBtn" <?= (empty($categories)) ? 'disabled' : '' ?>>
                            <i class="fas fa-minus-circle me-2"></i>Registrar Gasto en la Categoría
                        </button>
                        <div class="d-flex gap-2">
                            <a href="budgets.php" class="btn btn-info flex-fill">
                                <i class="fas fa-chart-pie me-2"></i>Gestionar Presupuestos
                            </a>
                            <a href="dashboard.php" class="btn btn-secondary flex-fill">
                                <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales
let currentBudgetRemaining = <?= $category_budget_remaining ?>;
let currentCategoryHasBudget = <?= $selected_category_id && $category_budget_remaining > 0 ? 'true' : 'false' ?>;

// Actualizar la información del presupuesto cuando se cambia la categoría
document.getElementById('category_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    currentBudgetRemaining = parseFloat(selectedOption.getAttribute('data-budget-remaining')) || 0;
    currentCategoryHasBudget = selectedOption.getAttribute('data-has-budget') === 'true';
    
    // Forzar el envío del formulario para actualizar la información del presupuesto
    document.getElementById('expenseForm').submit();
});

// Validación en tiempo real del monto
document.getElementById('amount').addEventListener('input', function() {
    const amount = parseFloat(this.value) || 0;
    const validationMessage = document.getElementById('amountValidation');
    const submitBtn = document.getElementById('submitBtn');
    
    if (amount <= 0) {
        validationMessage.textContent = '❌ El monto debe ser mayor a 0';
        validationMessage.className = 'validation-message validation-error';
        validationMessage.style.display = 'block';
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-disabled');
    } else if (!currentCategoryHasBudget) {
        validationMessage.textContent = '❌ Esta categoría no tiene presupuesto asignado';
        validationMessage.className = 'validation-message validation-error';
        validationMessage.style.display = 'block';
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-disabled');
    } else if (amount > currentBudgetRemaining) {
        validationMessage.textContent = `❌ No puedes gastar más de Bs ${currentBudgetRemaining.toFixed(2)} en esta categoría`;
        validationMessage.className = 'validation-message validation-error';
        validationMessage.style.display = 'block';
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-disabled');
    } else {
        validationMessage.textContent = `✅ Monto válido. Disponible: Bs ${currentBudgetRemaining.toFixed(2)}`;
        validationMessage.className = 'validation-message validation-success';
        validationMessage.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.classList.remove('btn-disabled');
    }
    
    // Ocultar mensaje si el campo está vacío
    if (this.value === '') {
        validationMessage.style.display = 'none';
    }
});

// Validación al enviar el formulario (como respaldo)
document.getElementById('expenseForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    
    if (!currentCategoryHasBudget) {
        e.preventDefault();
        alert('❌ Esta categoría no tiene presupuesto asignado. Asigna un presupuesto primero.');
        return false;
    }
    
    if (amount > currentBudgetRemaining) {
        e.preventDefault();
        alert(`❌ No puedes gastar más de Bs ${currentBudgetRemaining.toFixed(2)} en esta categoría.`);
        return false;
    }
});
</script>

<?php require 'includes/footer.php'; ?>
</body>
</html>