<?php
require 'includes/auth.php';
require 'config/db.php';

$user_id = $_SESSION['user_id'];

// Nombre del archivo
$filename = 'finanzas_' . date('Y-m-d') . '.csv';

// Cabeceras para forzar descarga
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Añadir BOM para Excel en UTF-8 (evita caracteres raros)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Usar punto y coma como delimitador (compatible con Excel en español)
// PHP no permite cambiar el delimitador en fputcsv directamente en versiones antiguas,
// así que lo haremos manualmente con implode.

// Escribir encabezado
$header = ['Tipo', 'Fecha (YYYY-MM)', 'Monto', 'Categoría', 'Descripción'];
fwrite($output, implode(';', array_map(function ($field) {
    // Escapar campos que contengan ;, " o saltos de línea
    $field = str_replace('"', '""', $field);
    if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
        $field = '"' . $field . '"';
    }
    return $field;
}, $header)) . "\n");

// Ingresos
$stmt = $pdo->prepare("SELECT 'Ingreso', CONCAT(year, '-', LPAD(month,2,'0')), amount, '', description FROM incomes WHERE user_id = ? ORDER BY year, month");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    // Formatear monto con punto (no coma) para evitar problemas en Excel
    $row[2] = number_format($row[2], 2, '.', '');
    fwrite($output, implode(';', array_map(function ($field) {
        $field = str_replace('"', '""', (string) $field);
        if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            $field = '"' . $field . '"';
        }
        return $field;
    }, $row)) . "\n");
}

// Gastos
$stmt = $pdo->prepare("
    SELECT 'Gasto', CONCAT(e.year, '-', LPAD(e.month,2,'0')), e.amount, c.name, e.description
    FROM expenses e
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE e.user_id = ?
    ORDER BY e.year, e.month
");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $row[2] = number_format($row[2], 2, '.', '');
    fwrite($output, implode(';', array_map(function ($field) {
        $field = str_replace('"', '""', (string) $field);
        if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            $field = '"' . $field . '"';
        }
        return $field;
    }, $row)) . "\n");
}

fclose($output);
exit();
?>