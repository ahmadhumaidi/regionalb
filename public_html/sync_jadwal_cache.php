<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';

rsm_ensure_schema();
$result = rsm_jadwal_sync_cache();
$zonas = array_keys($result['zonas'] ?? []);
$errors = $result['errors'] ?? [];
echo '[' . rsm_wib_timestamp() . '] synced jadwal zona: ' . implode(', ', $zonas) . PHP_EOL;
if ($errors) {
    echo 'errors: ' . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
