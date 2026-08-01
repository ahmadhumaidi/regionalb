<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';

rsm_ensure_schema();
$result = rsm_collab_sync_cache();
$reports = array_keys($result['reports'] ?? []);
$errors = $result['errors'] ?? [];
echo '[' . rsm_wib_timestamp() . '] synced reports: ' . implode(', ', $reports) . PHP_EOL;
if ($errors) {
    echo 'errors: ' . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
