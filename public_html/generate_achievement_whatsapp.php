<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';

$area = (string) ($argv[1] ?? 'Regional B');
if (!in_array($area, ['Regional A', 'Regional B'], true)) {
    $area = 'Regional B';
}

try {
    rsm_ensure_schema();
    rsm_collab_sync_cache();
    $artifact = rsm_generate_achievement_whatsapp_artifact($area);
    echo '[' . rsm_wib_timestamp() . '] Generated ' . ($artifact['text_file'] ?? '-') . PHP_EOL;
    if (!empty($artifact['image_error'])) {
        echo '[' . rsm_wib_timestamp() . '] Image warning: ' . $artifact['image_error'] . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
