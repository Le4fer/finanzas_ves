<?php
// scripts/migrate_budgets_table.php
// Ejecuta desde CLI: php migrate_budgets_table.php
// Añade columnas budget_amount y spent_amount a la tabla `budgets` si no existen.
// Si la tabla sólo tiene `amount`, copia esos valores a budget_amount y deja spent_amount en 0.

// Cargar variables de entorno si están en .env
if (file_exists(__DIR__ . '/../config/load_env.php')) {
    require_once __DIR__ . '/../config/load_env.php';
}
require_once __DIR__ . '/../config/db.php';

function columnExists($pdo, $dbName, $table, $column)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$dbName, $table, $column]);
    return (bool) $stmt->fetchColumn();
}

// Determinar nombre de la base de datos desde DSN o env
$dbName = getenv('DB_NAME') ?: 'finanzas_personales';
$table = 'budgets';

try {
    // Verificar existencia de columnas
    $hasBudgetAmount = columnExists($pdo, $dbName, $table, 'budget_amount');
    $hasSpent = columnExists($pdo, $dbName, $table, 'spent_amount');
    $hasAmount = columnExists($pdo, $dbName, $table, 'amount');

    if (!$hasBudgetAmount) {
        echo "Añadiendo columna budget_amount...\n";
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `budget_amount` DECIMAL(10,2) NOT NULL DEFAULT 0");
        $hasBudgetAmount = true;
    }
    if (!$hasSpent) {
        echo "Añadiendo columna spent_amount...\n";
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `spent_amount` DECIMAL(10,2) NOT NULL DEFAULT 0");
        $hasSpent = true;
    }

    if ($hasAmount && $hasBudgetAmount) {
        // Copiar los valores anteriores a budget_amount si todavía no se han migrado
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE budget_amount = 0 AND amount <> 0");
        if ($stmt && $stmt->fetchColumn() > 0) {
            echo "Migrando datos desde column 'amount' hacia 'budget_amount'...\n";
            $pdo->exec("UPDATE `$table` SET budget_amount = amount WHERE budget_amount = 0 AND amount <> 0");
        }
    }

    echo "Migración de tabla budgets completada.\n";
    if (!$hasAmount) {
        echo "Nota: la columna 'amount' no existe o fue eliminada previamente.\n";
    }
} catch (Exception $e) {
    echo "Error durante la migración: " . $e->getMessage() . "\n";
    exit(1);
}

?>