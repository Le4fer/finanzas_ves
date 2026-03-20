<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';

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

// Obtener categorías del usuario para el filtro
$stmt = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$category_options = ['' => 'Todas las Categorías'] + $categories;

// Función de formato
function format_currency($amount)
{
    return "Bs " . number_format($amount, 2, ',', '.');
}


// --- Lógica de Obtención de Datos de Gastos ---

// 1. Obtener el total de gastos del período seleccionado (para el KPI principal)
$sql_total_gastos_month = "SELECT SUM(amount) FROM expenses WHERE user_id = ? AND year = ? AND month = ?";
$stmt_total_gastos_month = $pdo->prepare($sql_total_gastos_month);
$stmt_total_gastos_month->execute([$user_id, $selected_year, $selected_month]);
$total_gastos_periodo = $stmt_total_gastos_month->fetchColumn() ?? 0;

// 2. Obtener la cantidad de transacciones del período seleccionado (para el KPI principal)
$sql_total_transactions = "SELECT COUNT(id) FROM expenses WHERE user_id = ? AND year = ? AND month = ?";
$stmt_total_transactions = $pdo->prepare($sql_total_transactions);
$stmt_total_transactions->execute([$user_id, $selected_year, $selected_month]);
$total_transactions_periodo = $stmt_total_transactions->fetchColumn() ?? 0;


// 3. Obtener el Resumen de Gastos por Categoría para el GRÁFICO (NO USA EL FILTRO DE CATEGORÍA)
$sql_summary = "
    SELECT c.name, c.color, SUM(e.amount) as total_spent
    FROM expenses e
    JOIN categories c ON e.category_id = c.id
    WHERE e.user_id = ? AND e.year = ? AND e.month = ?
    GROUP BY c.name, c.color ORDER BY total_spent DESC
";
$params_summary = [$user_id, $selected_year, $selected_month];

$stmt_summary = $pdo->prepare($sql_summary);
$stmt_summary->execute($params_summary);
$category_summary = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

// Recalcular el total basado en el resumen
$total_gastos_resumen = array_sum(array_column($category_summary, 'total_spent'));


// 4. Obtener la Lista Detallada de Gastos (USA EL FILTRO DE CATEGORÍA)
$sql_list = "
    SELECT e.amount, e.description, e.created_at, c.name as category_name
    FROM expenses e
    JOIN categories c ON e.category_id = c.id
    WHERE e.user_id = ? AND e.year = ? AND e.month = ?
";
$params_list = [$user_id, $selected_year, $selected_month];

if ($selected_category_id) {
    $sql_list .= " AND e.category_id = ?";
    $params_list[] = $selected_category_id;
}

$sql_list .= " ORDER BY e.created_at DESC";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params_list);
$expense_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// Lógica para mostrar la información del resumen de categoría si se aplica un filtro
$filtered_category_info = [];
if ($selected_category_id) {
    // Buscar el gasto total de la categoría filtrada en el resumen ya calculado
    foreach ($category_summary as $item) {
        if (isset($categories[$selected_category_id]) && $item['name'] == $categories[$selected_category_id]) {
            $filtered_category_info = [
                'name' => $item['name'],
                'total_spent' => $item['total_spent'],
                'percentage' => $total_gastos_resumen > 0 ? ($item['total_spent'] / $total_gastos_resumen) * 100 : 0
            ];
            break;
        }
    }
}


// --- INCLUSIÓN DE COMPONENTES DE VISTA ---
require 'includes/header.php';
?>

