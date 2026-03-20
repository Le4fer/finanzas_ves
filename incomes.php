<?php
require 'includes/auth.php';
require 'config/db.php';
// CSRF helper
require_once __DIR__ . '/config/csrf.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    validate_csrf();
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $month = (int) $_POST['month'];
    $year = (int) $_POST['year'];
    $description = trim($_POST['description']);

    if ($amount <= 0 || $month < 1 || $month > 12 || $year < 2000 || $year > 2030) {
        // Guardar mensaje de error en sesión
        $_SESSION['flash_notification'] = [
            'type' => 'error',
            'title' => '❌ Error',
            'message' => 'Datos inválidos. Por favor, verifica la información ingresada.'
        ];
    } else {
        // Registrar el ingreso
        $pdo->prepare("INSERT INTO incomes (user_id, amount, month, year, description) VALUES (?, ?, ?, ?, ?)")
            ->execute([$user_id, $amount, $month, $year, $description]);

        // Guardar mensaje de éxito en sesión
        $_SESSION['flash_notification'] = [
            'type' => 'success',
            'title' => '✅ Ingreso registrado exitosamente!',
            'message' => '• Se registró un ingreso de <strong>Bs ' . number_format($amount, 2, ',', '.') . '</strong><br>' .
                '• Para el período: ' . getMonthName($month) . ' ' . $year
        ];
    }

    // Redirigir para mostrar la notificación
    header("Location: incomes.php");
    exit;
}

// Función para obtener nombre del mes en español
function getMonthName($month)
{
    $months = [
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
    return $months[$month] ?? 'Mes desconocido';
}
?>

<?php require 'includes/header.php'; ?>

<style>
    .full-height-income {
        min-height: calc(100vh - 120px);
        padding: 0;
    }

    .income-grid {
        display: grid;
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr;
        gap: 20px;
        height: 100%;
    }

    .form-section {
        grid-column: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Header Styles */
    .income-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    /* Form Container */
    .form-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
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

    /* Button Styles */
    .btn-success {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        border: none;
        border-radius: 8px;
        padding: 12px 24px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(67, 233, 123, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        border: none;
        border-radius: 8px;
        padding: 12px 24px;
        font-weight: 600;
        color: #2c3e50;
        transition: all 0.3s ease;
    }

    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(168, 237, 234, 0.3);
        color: #2c3e50;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .form-container {
            padding: 20px;
        }

        .income-header {
            padding: 20px;
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

    /* Form row adjustments */
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="full-height-income">
    <!-- Header -->
    <div class="income-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1">💰 Registrar Nuevo Ingreso</h2>
                <p class="mb-0">Agrega nuevos ingresos a tu cuenta personal</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="fas fa-plus-circle fa-2x opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="income-grid" style="min-height: 400px;">
        <!-- Form Section -->
        <div class="form-section">
            <div class="form-container">
                <div class="section-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Formulario de Ingreso
                </div>

                <form method="POST">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label">Monto (Bs) *</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="0.01"
                            placeholder="0.00">
                        <div class="form-text">
                            Ingresa el monto del ingreso en Bolívares
                        </div>
                    </div>

                    <div class="form-row mb-3">
                        <div>
                            <label class="form-label">Mes *</label>
                            <select name="month" class="form-select" required>
                                <?php
                                $current_month = date('n');
                                for ($m = 1; $m <= 12; $m++):
                                    ?>
                                    <option value="<?= $m ?>" <?= $m == $current_month ? 'selected' : '' ?>>
                                        <?= getMonthName($m) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Año *</label>
                            <select name="year" class="form-select" required>
                                <?php
                                $current_year = date('Y');
                                for ($y = $current_year; $y >= 2020; $y--):
                                    ?>
                                    <option value="<?= $y ?>" <?= $y == $current_year ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descripción (opcional)</label>
                        <textarea name="description" class="form-control" rows="3"
                            placeholder="Describe el origen de este ingreso..."></textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-plus-circle me-2"></i>Registrar Ingreso
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                        </a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>