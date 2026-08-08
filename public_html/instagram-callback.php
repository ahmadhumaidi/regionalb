<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';

function instagram_callback_redirect(string $message, bool $ok = true): void
{
    $_SESSION['rsm_meta_notice'] = $message;
    header('Location: regional-b.php?page=konten&meta_status=' . ($ok ? 'ok' : 'error'));
    exit;
}

try {
    $actor = rsm_auth_user();
    if (!$actor) {
        header('Location: regional-b.php?page=konten');
        exit;
    }

    $oauth = $_SESSION['rsm_meta_oauth'] ?? null;
    if (!is_array($oauth) || (string) ($oauth['state'] ?? '') === '' || (time() - (int) ($oauth['created_at'] ?? 0)) > 900) {
        throw new RuntimeException('Sesi OAuth Meta kedaluwarsa. Ulangi dari tombol Hubungkan API.');
    }
    if (!hash_equals((string) $oauth['state'], (string) ($_GET['state'] ?? ''))) {
        throw new RuntimeException('State OAuth tidak valid.');
    }
    if (isset($_GET['error'])) {
        throw new RuntimeException((string) ($_GET['error_description'] ?? $_GET['error']));
    }

    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Kode OAuth Meta tidak diterima.');
    }

    $config = rsm_meta_require_config();
    $area = (string) ($oauth['area'] ?? 'Regional B');
    $accountId = (int) ($oauth['account_id'] ?? 0);
    $account = rsm_social_account_for_actor($area, $accountId, $actor);

    $token = rsm_meta_graph_request('oauth/access_token', [
        'client_id' => $config['app_id'],
        'redirect_uri' => $config['redirect_uri'],
        'client_secret' => $config['app_secret'],
        'code' => $code,
    ]);

    $shortToken = (string) ($token['access_token'] ?? '');
    if ($shortToken === '') {
        throw new RuntimeException('Access token Meta kosong.');
    }

    $longToken = rsm_meta_graph_request('oauth/access_token', [
        'grant_type' => 'fb_exchange_token',
        'client_id' => $config['app_id'],
        'client_secret' => $config['app_secret'],
        'fb_exchange_token' => $shortToken,
    ]);
    $userToken = (string) (($longToken['access_token'] ?? '') ?: $shortToken);
    $expiresIn = isset($longToken['expires_in']) ? (int) $longToken['expires_in'] : (isset($token['expires_in']) ? (int) $token['expires_in'] : null);

    $pages = rsm_meta_graph_request('me/accounts', [
        'fields' => 'id,name,access_token,instagram_business_account{id,username}',
        'access_token' => $userToken,
    ]);

    $targetUsername = mb_strtolower(trim((string) ($account['instagram_username'] ?? '')));
    $selectedPage = null;
    $selectedIg = null;
    foreach ((array) ($pages['data'] ?? []) as $page) {
        if (!is_array($page)) {
            continue;
        }
        $ig = $page['instagram_business_account'] ?? null;
        if (!is_array($ig) || empty($ig['id'])) {
            continue;
        }
        $igUsername = mb_strtolower(trim((string) ($ig['username'] ?? '')));
        if ($targetUsername !== '' && $igUsername === $targetUsername) {
            $selectedPage = $page;
            $selectedIg = $ig;
            break;
        }
        if ($selectedPage === null) {
            $selectedPage = $page;
            $selectedIg = $ig;
        }
    }

    if (!is_array($selectedPage) || !is_array($selectedIg)) {
        throw new RuntimeException('Tidak ada Facebook Page yang terhubung ke Instagram Business/Creator pada akun Meta ini.');
    }

    $pageToken = (string) ($selectedPage['access_token'] ?? '');
    if ($pageToken === '') {
        throw new RuntimeException('Page access token tidak ditemukan.');
    }

    rsm_update_social_meta_connection($accountId, $selectedPage, $selectedIg, $pageToken, $expiresIn);
    unset($_SESSION['rsm_meta_oauth']);
    instagram_callback_redirect('Instagram API berhasil terhubung untuk ' . campus_canonical_label((string) ($account['unit_name'] ?? 'kampus')) . '.');
} catch (Throwable $e) {
    unset($_SESSION['rsm_meta_oauth']);
    instagram_callback_redirect('Gagal menghubungkan Instagram API: ' . $e->getMessage(), false);
}
