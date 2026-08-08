<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';

try {
    $area = (string) ($_GET['area'] ?? 'Regional B');
    $accountId = (int) ($_GET['account_id'] ?? 0);
    $actor = rsm_auth_user();
    if (!$actor) {
        header('Location: regional-b.php?page=konten');
        exit;
    }

    rsm_meta_require_config();
    rsm_social_account_for_actor($area, $accountId, $actor);

    $state = bin2hex(random_bytes(24));
    $_SESSION['rsm_meta_oauth'] = [
        'state' => $state,
        'area' => $area,
        'account_id' => $accountId,
        'user_id' => (int) ($actor['id'] ?? 0),
        'created_at' => time(),
    ];

    $config = rsm_meta_config();
    $query = http_build_query([
        'client_id' => $config['app_id'],
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
        'response_type' => 'code',
        'scope' => implode(',', [
            'pages_show_list',
            'pages_read_engagement',
            'instagram_basic',
            'instagram_manage_insights',
        ]),
    ]);

    header('Location: https://www.facebook.com/' . rawurlencode($config['graph_version']) . '/dialog/oauth?' . $query);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Gagal memulai koneksi Instagram: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
