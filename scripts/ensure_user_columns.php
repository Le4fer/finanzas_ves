<?php
// scripts/ensure_user_columns.php
// Ejecuta desde CLI: php ensure_user_columns.php
// Añade columnas necesarias a la tabla `users` si no existen (is_verified, verification_code, must_change_password, is_active, current_balance, available_for_spending)

// Load .env for CLI if present (so env vars in .env are available to this script)
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

$dbName = getenv('DB_NAME') ?: 'finanzas_personales';
$table = 'users';

$toAdd = [
    'is_verified' => "TINYINT(1) NOT NULL DEFAULT 0",
    'verification_code' => "VARCHAR(64) DEFAULT NULL",
    'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0",
    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'current_balance' => "DECIMAL(14,2) NOT NULL DEFAULT 0",
    'available_for_spending' => "DECIMAL(14,2) NOT NULL DEFAULT 0"
];

foreach ($toAdd as $col => $definition) {
    try {
        if (columnExists($pdo, $dbName, $table, $col)) {
            echo "Columna '$col' ya existe, omitiendo.\n";
            continue;
        }

        $sql = "ALTER TABLE `$table` ADD COLUMN `$col` $definition";
        $pdo->exec($sql);
        echo "Columna '$col' agregada correctamente.\n";
    } catch (Exception $e) {
        echo "Error agregando columna '$col': " . $e->getMessage() . "\n";
    }
}

echo "Operación finalizada. Revisa la tabla 'users' en la base de datos.\n";

?>