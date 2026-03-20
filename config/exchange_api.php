<?php
class ExchangeRateAPI
{
    private $cache_file;
    private $cache_time = 43200; // 12 horas

    public function __construct()
    {
        $cache_dir = __DIR__ . '/../cache';
        $this->cache_file = $cache_dir . '/exchange_rate.cache';

        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
    }

    public function getExchangeRate()
    {
        $cached_rate = $this->getCachedRate();
        if ($cached_rate !== false) {
            return $cached_rate;
        }

        // Intentar múltiples fuentes en orden de prioridad
        $rate = $this->tryBCVAPI(); // Primero Banco Central de Venezuela
        if ($rate === false) {
            $rate = $this->tryDolarTodayAPI();
        }
        if ($rate === false) {
            $rate = $this->tryYadioAPI();
        }
        if ($rate === false) {
            $rate = $this->tryExchangeRateAPI();
        }
        if ($rate === false) {
            $rate = $this->getDefaultRate();
        }

        $this->saveToCache($rate);
        return $rate;
    }

    /**
     * Fuente 1: Banco Central de Venezuela (BCV) - OFICIAL
     */
    private function tryBCVAPI()
    {
        try {
            $url = 'https://www.bcv.org.ve/';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => [
                    'timeout' => 15,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                error_log("❌ Error: No se pudo conectar al BCV");
                return false;
            }

            // Buscar el patrón que contiene la tasa del dólar en el HTML del BCV
            $patterns = [
                '/<div class="col-sm-6 col-xs-6 centrado"><strong>([0-9.,]+)<\/strong><\/div>/i',
                '/USD<\/span><\/strong>[\s\S]*?<strong>([0-9.,]+)<\/strong>/i',
                '/D[oó]lar[^\d]*([0-9.,]+)/i',
                '/([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/'
            ];

            foreach ($patterns as $pat) {
                if (preg_match($pat, $response, $matches)) {
                    $candidate = $matches[1];
                    // Normalizar separadores con heurística
                    $rate = $this->normalizeNumber($candidate);
                    // Rechazar candidatos improbables (ej. < 50 Bs) para evitar capturar otras cifras
                    if ($rate > 50) {
                        error_log("✅ Tasa del BCV (patrón): Bs " . $rate . " - patrón: $pat");
                        return $rate;
                    } else {
                        // Guardar diagnóstico para inspección si el candidato es demasiado pequeño
                        $dbg = [
                            'pattern' => $pat,
                            'candidate' => $candidate,
                            'normalized' => $rate,
                            'note' => 'candidate too small, skipping'
                        ];
                        $this->saveDebugDump('bcv_candidate', $response, $dbg);
                    }
                }
            }

            // Intento con DOMDocument + búsqueda contextual como último recurso
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            if (@$doc->loadHTML($response)) {
                $xpath = new DOMXPath($doc);
                // Buscar elementos que contengan USD o Dólar y luego buscar números cercanos
                $nodes = $xpath->query("//*[contains(translate(text(), 'USD', 'usd'),'usd') or contains(translate(text(), 'DÓLARDÓLAR', 'dólardólar'),'dólar')]");
                foreach ($nodes as $node) {
                    $text = $node->textContent;
                    // Buscar números en los nodos cercanos
                    if (preg_match_all('/([0-9]{1,3}(?:[.,][0-9]{3})*(?:[.,][0-9]+)?)/', $text, $allMatches)) {
                        foreach ($allMatches[1] as $candidate) {
                            $rate = $this->normalizeNumber($candidate);
                            // Heurística: tasas razonables
                            if ($rate > 50 && $rate < 100000) {
                                error_log("✅ Tasa del BCV (DOM): Bs " . $rate);
                                return $rate;
                            }
                        }
                    }
                    // mirar nodo siguiente/siblings
                    $sibling = $node->nextSibling;
                    if ($sibling) {
                        $stext = trim($sibling->textContent);
                        if (preg_match('/([0-9.,]+)/', $stext, $m2)) {
                            $candidate = $m2[1];
                            $rate = $this->normalizeNumber($candidate);
                            if ($rate > 50) {
                                error_log("✅ Tasa del BCV (DOM sibling): Bs " . $rate);
                                return $rate;
                            }
                        }
                    }
                }
            }

            error_log("❌ No se pudo extraer la tasa del HTML del BCV");
            return false;

        } catch (Exception $e) {
            error_log("❌ Error BCV: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fuente 2: DolarToday API (Específica para Venezuela)
     */
    private function tryDolarTodayAPI()
    {
        try {
            $url = 'https://s3.amazonaws.com/dolartoday/data.json';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => ['timeout' => 10]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false)
                return false;

            $data = json_decode($response, true);

            if (isset($data['USD']['promedio'])) {
                $rate = floatval($data['USD']['promedio']);
                error_log("✅ Tasa de DolarToday: Bs " . $rate);
                return $rate;
            }

            if (isset($data['USD']['transferencia'])) {
                $rate = floatval($data['USD']['transferencia']);
                error_log("✅ Tasa de DolarToday (transferencia): Bs " . $rate);
                return $rate;
            }

            return false;

        } catch (Exception $e) {
            error_log("❌ Error DolarToday: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fuente 3: Yadio API (Muy confiable)
     */
    private function tryYadioAPI()
    {
        try {
            $url = 'https://api.yadio.io/json/USD';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => ['timeout' => 10]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false)
                return false;

            $data = json_decode($response, true);

            if (isset($data['VES'])) {
                $rate = floatval($data['VES']);
                error_log("✅ Tasa de Yadio: Bs " . $rate);
                return $rate;
            }

            return false;

        } catch (Exception $e) {
            error_log("❌ Error Yadio: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fuente 4: ExchangeRate-API (Respaldo internacional)
     */
    private function tryExchangeRateAPI()
    {
        try {
            $url = 'https://api.exchangerate-api.com/v4/latest/USD';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => ['timeout' => 10]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false)
                return false;

            $data = json_decode($response, true);

            if (isset($data['rates']['VES'])) {
                $rate = floatval($data['rates']['VES']);
                error_log("✅ Tasa de ExchangeRate-API: Bs " . $rate);
                return $rate;
            }

            return false;

        } catch (Exception $e) {
            error_log("❌ Error ExchangeRate-API: " . $e->getMessage());
            return false;
        }
    }

    private function getDefaultRate()
    {
        $default_rate = 36.00; // Mantener como respaldo
        error_log("🔄 Usando tasa por defecto: Bs " . $default_rate);
        return $default_rate;
    }

    /**
     * Normalizar una cadena numérica al formato float con punto decimal.
     */
    private function normalizeNumber($s)
    {
        $s = trim((string) $s);
        $s = str_replace(' ', '', $s);
        // Si contiene punto y coma ambos, asumimos '.' miles y ',' decimales
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace('.', '', $s); // quitar separador de miles
            $s = str_replace(',', '.', $s); // convertir decimal
        } elseif (strpos($s, ',') !== false) {
            // solo coma -> decimal
            $s = str_replace(',', '.', $s);
        }
        // eliminar cualquier caracter que no sea dígito, punto o signo
        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        return floatval($s);
    }

    /**
     * Guardar un volcado de diagnóstico en la carpeta cache para inspección.
     */
    private function saveDebugDump($tag, $response, $debugData = [])
    {
        $cacheDir = dirname($this->cache_file);
        $ts = time();
        $htmlPath = $cacheDir . "/{$tag}_debug_{$ts}.html";
        $jsonPath = $cacheDir . "/{$tag}_debug_{$ts}.json";
        @file_put_contents($htmlPath, $response);
        @file_put_contents($jsonPath, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        error_log("🧾 Debug dump guardado: $htmlPath and $jsonPath");
    }

    private function getCachedRate()
    {
        if (file_exists($this->cache_file)) {
            $data = json_decode(file_get_contents($this->cache_file), true);
            if ($data && time() - $data['timestamp'] < $this->cache_time) {
                error_log("📁 Tasa desde cache: Bs " . $data['rate']);
                return $data['rate'];
            }
        }
        return false;
    }

    private function saveToCache($rate)
    {
        // Obtener hora actual de Venezuela
        $venezuela_timezone = new DateTimeZone('America/Caracas');
        $venezuela_time = new DateTime('now', $venezuela_timezone);

        $data = [
            'rate' => $rate,
            'timestamp' => time(),
            'date' => $venezuela_time->format('Y-m-d H:i:s'),
            'date_venezuela' => $venezuela_time->format('d/m/Y H:i:s'),
            'timezone' => 'America/Caracas (VET)',
            'source' => 'BCV y APIs externas'
        ];
        file_put_contents($this->cache_file, json_encode($data));
        error_log("💾 Tasa guardada en cache: Bs " . $rate . " - " . $venezuela_time->format('d/m/Y H:i:s') . ' VET');
    }

    public function getCacheInfo()
    {
        if (file_exists($this->cache_file)) {
            $data = json_decode(file_get_contents($this->cache_file), true);

            // Formatear la fecha para mostrar en formato venezolano
            if (isset($data['date_venezuela'])) {
                $data['display_date'] = $data['date_venezuela'] . ' (VET)';
            } else if (isset($data['date'])) {
                // Si no existe date_venezuela, convertir la fecha existente
                try {
                    $date = DateTime::createFromFormat('Y-m-d H:i:s', $data['date']);
                    if ($date) {
                        $date->setTimezone(new DateTimeZone('America/Caracas'));
                        $data['display_date'] = $date->format('d/m/Y H:i:s') . ' (VET)';
                    } else {
                        $data['display_date'] = $data['date'] . ' (VET)';
                    }
                } catch (Exception $e) {
                    $data['display_date'] = $data['date'] . ' (VET)';
                }
            }

            return $data;
        }
        return false;
    }

    public function forceUpdate()
    {
        if (file_exists($this->cache_file)) {
            unlink($this->cache_file);
            error_log("🗑️ Cache eliminado para forzar actualización");
        }
        return $this->getExchangeRate();
    }
}
?>