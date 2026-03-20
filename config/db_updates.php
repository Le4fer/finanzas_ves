<?php
// db_updates.php - Ejecutar una sola vez
require 'db.php';

try {
    // Agregar nuevas columnas si no existen
    $pdo->exec("
        ALTER TABLE users 
        ADD COLUMN IF NOT EXISTS allocated_funds DECIMAL(15,2) DEFAULT 0.00,
        ADD COLUMN IF NOT EXISTS available_for_spending DECIMAL(15,2) DEFAULT 0.00
    ");
    
    echo "✅ Base de datos actualizada correctamente";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>