<style>
    /* Estilos CSS - Se eliminaron los estilos de .kpi-card que ya no se usan */
    .full-height-expenses {
        min-height: calc(100vh - 120px);
        padding: 0;
    }

    .expenses-grid {
        /* Se ajustó la grilla para que la columna principal ocupe todo el ancho */
        display: grid;
        grid-template-columns: 1fr 2fr;
        /* Mantengo la estructura para el gráfico/lista */
        gap: 20px;
        height: 100%;
    }

    .expenses-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    /* Contenedores */
    .expenses-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
    }

    .filters-container {
        height: auto;
    }

    .chart-container {
        flex: 1;
        min-height: 400px;
        display: flex;
        flex-direction: column;
    }

    .list-container {
        flex: 1;
        min-height: 600px;
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

    /* Chart Styles */
    .chart-legend-item {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        padding: 8px 0;
        border-bottom: 1px dotted #f1f1f1;
    }

    .legend-color {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 10px;
    }

    /* Table Styles */
    .table thead th {
        background-color: #f8f9fa;
        border-bottom: 2px solid #e9ecef;
        font-weight: 600;
        color: #495057;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .expense-amount {
        color: #dc3545;
        font-weight: bold;
    }

    /* Scrollable Areas */
    .scrollable-content {
        flex: 1;
        overflow-y: auto;
    }

    .scrollable-list {
        flex: 1;
        overflow-y: auto;
    }

    /* Personalizar la barra de scroll */
    .scrollable-content::-webkit-scrollbar,
    .scrollable-list::-webkit-scrollbar {
        width: 6px;
    }

    .scrollable-content::-webkit-scrollbar-track,
    .scrollable-list::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb,
    .scrollable-list::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .scrollable-content::-webkit-scrollbar-thumb:hover,
    .scrollable-list::-webkit-scrollbar-thumb:hover {
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

    /* Responsive */
    @media (max-width: 768px) {
        .expenses-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
    }
</style>

<div class="full-height-expenses">
    <div class="expenses-header">
        <div class="row align-items-center">
            <div class="col-md-12">
                <h2 class="mb-1">📊 Dashboard de Gastos</h2>
                <p class="mb-0">Análisis de tus gastos por período</p>
            </div>
        </div>
    </div>

    <div class="expenses-grid" style="min-height: 600px;">
        <div class="sidebar-section">
            <div class="expenses-container filters-container">
                <div class="section-title">🔍 Filtros y Período</div>
                <form method="GET" class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Mes</label>
                        <select class="form-select" id="month" name="month" onchange="this.form.submit()">
                            <?php foreach ($month_names as $num => $name): ?>
                                <option value="<?= $num ?>" <?= $selected_month == $num ? 'selected' : '' ?>><?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Año</label>
                        <select class="form-select" id="year" name="year" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Categoría</label>
                        <select class="form-select" id="category_id" name="category_id" onchange="this.form.submit()">
                            <?php foreach ($category_options as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $selected_category_id == (string) $id ? 'selected' : '' ?>>
                                    <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <a href="expenses.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-undo me-1"></i> Resetear Filtros
                        </a>
                    </div>
                </form>
            </div>

            <div class="expenses-container chart-container">
                <div class="section-title">
                    📈 Distribución por Categoría (Total Mensual)
                    <span class="badge bg-primary items-count"><?= count($category_summary) ?> categorías</span>
                </div>

                <div class="scrollable-content">
                    <?php if ($total_gastos_resumen > 0): ?>


                        [Image of Doughnut chart showing monthly expense categories]

                        <div class="d-flex flex-column h-100">
                            <div class="flex-grow-0 mb-3 fw-bold text-muted text-center">
                                Total: <?= format_currency($total_gastos_resumen) ?>
                            </div>
                            <div class="flex-grow-1 d-flex align-items-center justify-content-center">
                                <canvas id="expenseChart" style="max-width: 100%; max-height: 250px;"></canvas>
                            </div>
                            <div class="flex-grow-0 mt-3">
                                <div class="chart-legend-container">
                                    <?php $colors = ['#dc3545', '#ffc107', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14', '#e83e8c', '#007bff']; ?>
                                    <?php $color_index = 0; ?>
                                    <?php foreach ($category_summary as $item):
                                        $percentage = ($item['total_spent'] / $total_gastos_resumen) * 100;
                                        ?>
                                        <div class="chart-legend-item">
                                            <span>
                                                <span class="legend-color"
                                                    style="background-color: <?= htmlspecialchars($item['color'] ?? $colors[$color_index % count($colors)]) ?>;"></span>
                                                <?= htmlspecialchars($item['name']) ?>
                                            </span>
                                            <span>
                                                <?= format_currency($item['total_spent']) ?>
                                                <span
                                                    class="badge bg-light text-dark ms-2"><?= number_format($percentage, 1) ?>%</span>
                                            </span>
                                        </div>
                                        <?php $color_index++; endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-chart-pie"></i>
                            <p>No hay datos de gastos</p>
                            <small>Registra gastos para ver la distribución</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="main-section">
            <div class="expenses-container list-container">
                <div class="section-title">
                    📋 Detalle de Gastos
                    (<?= $selected_category_id && isset($categories[$selected_category_id]) ? htmlspecialchars($categories[$selected_category_id]) . ' en ' : '' ?><?= $month_names[$selected_month] ?>
                    <?= $selected_year ?>)
                    <span class="badge bg-primary items-count"><?= count($expense_list) ?> transacciones</span>
                </div>

                <div class="scrollable-content">
                    <?php if (!empty($expense_list)): ?>
                        <div class="scrollable-list">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fecha/Hora</th>
                                            <th>Categoría</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Monto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expense_list as $expense): ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i', strtotime($expense['created_at'])) ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($expense['category_name']) ?></td>
                                                <td><?= htmlspecialchars($expense['description']) ?></td>
                                                <td class="text-end expense-amount">- <?= format_currency($expense['amount']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data-placeholder">
                            <i class="fas fa-list-alt"></i>
                            <p>No hay gastos registrados</p>
                            <small>No se encontraron gastos para el período y filtro seleccionados</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>

<?php if ($total_gastos_resumen > 0): ?>
    <script>
        const defaultColors = ['#dc3545', '#ffc107', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14', '#e83e8c', '#007bff'];

        const categoryNames = [<?php echo implode(',', array_map(function ($item) {
            return "'" . htmlspecialchars($item['name']) . "'";
        }, $category_summary)); ?>];
        const amounts = [<?php echo implode(',', array_map(function ($item) {
            return $item['total_spent'];
        }, $category_summary)); ?>];

        const backgroundColors = [<?php echo implode(',', array_map(function ($item, $index) use ($colors) {
            return "'" . htmlspecialchars($item['color'] ?? $colors[$index % count($colors)]) . "'";
        }, $category_summary, array_keys($category_summary))); ?>];

        const expenseData = {
            labels: categoryNames,
            datasets: [{
                data: amounts,
                backgroundColor: backgroundColors,
                hoverOffset: 4,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        };

        new Chart(document.getElementById('expenseChart'), {
            type: 'doughnut',
            data: expenseData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                const total = context.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                                const currentValue = context.raw;
                                const formattedAmount = 'Bs ' + currentValue.toFixed(2).replace('.', ',');
                                const percentage = parseFloat((currentValue / total * 100).toFixed(1));
                                return `${label}${formattedAmount} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '65%',
            }
        });
    </script>
<?php endif; ?>

</body>

</html>