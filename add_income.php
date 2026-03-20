<?php
session_start();
require 'config/db.php';
// Cargar helper CSRF para proteger formularios
require_once __DIR__ . '/config/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Obtener datos del usuario para el nuevo sistema
$stmt = $pdo->prepare("SELECT current_balance, available_for_spending FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$current_balance = $user_data['current_balance'];
$available_for_spending = $user_data['available_for_spending'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    validate_csrf();
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $month = intval($_POST['month']);
    $year = intval($_POST['year']);

    if ($amount <= 0) {
        $error = "El monto debe ser mayor a 0.";
    } else {
        try {
            // Iniciar transacción
            $pdo->beginTransaction();

            // 1. Insertar el ingreso
            $stmt = $pdo->prepare("INSERT INTO incomes (user_id, amount, month, year, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $amount, $month, $year, $description]);

            // 2. Actualizar el saldo actual usando la función existente
            require 'update_balance.php';
            $new_balance = updateBalanceOnIncome($user_id, $amount, $pdo);

            // 3. Sumar al available_for_spending
            $stmt = $pdo->prepare("UPDATE users SET available_for_spending = available_for_spending + ? WHERE id = ?");
            $stmt->execute([$amount, $user_id]);

            // Confirmar transacción
            $pdo->commit();

            $success = "Ingreso registrado exitosamente! Se agregó Bs " . number_format($amount, 2, ',', '.') . " a tu saldo total y disponible.";

            // Actualizar variables locales
            $current_balance = $new_balance;
            $available_for_spending += $amount;

            // Limpiar campos
            $_POST = [];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al registrar el ingreso: " . $e->getMessage();
        }
    }
}

$current_year = date('Y');
$current_month = date('n');
$years = range($current_year - 1, $current_year + 1);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Registrar Ingreso - Finanzas Personales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .balance-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .available-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .balance-amount {
            font-size: 1.8rem;
            font-weight: bold;
        }

        .available-amount {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .budget-info {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body class="bg-light">
    <?php require 'includes/header.php'; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <!-- Mostrar Saldo Total -->
                <div class="balance-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small>Saldo Total</small>
                            <div class="balance-amount">Bs <?= number_format($current_balance, 2, ',', '.') ?></div>
                        </div>
                        <i class="fas fa-money-bill-wave fa-2x opacity-50"></i>
                    </div>
                </div>

                <!-- Mostrar Disponible para Gastar -->
                <div class="available-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small>Disponible para Gastar</small>
                            <div class="available-amount">Bs <?= number_format($available_for_spending, 2, ',', '.') ?>
                            </div>
                            <small class="opacity-75">Este monto aumentará con el nuevo ingreso</small>
                        </div>
                        <i class="fas fa-wallet fa-2x opacity-50"></i>
                    </div>
                </div>

                <!-- Información de Presupuestos -->
                <?php
                // Verificar presupuestos activos
                $stmt = $pdo->prepare("
                SELECT COUNT(*) as budget_count 
                FROM budgets 
                WHERE user_id = ? AND month = ? AND year = ?
            ");
                $stmt->execute([$user_id, $current_month, $current_year]);
                $budget_count = $stmt->fetchColumn();

                if ($budget_count > 0): ?>
                    <div class="budget-info">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small>💰 Sistema de Presupuestos Activo</small>
                                <div class="available-amount"><?= $budget_count ?> categorías con presupuesto</div>
                                <small class="opacity-75">Este ingreso estará disponible para asignar a presupuestos</small>
                            </div>
                            <i class="fas fa-chart-pie fa-2x opacity-50"></i>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Registrar Nuevo Ingreso</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label for="amount" class="form-label">Monto a Agregar *</label>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="amount"
                                    name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required
                                    placeholder="Ej: 2500.00">
                                <div class="form-text">
                                    Este monto se agregará a tu <strong>saldo total</strong> y estará <strong>disponible
                                        para gastar</strong> inmediatamente.
                                    <?php if ($budget_count > 0): ?>
                                        <br><span class="text-info">💡 Luego puedes asignarlo a presupuestos
                                            específicos</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Descripción</label>
                                <textarea class="form-control" id="description" name="description" rows="2"
                                    placeholder="¿De dónde proviene este ingreso?"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="month" class="form-label">Mes *</label>
                                        <select class="form-select" id="month" name="month" required>
                                            <?php
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
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus-circle me-2"></i>Agregar Ingreso al Saldo
                                </button>
                                <?php if ($budget_count > 0): ?>
                                    <a href="budgets.php" class="btn btn-info">
                                        <i class="fas fa-chart-pie me-2"></i>Asignar a Presupuestos
                                    </a>
                                <?php else: ?>
                                    <a href="budgets.php" class="btn btn-outline-info">
                                        <i class="fas fa-chart-pie me-2"></i>Configurar Presupuestos
                                    </a>
                                <?php endif; ?>
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require 'includes/footer.php'; ?>
</body>

</html>