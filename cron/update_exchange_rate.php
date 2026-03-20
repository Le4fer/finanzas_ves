<?php
// cron/update_exchange_rate.php
require_once '../config/db.php';
require_once '../config/exchange_api.php';

// Este script se ejecuta automáticamente via cron job
function updateExchangeRateInDatabase()
{
    global $pdo;

    try {
        // Usar la clase ExchangeRateAPI para forzar la actualización
        $api = new ExchangeRateAPI();
        $new_rate = $api->forceUpdate();
        $timestamp = date('Y-m-d H:i:s');

        // Guardar en la base de datos para histórico
        $stmt = $pdo->prepare("
            INSERT INTO exchange_rates (rate, source, created_at) 
            VALUES (?, 'auto_api', ?)
        ");
        $stmt->execute([$new_rate, $timestamp]);

        // También guardar en una tabla de configuración
        $stmt = $pdo->prepare("
            INSERT INTO app_config (config_key, config_value, updated_at) 
            VALUES ('current_exchange_rate', ?, ?)
            ON DUPLICATE KEY UPDATE config_value = ?, updated_at = ?
        ");
        $stmt->execute([$new_rate, $timestamp, $new_rate, $timestamp]);

        return [
            'success' => true,
            'rate' => $new_rate,
            'timestamp' => $timestamp
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Ejecutar si se llama directamente
if (php_sapi_name() === 'cli') {
    $result = updateExchangeRateInDatabase();
    echo "Actualización: " . ($result['success'] ? 'Éxito' : 'Falló') . "\n";
    echo "Tasa: " . ($result['rate'] ?? 'N/A') . "\n";
    if (!$result['success']) {
        echo "Error: " . $result['error'] . "\n";
    }
}
?>