<?php
/**
 * Tiny .env loader for CLI scripts and web requests when phpdotenv is not installed.
 * It reads a .env file in the project root and populates getenv()/$_ENV/$_SERVER.
 * Safe, simple: ignores comments and empty lines. Does not support nested vars.
 */

$projectRoot = dirname(__DIR__);
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    return;
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }
    if (strpos($line, '=') === false) {
        continue;
    }
    list($name, $value) = explode('=', $line, 2);
    $name = trim($name);
    $value = trim($value);
    // Strip surrounding quotes " or '
    if ((strlen($value) >= 2) && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
        $value = substr($value, 1, -1);
    }
    // Set environment variables
    putenv("$name=$value");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
