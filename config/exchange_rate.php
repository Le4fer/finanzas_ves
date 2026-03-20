<?php
// config/exchange_rate.php
function getExchangeRate() {
    // Puedes obtener la tasa de una API del BCV aquí
    // Por ahora usamos una tasa fija, pero puedes implementar:
    // - API del BCV
    // - Actualización manual
    // - Cache de tasas
    
    return 40.50; // Tasa ejemplo en Bs por USD
}

function updateExchangeRate($new_rate) {
    // Función para actualizar la tasa manualmente
    file_put_contents('exchange_rate.txt', $new_rate);
    return $new_rate;
}

function loadExchangeRate() {
    if (file_exists('exchange_rate.txt')) {
        return floatval(file_get_contents('exchange_rate.txt'));
    }
    return getExchangeRate();
}
?>