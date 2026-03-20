<?php
require '../config/db.php';
require '../config/exchange_api.php';

header('Content-Type: application/json');

try {
    $api = new ExchangeRateAPI();
    $new_rate = $api->forceUpdate();
    
    if ($new_rate !== false) {
        echo json_encode([
            'success' => true,
            'rate' => number_format($new_rate, 2, ',', '.'),
            'raw_rate' => $new_rate,
            'message' => 'Tasa actualizada exitosamente'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo obtener la tasa de cambio'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>