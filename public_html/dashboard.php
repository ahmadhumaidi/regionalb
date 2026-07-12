<?php
declare(strict_types=1);

require_once __DIR__ . '/rsm_db.php';
require_once __DIR__ . '/rsm_profile_gamification.php';

$area = isset($area) ? (string) $area : 'Regional';
$areaScope = isset($areaScope) ? (string) $areaScope : 'Semua wilayah';
$page = strtolower((string) ($_GET['page'] ?? 'dashboard'));
$role = 'staff';
$allowedRoleKeys = ['staff'];
$roleQuery = '';

$roles = [
    'senior' => ['label' => 'Senior Manager', 'name' => 'Senior Manager', 'scope' => 'Semua wilayah, unit, dan staff'],
    'koordinator' => ['label' => 'Koordinator Wilayah', 'name' => 'Koordinator Wilayah', 'scope' => 'Wilayah yang menjadi tanggung jawab'],
    'staff' => ['label' => 'Staff Unit', 'name' => 'Staff Unit', 'scope' => 'Laporan milik sendiri'],
];
if (!isset($roles[$role])) {
    $role = 'senior';
}

$menus = [
    'dashboard' => 'Dashboard Utama',
    'pencapaian' => 'Pencapaian Staff',
    'kegiatan' => 'Kegiatan Marketing',
    'anggaran' => 'Laporan Iklan',
    'aktivitas' => 'Aktivitas Lain',
    'rekap' => 'Laporan & Rekap',
    'role' => 'User & Role',
    'profile' => 'Profil Saya',
    'password' => 'Ganti Password',
];
$adminMenus = [
    'users' => 'Kelola User',
];
$pageTitles = $menus + $adminMenus + ['detail' => 'Detail Laporan', 'edit' => 'Edit Laporan'];
if (!isset($pageTitles[$page])) {
    $page = 'dashboard';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function money_idr(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function url_for(string $page, string $role): string
{
    return '?page=' . rawurlencode($page) . ($GLOBALS['roleQuery'] ?? '');
}

function selected_attr(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

function percent_label(float $value): string
{
    return number_format($value, 2, ',', '.') . '%';
}

function badge(string $status): string
{
    $key = strtolower(str_replace(' ', '-', $status));
    return '<span class="badge badge-' . h($key) . '">' . h($status) . '</span>';
}

function can_action(string $role, string $action): bool
{
    $rules = [
        'senior' => ['detail', 'setujui', 'tolak', 'revisi', 'export'],
        'koordinator' => ['detail', 'verifikasi', 'revisi', 'export-wilayah'],
        'staff' => ['detail', 'edit-draft', 'hapus-draft'],
    ];
    return in_array($action, $rules[$role] ?? [], true);
}

function allowed_effective_roles(string $actualRole): array
{
    return [
        'senior' => ['senior', 'koordinator', 'staff'],
        'koordinator' => ['koordinator', 'staff'],
        'staff' => ['staff'],
    ][$actualRole] ?? ['staff'];
}

$dbError = null;
$notice = null;
$summary = [
    'marketing_count' => 0,
    'budget_total' => 0,
    'realization_total' => 0,
    'other_count' => 0,
    'pending_count' => 0,
    'approved_count' => 0,
];
$activities = [];
$ads = [];
$otherActivities = [];
$logs = [];
$detailReport = null;
$detailLogs = [];
$detailAdLeads = [];
$editReport = null;
$authUser = null;
$loginError = null;
$managedUsers = [];
$dashboardFilters = rsm_dashboard_filters_from_request($_GET);
$dashboardOverview = [
    'status_map' => ['report_type' => [], 'status' => [], 'progress_status' => [], 'follow_up_result' => [], 'closing_status' => []],
    'status_buckets' => ['registrasi' => [], 'herregistrasi' => []],
    'kpi' => ['leads' => 0, 'follow_up' => 0, 'registrasi' => 0, 'herregistrasi' => 0, 'conversion_rate' => 0],
    'funnel' => [],
    'budget' => ['requested' => 0, 'approved' => 0, 'spend' => 0, 'remaining' => 0, 'ads_leads' => 0, 'ads_registrasi' => 0, 'cpl' => 0, 'cost_per_registrasi' => 0],
    'ranking' => [],
    'daily_reports' => [],
];
$gamification = [
    'leaderboard' => [],
    'my_rank' => null,
    'challenge' => ['title' => 'Challenge Bulan Ini', 'items' => []],
    'point_rules' => [],
];
$staffAchievement = [
    'rows' => [],
    'regional_summary' => [],
    'totals' => ['staff_count' => 0, 'registrasi' => 0, 'herregistrasi' => 0],
    'sources' => [],
];
$references = [
    'regionals' => [],
    'staff' => [],
    'campuses' => [],
];
$profileData = null;

try {
    rsm_ensure_schema();
    $postAction = (string) ($_POST['action'] ?? '');
    if (isset($_GET['logout'])) {
        rsm_logout();
        header('Location: ./');
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $postAction === 'login') {
        if (!rsm_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $loginError = 'Username atau password salah.';
        }
    }

    $authUser = rsm_auth_user();
    if (!$authUser) {
        render_login_page($loginError);
        exit;
    }

    $actualRole = (string) $authUser['role'];
    $allowedRoleKeys = allowed_effective_roles($actualRole);
    $requestedRole = strtolower((string) ($_GET['role'] ?? $actualRole));
    $role = in_array($requestedRole, $allowedRoleKeys, true) ? $requestedRole : $actualRole;
    if (!isset($roles[$role])) {
        $role = $allowedRoleKeys[0] ?? 'staff';
    }
    if (count($allowedRoleKeys) > 1) {
        $roleQuery = '&role=' . rawurlencode($role);
    }
    if (($authUser['role'] ?? '') !== 'senior' && isset($adminMenus[$page])) {
        $page = 'dashboard';
    }

    if (!empty($authUser['area']) && (string) $authUser['area'] !== $area) {
        header('Location: ' . ((string) $authUser['area'] === 'Regional A' ? 'regional-a.php' : 'regional-b.php'));
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $postAction === 'change_password') {
        rsm_change_password((int) $authUser['id'], (string) ($_POST['old_password'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
        $notice = 'Password berhasil diperbarui.';
        $authUser = rsm_auth_user();
    } elseif ($postAction !== 'login') {
        $notice = rsm_handle_post($area, $role);
        $authUser = rsm_auth_user();
    }

    $references = rsm_reference_options($area, $authUser, $role);
    $summary = rsm_summary($area, $authUser);
    $activities = rsm_reports($area, 'marketing', 50, $authUser);
    $ads = rsm_reports($area, 'ads', 50, $authUser);
    $otherActivities = rsm_reports($area, 'other', 50, $authUser);
    $logs = rsm_logs($area, 20, $authUser);
    if ($page === 'dashboard') {
        $dashboardOverview = rsm_dashboard_overview($area, $dashboardFilters, $authUser);
        $gamification = rsm_gamification_summary($area, $dashboardFilters, $authUser);
        $staffAchievement = rsm_collab_staff_performance($area, $dashboardFilters, $authUser);
    } elseif ($page === 'pencapaian') {
        $staffAchievement = rsm_collab_staff_performance($area, $dashboardFilters, $authUser);
    }
    if ($page === 'users' && ($authUser['role'] ?? '') === 'senior') {
        $managedUsers = rsm_users($area);
    }
    if ($page === 'profile') {
        $profileData = rsm_profile_gamification($area, $authUser, (int) ($_GET['user_id'] ?? 0));
    }
    if ($page === 'detail') {
        $detailReport = rsm_report($area, (int) ($_GET['id'] ?? 0), $authUser);
        if ($detailReport) {
            $detailLogs = rsm_report_logs((int) $detailReport['id']);
            if (($detailReport['report_type'] ?? '') === 'ads') {
                $detailAdLeads = rsm_report_ad_leads((int) $detailReport['id']);
            }
        }
    } elseif ($page === 'edit') {
        $editReport = rsm_report($area, (int) ($_GET['id'] ?? 0), $authUser);
    }
} catch (Throwable $error) {
    $dbError = $error->getMessage();
}

$latestActivities = array_slice($activities, 0, 8);

$displayLeads = (float) $dashboardOverview['kpi']['leads'];
$displayRegistrasi = (float) ($staffAchievement['totals']['registrasi'] ?? $dashboardOverview['kpi']['registrasi']);
$displayHerregistrasi = (float) ($staffAchievement['totals']['herregistrasi'] ?? $dashboardOverview['kpi']['herregistrasi']);
$displayConversionRate = rsm_dashboard_percent($displayRegistrasi, $displayLeads);
$displayCostPerRegistrasi = rsm_dashboard_divide((float) $dashboardOverview['budget']['spend'], $displayRegistrasi);
$displayRegistrationSource = (string) (($staffAchievement['sources']['registrasi']['label'] ?? '') ?: 'Closing Collab');
$displayHerregistrationSource = (string) (($staffAchievement['sources']['herregistrasi']['label'] ?? '') ?: 'Herreg Collab');

$dashboardOverview['kpi']['registrasi'] = $displayRegistrasi;
$dashboardOverview['kpi']['herregistrasi'] = $displayHerregistrasi;
$dashboardOverview['kpi']['conversion_rate'] = $displayConversionRate;
$dashboardOverview['funnel'][2]['value'] = $displayRegistrasi;
$dashboardOverview['funnel'][2]['rate'] = rsm_dashboard_percent($displayRegistrasi, $displayLeads);
$dashboardOverview['funnel'][3]['value'] = $displayHerregistrasi;
$dashboardOverview['funnel'][3]['rate'] = rsm_dashboard_percent($displayHerregistrasi, $displayLeads);
$dashboardOverview['budget']['cost_per_registrasi'] = $displayCostPerRegistrasi;

$summaryCards = [
    ['label' => 'Leads', 'value' => number_format((float) $dashboardOverview['kpi']['leads'], 0, ',', '.'), 'tone' => 'blue', 'note' => 'Target belum diatur'],
    ['label' => 'Follow Up', 'value' => number_format((float) $dashboardOverview['kpi']['follow_up'], 0, ',', '.'), 'tone' => 'cyan', 'note' => 'Dari detail lead yang sudah ditindaklanjuti'],
    ['label' => 'Registrasi', 'value' => number_format($displayRegistrasi, 0, ',', '.'), 'tone' => 'green', 'note' => 'Acuan: ' . $displayRegistrationSource],
    ['label' => 'Herregistrasi', 'value' => number_format($displayHerregistrasi, 0, ',', '.'), 'tone' => 'purple', 'note' => 'Acuan: ' . $displayHerregistrationSource],
    ['label' => 'Conversion Rate', 'value' => percent_label($displayConversionRate), 'tone' => 'amber', 'note' => 'Registrasi dibagi leads'],
    ['label' => 'Target PMB', 'value' => 'Belum diatur', 'tone' => 'slate', 'note' => 'Phase 1 belum membuat tabel target'],
];
$registrationRecap = [
    'leads' => $displayLeads,
    'registrasi' => $displayRegistrasi,
    'registrasi_source' => $displayRegistrationSource,
    'herregistrasi' => $displayHerregistrasi,
    'herregistrasi_source' => $displayHerregistrationSource,
    'conversion_rate' => $displayConversionRate,
    'cost_per_registrasi' => $displayCostPerRegistrasi,
    'top_units' => array_slice($dashboardOverview['ranking'], 0, 3),
];
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitles[$page]) ?> - <?= h($area) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <?php if ($page === 'profile'): ?><link rel="stylesheet" href="assets/profile.css"><?php endif; ?>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="./">
        <span class="brand-mark">RSM</span>
        <span><strong><?= h($area) ?></strong><small><?= h($areaScope) ?></small></span>
      </a>
      <nav class="menu">
        <?php foreach ($menus as $key => $label): ?>
          <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key, $role)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php if (($authUser['role'] ?? '') === 'senior'): ?>
          <?php foreach ($adminMenus as $key => $label): ?>
            <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key, $role)) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        <?php endif; ?>
      </nav>
      <div class="role-note">
        <span>User aktif</span>
        <strong><?= h((string) ($authUser['name'] ?? $roles[$role]['label'])) ?></strong>
        <small><?= h((string) ($authUser['jabatan'] ?? $roles[$role]['scope'])) ?></small>
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div>
          <p class="eyebrow"><?= h($area) ?></p>
          <h1><?= h($pageTitles[$page]) ?></h1>
          <p>Monitoring aktivitas marketing regional, Laporan iklan, dan performa unit/kampus.</p>
        </div>
        <div class="top-actions">
          <?php if (count($allowedRoleKeys) > 1): ?>
            <form method="get" class="role-form">
              <input type="hidden" name="page" value="<?= h($page) ?>">
              <?php if (isset($_GET['id'])): ?><input type="hidden" name="id" value="<?= h((string) $_GET['id']) ?>"><?php endif; ?>
              <label>
                <span>Tampilan sebagai</span>
                <select name="role" onchange="this.form.submit()">
                  <?php foreach ($allowedRoleKeys as $key): ?>
                    <option value="<?= h($key) ?>" <?= $role === $key ? 'selected' : '' ?>><?= h($roles[$key]['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </form>
          <?php endif; ?>
          <div class="user-pill">
            <strong><?= h((string) ($authUser['name'] ?? '-')) ?></strong>
            <span><?= h((string) ($authUser['username'] ?? '-')) ?> - <?= h((string) ($authUser['jabatan'] ?? $roles[$role]['label'])) ?></span>
          </div>
          <button class="icon-btn" type="button">Notifikasi</button>
          <a class="logout" href="?logout=1">Keluar</a>
        </div>
      </header>

      <?php if ($notice): ?>
        <div class="alert alert-success"><?= h($notice) ?></div>
      <?php endif; ?>
      <?php if ($dbError): ?>
        <div class="alert alert-danger">Database RSM belum tersambung: <?= h($dbError) ?></div>
      <?php endif; ?>

      <?php if ($page === 'dashboard'): ?>
        <form class="filter-bar dashboard-filter" method="get">
          <input type="hidden" name="page" value="dashboard">
          <?php if (count($allowedRoleKeys) > 1): ?>
            <input type="hidden" name="role" value="<?= h($role) ?>">
          <?php endif; ?>
          <label><span>Dari tanggal</span><input type="date" name="date_from" value="<?= h((string) $dashboardFilters['date_from']) ?>"></label>
          <label><span>Sampai tanggal</span><input type="date" name="date_to" value="<?= h((string) $dashboardFilters['date_to']) ?>"></label>
          <?php if (($authUser['role'] ?? '') === 'staff'): ?>
            <label><span>Wilayah</span><input class="locked-input" value="<?= h(staff_identity_value('Wilayah', $authUser, $references)) ?>" readonly title="Otomatis sesuai akun login"></label>
            <label><span>Unit/Kampus</span><input class="locked-input" value="<?= h(staff_identity_value('Unit/Kampus', $authUser, $references)) ?>" readonly title="Otomatis sesuai akun login"></label>
            <label><span>Staff</span><input class="locked-input" value="<?= h(staff_identity_value('Nama staff', $authUser, $references)) ?>" readonly title="Otomatis sesuai akun login"></label>
          <?php else: ?>
            <label><span>Wilayah</span><select name="wilayah"><option value="">Semua Wilayah</option><?php foreach ($references['regionals'] as $regionalOption): $value = (string) $regionalOption; ?><option value="<?= h($value) ?>"<?= selected_attr((string) $dashboardFilters['wilayah'], $value) ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label><span>Unit/Kampus</span><select name="unit_name"><option value="">Semua Unit/Kampus</option><?php foreach ($references['campuses'] as $campusOption): $value = (string) $campusOption['label']; ?><option value="<?= h($value) ?>"<?= selected_attr((string) $dashboardFilters['unit_name'], $value) ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label><span>Staff</span><select name="staff_name"><option value="">Semua Staff</option><?php foreach ($references['staff'] as $staffOption): $value = (string) $staffOption['name']; ?><option value="<?= h($value) ?>"<?= selected_attr((string) $dashboardFilters['staff_name'], $value) ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <label><span>Platform</span><select name="platform"><option value="">Semua Platform</option><?php foreach (array_filter($dashboardOverview['status_map']['report_type'] ? array_unique(array_map(static fn (array $row): string => (string) ($row['platform'] ?? ''), $ads)) : []) as $platformOption): ?><option value="<?= h($platformOption) ?>"<?= selected_attr((string) $dashboardFilters['platform'], $platformOption) ?>><?= h($platformOption) ?></option><?php endforeach; ?></select></label>
          <label><span>Status</span><select name="status"><option value="">Semua Status</option><?php foreach ($dashboardOverview['status_map']['status'] as $statusOption): ?><option value="<?= h((string) $statusOption) ?>"<?= selected_attr((string) $dashboardFilters['status'], (string) $statusOption) ?>><?= h((string) $statusOption) ?></option><?php endforeach; ?></select></label>
          <div class="filter-actions"><button class="primary-btn">Terapkan</button><a class="secondary-btn" href="<?= h(url_for('dashboard', $role)) ?>">Reset</a></div>
        </form>

        <section class="summary-grid">
          <?php foreach ($summaryCards as $card): ?>
            <article class="summary-card tone-<?= h($card['tone']) ?>">
              <span><?= h($card['label']) ?></span>
              <strong><?= h($card['value']) ?></strong>
              <small><?= h($card['note']) ?></small>
            </article>
          <?php endforeach; ?>
        </section>

        <section class="panel registration-recap-panel">
          <div class="panel-head">
            <h2>Rekap Pencapaian Registrasi</h2>
            <span>Ringkasan hasil PMB dari filter aktif</span>
          </div>
          <div class="registration-recap-grid">
            <div class="registration-hero">
              <span>Total Registrasi</span>
              <strong><?= h(number_format($registrationRecap['registrasi'], 0, ',', '.')) ?></strong>
              <small>Acuan: <?= h($registrationRecap['registrasi_source']) ?></small>
              <div class="registration-progress">
                <i style="width: <?= h((string) max(3, min(100, $registrationRecap['conversion_rate']))) ?>%"></i>
              </div>
              <p><?= h(percent_label($registrationRecap['conversion_rate'])) ?> conversion dari <?= h(number_format($registrationRecap['leads'], 0, ',', '.')) ?> leads</p>
            </div>
            <div class="registration-stat-list">
              <div><span>Leads Masuk</span><strong><?= h(number_format($registrationRecap['leads'], 0, ',', '.')) ?></strong></div>
              <div><span>Herregistrasi</span><strong><?= h(number_format($registrationRecap['herregistrasi'], 0, ',', '.')) ?></strong><small>Acuan: <?= h($registrationRecap['herregistrasi_source']) ?></small></div>
              <div><span>Cost / Registrasi</span><strong><?= h(money_idr($registrationRecap['cost_per_registrasi'])) ?></strong></div>
            </div>
            <div class="registration-top-units">
              <div class="registration-subhead">
                <strong>Top Unit/Kampus</strong>
                <span>berdasarkan registrasi</span>
              </div>
              <?php if (!$registrationRecap['top_units']): ?>
                <p class="muted">Belum ada registrasi pada periode/filter ini.</p>
              <?php endif; ?>
              <?php foreach ($registrationRecap['top_units'] as $index => $unit): ?>
                <div class="registration-unit-row">
                  <b>#<?= h((string) ($index + 1)) ?></b>
                  <span><?= h((string) (($unit['unit_label'] ?? '') ?: '-')) ?></span>
                  <strong><?= h(number_format((float) ($unit['registrasi_total'] ?? 0), 0, ',', '.')) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section class="dashboard-grid">
          <article class="panel funnel-panel">
            <div class="panel-head"><h2>Funnel Marketing</h2><span>Leads - Follow Up - Registrasi - Herregistrasi</span></div>
            <div class="funnel-list">
              <?php foreach ($dashboardOverview['funnel'] as $item): ?>
                <div class="funnel-row">
                  <div><strong><?= h((string) $item['label']) ?></strong><span><?= h(percent_label((float) $item['rate'])) ?> dari leads</span></div>
                  <b><?= h(number_format((float) $item['value'], 0, ',', '.')) ?></b>
                  <i style="width: <?= h((string) max(3, min(100, (float) $item['rate']))) ?>%"></i>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
          <article class="panel budget-panel">
            <div class="panel-head"><h2>Monitoring Anggaran Iklan</h2><span>Pengajuan, approved, spend, CPL</span></div>
            <div class="metric-grid compact-metrics">
              <div><span>Pengajuan</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['requested'])) ?></strong></div>
              <div><span>Disetujui</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['approved'])) ?></strong></div>
              <div><span>Spend</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['spend'])) ?></strong></div>
              <div><span>Sisa</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['remaining'])) ?></strong></div>
              <div><span>CPL</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['cpl'])) ?></strong></div>
              <div><span>Cost / Registrasi</span><strong><?= h(money_idr((float) $dashboardOverview['budget']['cost_per_registrasi'])) ?></strong></div>
            </div>
          </article>
        </section>

        <section class="panel game-panel">
          <div class="panel-head"><h2>Arena Performa Staff</h2><span>Poin, badge, dan challenge dari data existing</span></div>
          <?php render_gamification_panel($gamification); ?>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Ranking Top 10 Unit/Kampus</h2><span>Berdasarkan registrasi, partner_campus_id sebagai key utama</span></div>
          <?php render_ranking_table($dashboardOverview['ranking']); ?>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Laporan Harian Staff Terbaru</h2><span>Aktivitas, hasil, kendala, dan rencana berikutnya</span></div>
          <?php render_daily_report_table($dashboardOverview['daily_reports']); ?>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Mapping Status Aktual</h2><span>Dibaca dari database sesuai scope user</span></div>
          <?php render_status_mapping_panel($dashboardOverview['status_map'], $dashboardOverview['status_buckets']); ?>
        </section>
      <?php elseif ($page === 'pencapaian'): ?>
        <form class="filter-bar dashboard-filter" method="get">
          <input type="hidden" name="page" value="pencapaian">
          <?php if (count($allowedRoleKeys) > 1): ?>
            <input type="hidden" name="role" value="<?= h($role) ?>">
          <?php endif; ?>
          <label><span>Dari tanggal</span><input type="date" name="date_from" value="<?= h((string) $dashboardFilters['date_from']) ?>"></label>
          <label><span>Sampai tanggal</span><input type="date" name="date_to" value="<?= h((string) $dashboardFilters['date_to']) ?>"></label>
          <?php if (($authUser['role'] ?? '') === 'staff'): ?>
            <label><span>Wilayah</span><input class="locked-input" value="<?= h(staff_identity_value('Wilayah', $authUser, $references)) ?>" readonly></label>
            <label><span>Staff</span><input class="locked-input" value="<?= h(staff_identity_value('Nama staff', $authUser, $references)) ?>" readonly></label>
          <?php else: ?>
            <label><span>Wilayah</span><select name="wilayah"><option value="">Semua Wilayah</option><?php foreach ($references['regionals'] as $regionalOption): $value = (string) $regionalOption; ?><option value="<?= h($value) ?>"<?= selected_attr((string) $dashboardFilters['wilayah'], $value) ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label><span>Staff</span><select name="staff_name"><option value="">Semua Staff</option><?php foreach ($references['staff'] as $staffOption): $value = (string) $staffOption['name']; ?><option value="<?= h($value) ?>"<?= selected_attr((string) $dashboardFilters['staff_name'], $value) ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <div class="filter-actions"><button class="primary-btn">Terapkan</button><a class="secondary-btn" href="<?= h(url_for('pencapaian', $role)) ?>">Reset</a></div>
        </form>

        <section class="summary-grid four">
          <article class="summary-card tone-green"><span>Total Registrasi</span><strong><?= h(number_format((float) $staffAchievement['totals']['registrasi'], 0, ',', '.')) ?></strong><small>Acuan Closing Collab</small></article>
          <article class="summary-card tone-purple"><span>Total Herregistrasi</span><strong><?= h(number_format((float) $staffAchievement['totals']['herregistrasi'], 1, ',', '.')) ?></strong><small>Acuan Herreg Collab</small></article>
          <article class="summary-card tone-blue"><span>Staff Terbaca</span><strong><?= h(number_format((float) $staffAchievement['totals']['staff_count'], 0, ',', '.')) ?></strong><small>Dalam scope filter</small></article>
          <article class="summary-card tone-slate"><span>Sumber Data</span><strong>Collab</strong><small>Closing & Herreg</small></article>
        </section>

        <section class="panel achievement-panel">
          <div class="panel-head">
            <h2>Pencapaian Registrasi & Herregistrasi Staff</h2>
            <span>Sumber live Closing Collab dan Herreg Collab sesuai rentang tanggal aktif</span>
          </div>
          <?php render_collab_source_note($staffAchievement['sources'] ?? []); ?>
          <?php render_regional_achievement_summary($staffAchievement['regional_summary'] ?? []); ?>
          <?php render_staff_achievement_table($staffAchievement['rows'] ?? []); ?>
        </section>
      <?php elseif ($page === 'kegiatan'): ?>
        <?php render_form_panel('Tambah Kegiatan Marketing', [
            'Tanggal kegiatan', 'Wilayah', 'Unit/Kampus', 'Nama staff', 'Jenis kegiatan', 'Nama kegiatan',
            'Lokasi kegiatan', 'Target kegiatan', 'Hasil kegiatan', 'Jumlah prospek/leads', 'Catatan', 'Upload dokumentasi/foto'
        ], ['Follow up leads', 'Kunjungan sekolah', 'Kunjungan instansi', 'Sebar brosur', 'Pasang spanduk', 'Event kampus', 'Presentasi PMB', 'Aktivitas digital', 'Lainnya'], ['Draft', 'Dikirim', 'Diverifikasi Koordinator', 'Disetujui Senior Manager', 'Revisi'], 'create_marketing', $references, $authUser); ?>
        <section class="panel">
          <div class="panel-head"><h2>Daftar Kegiatan Marketing</h2><button class="primary-btn">Tambah Kegiatan</button></div>
          <div class="filter-bar compact">
            <input placeholder="Cari kegiatan">
            <select><option>Status laporan</option></select>
            <?php if (($authUser['role'] ?? '') === 'staff'): ?>
              <input class="locked-input" value="<?= h(staff_identity_value('Wilayah', $authUser, $references)) ?>" readonly title="Otomatis sesuai akun login">
              <input class="locked-input" value="<?= h(staff_identity_value('Nama staff', $authUser, $references)) ?>" readonly title="Otomatis sesuai akun login">
            <?php else: ?>
              <select><option>Wilayah</option></select>
              <select><option>Staff</option></select>
            <?php endif; ?>
          </div>
          <?php render_activity_table($activities, $role); ?>
        </section>
      <?php elseif ($page === 'anggaran'): ?>
        <section class="summary-grid four">
          <article class="summary-card tone-purple"><span>Total anggaran</span><strong><?= h(money_idr((float) $summary['budget_total'])) ?></strong><small>Pengajuan bulan ini</small></article>
          <article class="summary-card tone-green"><span>Total realisasi</span><strong><?= h(money_idr((float) $summary['realization_total'])) ?></strong><small>Pemakaian aktual</small></article>
          <article class="summary-card tone-amber"><span>Sisa anggaran</span><strong><?= h(money_idr((float) $summary['budget_total'] - (float) $summary['realization_total'])) ?></strong><small>Belum digunakan</small></article>
          <article class="summary-card tone-blue"><span>Leads iklan</span><strong><?= h((string) array_sum(array_map(static fn (array $row): int => (int) ($row['leads_count'] ?? 0), $ads))) ?></strong><small>Semua platform</small></article>
        </section>
        <?php render_form_panel('Input Anggaran Iklan', [
            'Tanggal', 'Wilayah', 'Unit/Kampus', 'Platform iklan', 'Nama campaign', 'Tujuan iklan',
            'Anggaran diajukan', 'Anggaran disetujui', 'Realisasi pemakaian',
            'CPL / Cost per Lead', 'Link campaign', 'Upload bukti invoice/screenshot', 'Upload data hasil iklan (.xls/.xlsx)', 'Catatan performa iklan'
        ], ['Meta Ads', 'Google Ads', 'TikTok Ads', 'WhatsApp Blast', 'Marketplace/Portal', 'Lainnya'], ['Pengajuan', 'Disetujui', 'Ditolak', 'Berjalan', 'Selesai', 'Revisi'], 'create_ads', $references, $authUser); ?>
        <section class="panel">
          <div class="panel-head"><h2>Laporan Anggaran</h2><button class="primary-btn">Tambah Anggaran</button></div>
          <?php render_ads_table($ads, $role); ?>
        </section>
      <?php elseif ($page === 'aktivitas'): ?>
        <?php render_form_panel('Input Aktivitas Lain', [
            'Tanggal', 'Wilayah', 'Unit/Kampus', 'Nama staff', 'Kategori aktivitas', 'Deskripsi aktivitas',
            'Hasil aktivitas', 'Kendala', 'Tindak lanjut', 'Upload dokumentasi'
        ], ['Meeting internal', 'Briefing', 'Training', 'Koordinasi kampus', 'Koordinasi mitra', 'Pelayanan calon mahasiswa', 'Administrasi PMB', 'Follow up pembayaran', 'Herregistrasi', 'Lainnya'], ['Draft', 'Dikirim', 'Diverifikasi', 'Disetujui', 'Revisi'], 'create_other', $references, $authUser); ?>
        <section class="panel">
          <div class="panel-head"><h2>Daftar Aktivitas Lain</h2><button class="primary-btn">Tambah Aktivitas</button></div>
          <?php render_other_table($otherActivities, $role); ?>
        </section>
      <?php elseif ($page === 'rekap'): ?>
        <section class="panel">
          <div class="panel-head"><h2>Laporan & Rekap</h2><div class="actions"><button class="secondary-btn">Export Excel</button><button class="secondary-btn">Export PDF</button></div></div>
          <div class="filter-bar"><select><option>Bulanan</option><option>Harian</option><option>Mingguan</option><option>Custom date</option></select><input type="date"><input type="date"><select><option>Semua laporan</option></select></div>
          <div class="report-grid">
            <?php foreach (['Rekap kegiatan marketing', 'Rekap anggaran iklan', 'Rekap aktivitas lain', 'Rekap performa staff', 'Rekap performa wilayah', 'Rekap performa unit/kampus'] as $item): ?>
              <article class="report-card"><strong><?= h($item) ?></strong><span>Siap difilter dan diekspor</span></article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php elseif ($page === 'detail'): ?>
        <?php if (!$detailReport): ?>
          <section class="panel">
            <div class="panel-head"><h2>Laporan tidak ditemukan</h2><span>Data tidak tersedia di area ini</span></div>
            <p class="muted">Pastikan laporan yang dibuka berasal dari <?= h($area) ?>.</p>
            <a class="secondary-btn" href="<?= h(url_for('dashboard', $role)) ?>">Kembali ke Dashboard</a>
          </section>
        <?php else: ?>
          <section class="panel detail-panel">
            <div class="panel-head">
              <div>
                <h2><?= h((string) $detailReport['title']) ?></h2>
                <span><?= h((string) $detailReport['report_type']) ?> - <?= h((string) $detailReport['report_date']) ?></span>
              </div>
              <div class="actions">
                <?= badge((string) $detailReport['status']) ?>
                <a class="secondary-btn" href="<?= h(url_for(report_page_for((string) $detailReport['report_type']), $role)) ?>">Kembali</a>
              </div>
            </div>

            <div class="detail-grid">
              <?php foreach (detail_fields($detailReport) as $label => $value): ?>
                <div class="detail-item">
                  <span><?= h($label) ?></span>
                  <strong><?= h($value !== '' ? $value : '-') ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head"><h2>Catatan & Revisi</h2><span>Informasi tindak lanjut</span></div>
            <div class="detail-note">
              <strong>Catatan laporan</strong>
              <p><?= h((string) ($detailReport['notes'] ?: '-')) ?></p>
            </div>
            <div class="detail-note">
              <strong>Catatan revisi</strong>
              <p><?= h((string) ($detailReport['revision_note'] ?: '-')) ?></p>
            </div>
          </section>

          <?php if (($detailReport['report_type'] ?? '') === 'ads'): ?>
            <section class="panel">
              <div class="panel-head">
                <div><h2>Data Hasil Iklan</h2><span>Isi dari file XLS yang diupload pada laporan iklan</span></div>
                <a class="secondary-btn" href="download-ad-lead-template.php">Download Template .xlsx</a>
              </div>
              <form class="inline-upload" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_ad_leads">
                <input type="hidden" name="report_id" value="<?= h((string) $detailReport['id']) ?>">
                <input type="file" name="ad_leads_file" accept=".xls,.xlsx" required>
                <button class="primary-btn">Upload / Ganti Data</button>
              </form>
              <?php render_ad_leads_table($detailAdLeads); ?>
            </section>
          <?php endif; ?>

          <section class="panel">
            <div class="panel-head"><h2>Riwayat Status</h2><span>Log aktivitas laporan</span></div>
            <div class="log-list">
              <?php if (!$detailLogs): ?>
                <div><strong>Belum ada log</strong><span>Riwayat akan muncul setelah laporan dibuat atau status berubah.</span></div>
              <?php endif; ?>
              <?php foreach ($detailLogs as $log): ?>
                <div><strong><?= h((string) $log['created_at']) ?></strong><span><?= h((string) $log['actor_name']) ?> - <?= h((string) $log['action_name']) ?> <?= h((string) ($log['old_status'] ?? '')) ?> <?= $log['new_status'] ? '-> ' . h((string) $log['new_status']) : '' ?></span></div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php elseif ($page === 'edit'): ?>
        <?php if (!$editReport): ?>
          <section class="panel">
            <div class="panel-head"><h2>Laporan tidak ditemukan</h2><span>Data tidak tersedia di area ini</span></div>
            <p class="muted">Pastikan laporan yang dibuka berasal dari <?= h($area) ?>.</p>
            <a class="secondary-btn" href="<?= h(url_for('dashboard', $role)) ?>">Kembali ke Dashboard</a>
          </section>
        <?php elseif (!can_edit_report($role, (string) $editReport['status'])): ?>
          <section class="panel">
            <div class="panel-head"><h2>Tidak bisa diedit</h2><span>Status laporan: <?= h((string) $editReport['status']) ?></span></div>
            <p class="muted">Role Staff Unit hanya bisa mengedit laporan berstatus Draft atau Revisi.</p>
            <a class="secondary-btn" href="<?= h(url_for('detail', $role) . '&id=' . (int) $editReport['id']) ?>">Kembali ke Detail</a>
          </section>
        <?php else: ?>
          <?php render_edit_form($editReport, $role, $references, $authUser); ?>
        <?php endif; ?>
      <?php elseif ($page === 'password'): ?>
        <section class="panel form-panel">
          <div class="panel-head">
            <div>
              <h2>Ganti Password</h2>
              <span>Gunakan password pribadi setelah login pertama</span>
            </div>
          </div>
          <form class="data-form" method="post">
            <input type="hidden" name="action" value="change_password">
            <label><span>Password lama</span><input type="password" name="old_password" required></label>
            <label><span>Password baru</span><input type="password" name="new_password" minlength="6" required></label>
            <label><span>Ulangi password baru</span><input type="password" name="confirm_password" minlength="6" required></label>
            <div class="form-actions"><button class="primary-btn">Simpan Password</button></div>
          </form>
        </section>
      <?php elseif ($page === 'profile'): ?>
        <?php render_profile_page($profileData ?: ['target' => $authUser, 'allowed_users' => [], 'stats' => [], 'level' => [], 'xp' => 0, 'league' => 'Starter', 'status_performa' => 'Belum ada data', 'joined_label' => '-', 'streak' => ['current' => 0, 'longest' => 0], 'competencies' => [], 'badges' => [], 'kpis' => [], 'activities' => [], 'history' => [], 'score' => 0], $authUser, $role); ?>
      <?php elseif ($page === 'users' && ($authUser['role'] ?? '') === 'senior'): ?>
        <section class="panel form-panel">
          <div class="panel-head">
            <div>
              <h2>Tambah User RSM</h2>
              <span>Password tidak ditampilkan. RSM bisa membuat atau reset password baru.</span>
            </div>
          </div>
          <form class="data-form" method="post">
            <input type="hidden" name="action" value="admin_create_user">
            <label><span>Nama</span><input name="name" required></label>
            <label><span>NIK</span><input name="nik" placeholder="Opsional"></label>
            <label><span>Username</span><input name="username" required></label>
            <label><span>Role</span><select name="user_role"><option value="staff">Staff Unit</option><option value="koordinator">Koordinator Wilayah</option><option value="senior">Senior Manager</option></select></label>
            <label><span>Jabatan</span><input name="jabatan" value="Staff Unit"></label>
            <label><span>Regional</span><select name="regional"><option value="">Pilih regional</option><?php foreach (rsm_area_regionals($area) as $regionalOption): ?><option><?= h($regionalOption) ?></option><?php endforeach; ?></select></label>
            <label><span>Area</span><input name="area" value="<?= h($area) ?>"></label>
            <label><span>Kampus/Unit</span><input name="campus_name"></label>
            <label><span>Password awal</span><input name="new_user_password" value="kptsukses" required></label>
            <div class="form-actions"><button class="primary-btn">Tambah User</button></div>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Daftar User RSM</h2><span>Password asli tidak bisa ditampilkan karena disimpan aman sebagai hash</span></div>
          <?php render_users_table($managedUsers); ?>
        </section>
      <?php else: ?>
        <section class="panel">
          <div class="panel-head"><h2>User & Role</h2><span>Aturan akses operasional</span></div>
          <div class="role-grid">
            <?php foreach ($roles as $key => $item): ?>
              <article class="role-policy">
                <h3><?= h($item['label']) ?></h3>
                <p><?= h($item['scope']) ?></p>
                <ul>
                  <?php foreach (role_points($key) as $point): ?>
                    <li><?= h($point) ?></li>
                  <?php endforeach; ?>
                </ul>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Log Aktivitas</h2><span>Semua perubahan status wajib tercatat</span></div>
          <div class="log-list">
            <?php if (!$logs): ?>
              <div><strong>Belum ada log</strong><span>Aktivitas status akan muncul setelah ada perubahan laporan.</span></div>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
              <div><strong><?= h((string) $log['created_at']) ?></strong><span><?= h((string) $log['actor_name']) ?> - <?= h((string) $log['action_name']) ?> <?= h((string) ($log['new_status'] ?? '')) ?></span></div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>
  <script src="assets/app.js"></script>
</body>
</html>
<?php
function render_login_page(?string $error): void
{
    ?>
    <!doctype html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Login RSM</title>
      <link rel="stylesheet" href="assets/style.css">
    </head>
    <body class="login-body">
      <main class="login-card">
        <p class="eyebrow">Regional Senior Manager</p>
        <h1>Masuk Dashboard</h1>
        <p>Gunakan username dari NIK tanpa titik dan password yang diberikan admin.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="login-form">
          <input type="hidden" name="action" value="login">
          <label><span>Username</span><input name="username" autocomplete="username" required autofocus></label>
          <label><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
          <button class="primary-btn">Masuk</button>
        </form>
      </main>
    </body>
    </html>
    <?php
}

function render_profile_page(array $profile, array $authUser, string $role): void
{
    $user = $profile['target'] ?? $authUser;
    $photoPath = trim((string) ($user['photo_path'] ?? ''));
    $initial = strtoupper(substr((string) ($user['name'] ?? 'U'), 0, 1));
    $level = $profile['level'] ?? ['number' => 1, 'progress' => 0, 'remaining' => 0, 'next_min' => 200];
    $stats = $profile['stats'] ?? [];
    $xp = (int) ($profile['xp'] ?? 0);
    $score = (int) ($profile['score'] ?? 0);
    $isOwnProfile = (int) ($user['id'] ?? 0) === (int) ($authUser['id'] ?? 0);
    $allowedUsers = $profile['allowed_users'] ?? [];
    $circle = max(0, min(100, (float) ($level['progress'] ?? 0)));
    ?>
    <section class="profile-game-shell">
      <aside class="profile-game-sidebar">
        <div class="profile-avatar-xl">
          <?php if ($photoPath !== ''): ?>
            <img src="<?= h($photoPath) ?>" alt="Foto profil <?= h((string) ($user['name'] ?? 'User')) ?>">
          <?php else: ?>
            <span><?= h($initial) ?></span>
          <?php endif; ?>
        </div>
        <strong><?= h((string) ($user['name'] ?? '-')) ?></strong>
        <small><?= h((string) (($user['username'] ?? '') ?: ('ID #' . (int) ($user['id'] ?? 0)))) ?></small>
        <div class="profile-meta-list">
          <span><?= h(ucfirst((string) ($user['role'] ?? '-'))) ?></span>
          <span><?= h((string) (($user['campus_name'] ?? '') ?: 'Unit belum diatur')) ?></span>
          <span><?= h((string) (($user['regional'] ?? '') ?: 'Wilayah belum diatur')) ?></span>
        </div>
        <div class="profile-level-card">
          <div><span>Level</span><strong><?= h((string) ($level['number'] ?? 1)) ?></strong></div>
          <div><span>XP</span><strong><?= h(number_format($xp, 0, ',', '.')) ?></strong></div>
          <div class="profile-xp-bar"><i style="width: <?= h((string) $circle) ?>%"></i></div>
          <small><?= h(number_format((int) ($level['remaining'] ?? 0), 0, ',', '.')) ?> XP menuju level berikutnya</small>
        </div>
        <nav class="profile-section-menu">
          <a href="#ringkasan">Ringkasan</a>
          <a href="#misi">Misi</a>
          <a href="#kompetensi">Kompetensi</a>
          <a href="#pencapaian">Pencapaian</a>
          <a href="#aktivitas">Aktivitas</a>
          <a href="#pengaturan">Pengaturan Profil</a>
        </nav>
      </aside>

      <div class="profile-game-content">
        <?php if (count($allowedUsers) > 1): ?>
          <form class="profile-user-switch" method="get">
            <input type="hidden" name="page" value="profile">
            <?php if (count($GLOBALS['allowedRoleKeys'] ?? []) > 1): ?><input type="hidden" name="role" value="<?= h($role) ?>"><?php endif; ?>
            <label><span>Lihat profil user</span><select name="user_id" onchange="this.form.submit()">
              <?php foreach ($allowedUsers as $option): ?>
                <option value="<?= h((string) ($option['id'] ?? 0)) ?>" <?= (int) ($option['id'] ?? 0) === (int) ($user['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($option['name'] ?? '-')) ?> - <?= h((string) ($option['regional'] ?? '-')) ?></option>
              <?php endforeach; ?>
            </select></label>
          </form>
        <?php endif; ?>

        <section class="profile-stat-header" id="ringkasan">
          <article><span>Rank/League</span><strong><?= h((string) ($profile['league'] ?? 'Starter')) ?></strong><small>Berbasis XP, closing, dan konsistensi</small></article>
          <article><span>Lama Bergabung</span><strong><?= h((string) ($profile['joined_label'] ?? '-')) ?></strong><small>Sejak akun dibuat</small></article>
          <article><span>Status Performa</span><strong><?= h((string) ($profile['status_performa'] ?? 'Belum ada data')) ?></strong><small>Skor saat ini <?= h((string) $score) ?>/100</small></article>
        </section>

        <section class="profile-hero-card">
          <div class="profile-hero-copy">
            <span class="eyebrow">Profil User</span>
            <h2><?= h((string) ($user['name'] ?? '-')) ?></h2>
            <p><?= h((string) (($user['bio_text'] ?? '') ?: 'Biodata singkat belum diisi.')) ?></p>
            <div class="profile-identity-tags">
              <span><?= h((string) ($user['jabatan'] ?? '-')) ?></span>
              <span><?= h((string) (($user['campus_name'] ?? '') ?: 'Unit belum diatur')) ?></span>
              <span><?= h((string) (($user['regional'] ?? '') ?: 'Wilayah belum diatur')) ?></span>
            </div>
          </div>
          <div class="profile-radial" style="--value: <?= h((string) $circle) ?>">
            <div><strong><?= h((string) ($level['number'] ?? 1)) ?></strong><span>Level</span></div>
          </div>
          <div class="profile-core-metrics">
            <div><span>Aktivitas</span><strong><?= h(number_format((int) ($stats['total_reports'] ?? 0), 0, ',', '.')) ?></strong></div>
            <div><span>Konsistensi</span><strong><?= h(number_format((int) (($profile['streak']['current'] ?? 0)), 0, ',', '.')) ?> hari</strong></div>
            <div><span>Konversi</span><strong><?= h(percent_label(rsm_dashboard_percent((float) ($stats['closing_total'] ?? 0), (float) ($stats['leads_total'] ?? 0)))) ?></strong></div>
          </div>
        </section>

        <section class="profile-metric-grid">
          <article><span>Total kegiatan</span><strong><?= h(number_format((int) ($stats['total_reports'] ?? 0), 0, ',', '.')) ?></strong></article>
          <article><span>Total leads</span><strong><?= h(number_format((int) ($stats['leads_total'] ?? 0), 0, ',', '.')) ?></strong></article>
          <article><span>Total closing</span><strong><?= h(number_format((int) ($stats['closing_total'] ?? 0), 0, ',', '.')) ?></strong></article>
          <article><span>Hari aktif</span><strong><?= h(number_format((int) ($stats['active_days'] ?? 0), 0, ',', '.')) ?></strong></article>
          <article><span>Streak laporan</span><strong><?= h(number_format((int) ($profile['streak']['current'] ?? 0), 0, ',', '.')) ?></strong><small>Longest <?= h(number_format((int) ($profile['streak']['longest'] ?? 0), 0, ',', '.')) ?> hari</small></article>
          <article><span>Badge terbuka</span><strong><?= h(number_format(count(array_filter($profile['badges'] ?? [], static fn (array $badge): bool => (bool) ($badge['unlocked'] ?? false))), 0, ',', '.')) ?></strong></article>
        </section>

        <section class="profile-two-col" id="misi">
          <div class="panel profile-section-card">
            <div class="panel-head"><h2>KPI Personal</h2><span>Target belum diatur untuk fase ini</span></div>
            <div class="profile-kpi-list">
              <?php foreach (($profile['kpis'] ?? []) as $kpi): ?>
                <div class="profile-kpi-row">
                  <div><strong><?= h((string) ($kpi['label'] ?? '-')) ?></strong><small>Target belum diatur</small></div>
                  <span><?= h(number_format((float) ($kpi['value'] ?? 0), 0, ',', '.')) ?></span>
                  <div class="profile-xp-bar"><i style="width: <?= ((float) ($kpi['value'] ?? 0)) > 0 ? '36' : '0' ?>%"></i></div>
                  <em><?= h((string) ($kpi['status'] ?? 'Belum mulai')) ?></em>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="panel profile-section-card" id="kompetensi">
            <div class="panel-head"><h2>Kompetensi</h2><span>Skor dari data aktual</span></div>
            <div class="profile-skill-list">
              <?php foreach (($profile['competencies'] ?? []) as $skill): $skillScore = max(0, min(100, (float) ($skill['score'] ?? 0))); ?>
                <div><span><?= h((string) ($skill['label'] ?? '-')) ?><b><?= h(number_format($skillScore, 0, ',', '.')) ?></b></span><i><em style="width: <?= h((string) $skillScore) ?>%"></em></i></div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section class="panel profile-section-card" id="pencapaian">
          <div class="panel-head"><h2>Badge dan Achievement</h2><span>Dihitung dinamis, belum disimpan sebagai tabel baru</span></div>
          <div class="profile-badge-grid">
            <?php foreach (($profile['badges'] ?? []) as $badge): ?>
              <article class="<?= !empty($badge['unlocked']) ? 'unlocked' : 'locked' ?>">
                <b><?= h((string) ($badge['icon'] ?? '*')) ?></b>
                <strong><?= h((string) ($badge['name'] ?? '-')) ?></strong>
                <small><?= h((string) ($badge['desc'] ?? '-')) ?></small>
                <span><?= !empty($badge['unlocked']) ? 'Terbuka' : 'Terkunci' ?></span>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="profile-two-col">
          <div class="panel profile-section-card">
            <div class="panel-head"><h2>Riwayat Performa</h2><span>6 bulan terakhir</span></div>
            <div class="profile-history-chart">
              <?php $maxHistory = max(1, ...array_map(static fn (array $row): int => max((int) ($row['activities'] ?? 0), (int) ($row['leads'] ?? 0), (int) ($row['closing'] ?? 0), (int) (($row['xp'] ?? 0) / 10)), $profile['history'] ?? [])); ?>
              <?php foreach (($profile['history'] ?? []) as $row): ?>
                <div>
                  <span><?= h((string) ($row['label'] ?? '-')) ?></span>
                  <i title="Aktivitas"><em style="height: <?= h((string) max(4, (((int) ($row['activities'] ?? 0)) / $maxHistory) * 100)) ?>%"></em></i>
                  <i title="Leads"><em style="height: <?= h((string) max(4, (((int) ($row['leads'] ?? 0)) / $maxHistory) * 100)) ?>%"></em></i>
                  <i title="Closing"><em style="height: <?= h((string) max(4, (((int) ($row['closing'] ?? 0)) / $maxHistory) * 100)) ?>%"></em></i>
                  <small><?= h(number_format((int) ($row['xp'] ?? 0), 0, ',', '.')) ?> XP</small>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="panel profile-section-card" id="aktivitas">
            <div class="panel-head"><h2>Aktivitas Terbaru</h2><span>Maksimal 10 laporan</span></div>
            <div class="profile-activity-list">
              <?php if (empty($profile['activities'])): ?><p class="muted">Belum ada aktivitas untuk user ini.</p><?php endif; ?>
              <?php foreach (($profile['activities'] ?? []) as $activity): ?>
                <article>
                  <time><?= h((string) ($activity['report_date'] ?? '-')) ?></time>
                  <strong><?= h((string) ($activity['title'] ?? '-')) ?></strong>
                  <span><?= h((string) ($activity['report_type'] ?? '-')) ?> - <?= h((string) ($activity['unit_name'] ?? '-')) ?></span>
                  <small><?= h(number_format((float) ($activity['leads_count'] ?? 0), 0, ',', '.')) ?> leads, <?= h(number_format((float) ($activity['closing_count'] ?? 0), 0, ',', '.')) ?> closing - <?= h((string) ($activity['status'] ?? '-')) ?></small>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <?php if ($isOwnProfile): ?>
          <section class="panel profile-section-card" id="pengaturan">
            <div class="panel-head"><h2>Pengaturan Profil</h2><span>Edit biodata singkat dan foto</span></div>
            <form class="data-form profile-form" method="post" enctype="multipart/form-data">
              <input type="hidden" name="action" value="update_profile">
              <label><span>Nama</span><input class="locked-input" value="<?= h((string) ($user['name'] ?? '-')) ?>" readonly></label>
              <label><span>NIK</span><input class="locked-input" value="<?= h((string) (($user['nik'] ?? '') ?: '-')) ?>" readonly></label>
              <label><span>Username</span><input class="locked-input" value="<?= h((string) ($user['username'] ?? '-')) ?>" readonly></label>
              <label><span>Jabatan</span><input class="locked-input" value="<?= h((string) ($user['jabatan'] ?? '-')) ?>" readonly></label>
              <label class="form-full"><span>Biodata singkat</span><textarea name="bio_text" rows="5" maxlength="800" placeholder="Ceritakan singkat peran, fokus kerja, atau target PMB kamu."><?= h((string) ($user['bio_text'] ?? '')) ?></textarea></label>
              <label class="form-full"><span>Foto profil</span><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small class="field-hint">Format JPG, PNG, atau WEBP. Maksimal 2 MB.</small></label>
              <div class="form-actions form-full"><button class="primary-btn">Simpan Profil</button></div>
            </form>
          </section>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

function field_name_for(string $field): string
{
    $map = [
        'Tanggal kegiatan' => 'report_date',
        'Tanggal' => 'report_date',
        'Wilayah' => 'wilayah',
        'Unit/Kampus' => 'unit_name',
        'Nama staff' => 'staff_name',
        'Jenis kegiatan' => 'activity_kind',
        'Nama kegiatan' => 'title',
        'Lokasi kegiatan' => 'location_name',
        'Target kegiatan' => 'target_text',
        'Hasil kegiatan' => 'result_text',
        'Jumlah prospek/leads' => 'leads_count',
        'Catatan' => 'notes',
        'Platform iklan' => 'platform',
        'Nama campaign' => 'campaign_name',
        'Tujuan iklan' => 'ad_goal',
        'Anggaran diajukan' => 'budget_requested',
        'Anggaran disetujui' => 'budget_approved',
        'Realisasi pemakaian' => 'realization_amount',
        'Jumlah leads masuk' => 'leads_count',
        'CPL / Cost per Lead' => 'cpl',
        'Link campaign' => 'campaign_link',
        'Catatan performa iklan' => 'notes',
        'Kategori aktivitas' => 'category',
        'Deskripsi aktivitas' => 'title',
        'Hasil aktivitas' => 'result_text',
        'Kendala' => 'obstacle_text',
        'Tindak lanjut' => 'follow_up_text',
        'Upload dokumentasi/foto' => 'attachment_path',
        'Upload bukti invoice/screenshot' => 'attachment_path',
        'Upload data hasil iklan (.xls/.xlsx)' => 'ad_leads_file',
        'Upload dokumentasi' => 'attachment_path',
    ];

    return $map[$field] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field) ?? $field);
}

function is_staff_user(?array $authUser): bool
{
    return (string) ($authUser['role'] ?? '') === 'staff';
}

function staff_identity_value(string $field, ?array $authUser, array $references): string
{
    if ($field === 'Wilayah') {
        return (string) (($authUser['regional'] ?? '') ?: ($references['regionals'][0] ?? ''));
    }
    if ($field === 'Unit/Kampus') {
        return (string) (($authUser['campus_name'] ?? '') ?: ($references['campuses'][0]['label'] ?? ''));
    }
    if ($field === 'Nama staff') {
        return (string) (($authUser['name'] ?? '') ?: ($references['staff'][0]['name'] ?? ''));
    }

    return '';
}

function render_locked_identity_input(string $field, string $name, ?array $authUser, array $references): void
{
    $value = staff_identity_value($field, $authUser, $references);
    ?>
    <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
    <div class="locked-value" title="Otomatis sesuai akun login"><?= h($value !== '' ? $value : 'Belum terhubung') ?></div>
    <?php
}

function render_form_panel(string $title, array $fields, array $options, array $statuses, string $action, array $references, ?array $authUser = null): void
{
    ?>
    <section class="panel form-panel">
      <div class="panel-head">
        <h2><?= h($title) ?></h2>
        <span>Form input operasional</span>
      </div>
      <form class="data-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= h($action) ?>">
        <?php foreach ($fields as $index => $field): ?>
          <?php $name = field_name_for($field); ?>
          <label class="<?= in_array($field, ['Catatan', 'Hasil kegiatan', 'Deskripsi aktivitas', 'Catatan performa iklan', 'Kendala', 'Tindak lanjut', 'Upload data hasil iklan (.xls/.xlsx)'], true) ? 'wide' : '' ?>">
            <span><?= h($field) ?></span>
            <?php if (str_contains(strtolower($field), 'tanggal')): ?>
              <input type="date" name="<?= h($name) ?>" value="<?= h(date('Y-m-d')) ?>">
            <?php elseif (is_staff_user($authUser) && in_array($field, ['Wilayah', 'Unit/Kampus', 'Nama staff'], true)): ?>
              <?php render_locked_identity_input($field, $name, $authUser, $references); ?>
            <?php elseif ($field === 'Wilayah'): ?>
              <select name="<?= h($name) ?>" required>
                <option value="">Pilih wilayah</option>
                <?php foreach ($references['regionals'] as $regionalOption): ?>
                  <option value="<?= h((string) $regionalOption) ?>"><?= h((string) $regionalOption) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($field === 'Unit/Kampus'): ?>
              <select name="<?= h($name) ?>" required>
                <option value="">Pilih unit/kampus</option>
                <?php foreach ($references['campuses'] as $campusOption): ?>
                  <option value="<?= h((string) $campusOption['label']) ?>"><?= h((string) $campusOption['label']) ?><?= !empty($campusOption['kode_kampus']) ? ' [' . h(strtoupper((string) $campusOption['kode_kampus'])) . ']' : '' ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($field === 'Nama staff'): ?>
              <select name="<?= h($name) ?>" required>
                <option value="">Pilih staff</option>
                <?php foreach ($references['staff'] as $staffOption): ?>
                  <option value="<?= h((string) $staffOption['name']) ?>"><?= h((string) $staffOption['name']) ?> - <?= h((string) ($staffOption['regional'] ?? '-')) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif (str_contains(strtolower($field), 'upload')): ?>
              <?php if ($name === 'ad_leads_file'): ?>
                <div class="file-with-action">
                  <input type="file" name="<?= h($name) ?>" accept=".xls,.xlsx">
                  <a class="secondary-btn" href="download-ad-lead-template.php">Download Template .xlsx</a>
                </div>
                <small class="field-hint">Bisa upload .xls atau .xlsx. Template yang disediakan format .xlsx.</small>
              <?php else: ?>
                <input type="file" name="<?= h($name) ?>">
              <?php endif; ?>
            <?php elseif (str_contains(strtolower($field), 'tujuan iklan')): ?>
              <select name="<?= h($name) ?>"><option>Leads</option><option>Awareness</option><option>Traffic</option><option>Conversion</option></select>
            <?php elseif (str_contains(strtolower($field), 'jenis') || str_contains(strtolower($field), 'platform') || str_contains(strtolower($field), 'kategori')): ?>
              <select name="<?= h($name) ?>"><?php foreach ($options as $option): ?><option><?= h($option) ?></option><?php endforeach; ?></select>
            <?php elseif (str_contains(strtolower($field), 'hasil') || str_contains(strtolower($field), 'catatan') || str_contains(strtolower($field), 'kendala') || str_contains(strtolower($field), 'tindak')): ?>
              <textarea name="<?= h($name) ?>" rows="3"></textarea>
            <?php else: ?>
              <input name="<?= h($name) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <label><span>Status</span><select name="status"><?php foreach ($statuses as $status): ?><option><?= h($status) ?></option><?php endforeach; ?></select></label>
        <div class="form-actions"><button class="secondary-btn" name="status" value="Draft">Simpan Draft</button><button class="primary-btn">Kirim Laporan</button></div>
      </form>
    </section>
    <?php
}

function render_edit_form(array $report, string $role, array $references, ?array $authUser = null): void
{
    $type = (string) $report['report_type'];
    $fields = report_fields_for_type($type);
    $options = report_options_for_type($type);
    $statuses = ['Draft', 'Revisi'];
    ?>
    <section class="panel form-panel">
      <div class="panel-head">
        <div>
          <h2>Edit <?= h(report_label_for_type($type)) ?></h2>
          <span><?= h((string) $report['title']) ?></span>
        </div>
        <a class="secondary-btn" href="<?= h(url_for('detail', $role) . '&id=' . (int) $report['id']) ?>">Kembali ke Detail</a>
      </div>
      <form class="data-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_report">
        <input type="hidden" name="report_id" value="<?= h((string) $report['id']) ?>">
        <?php foreach ($fields as $field): ?>
          <?php $name = field_name_for($field); ?>
          <label class="<?= in_array($field, ['Catatan', 'Hasil kegiatan', 'Deskripsi aktivitas', 'Catatan performa iklan', 'Kendala', 'Tindak lanjut', 'Upload data hasil iklan (.xls/.xlsx)'], true) ? 'wide' : '' ?>">
            <span><?= h($field) ?></span>
            <?php render_edit_input($field, $name, $report, $options, $references, $authUser); ?>
          </label>
        <?php endforeach; ?>
        <label><span>Status</span><select name="status"><?php foreach ($statuses as $status): ?><option<?= (string) $report['status'] === $status ? ' selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select></label>
        <div class="form-actions">
          <a class="secondary-btn" href="<?= h(url_for(report_page_for($type), $role)) ?>">Batal</a>
          <button class="primary-btn">Simpan Perubahan</button>
        </div>
      </form>
    </section>
    <?php
}

function render_edit_input(string $field, string $name, array $report, array $options, array $references, ?array $authUser = null): void
{
    $value = (string) ($report[$name] ?? '');
    if (str_contains(strtolower($field), 'tanggal')) {
        ?><input type="date" name="<?= h($name) ?>" value="<?= h($value !== '' ? $value : date('Y-m-d')) ?>"><?php
    } elseif (is_staff_user($authUser) && in_array($field, ['Wilayah', 'Unit/Kampus', 'Nama staff'], true)) {
        render_locked_identity_input($field, $name, $authUser, $references);
    } elseif ($field === 'Wilayah') {
        ?><select name="<?= h($name) ?>" required><?php foreach ($references['regionals'] as $regionalOption): ?><option value="<?= h((string) $regionalOption) ?>"<?= $value === (string) $regionalOption ? ' selected' : '' ?>><?= h((string) $regionalOption) ?></option><?php endforeach; ?></select><?php
    } elseif ($field === 'Unit/Kampus') {
        ?><select name="<?= h($name) ?>" required><?php foreach ($references['campuses'] as $campusOption): $label = (string) $campusOption['label']; ?><option value="<?= h($label) ?>"<?= $value === $label ? ' selected' : '' ?>><?= h($label) ?><?= !empty($campusOption['kode_kampus']) ? ' [' . h(strtoupper((string) $campusOption['kode_kampus'])) . ']' : '' ?></option><?php endforeach; ?></select><?php
    } elseif ($field === 'Nama staff') {
        ?><select name="<?= h($name) ?>" required><?php foreach ($references['staff'] as $staffOption): $label = (string) $staffOption['name']; ?><option value="<?= h($label) ?>"<?= $value === $label ? ' selected' : '' ?>><?= h($label) ?> - <?= h((string) ($staffOption['regional'] ?? '-')) ?></option><?php endforeach; ?></select><?php
    } elseif ($name === 'ad_leads_file') {
        ?><div class="file-with-action"><input type="file" name="<?= h($name) ?>" accept=".xls,.xlsx"><a class="secondary-btn" href="download-ad-lead-template.php">Download Template .xlsx</a></div><small class="field-hint">Kosongkan jika tidak ingin menambah data hasil iklan. Format upload: .xls atau .xlsx.</small><?php
    } elseif (str_contains(strtolower($field), 'upload')) {
        ?><input type="file" name="<?= h($name) ?>"><?php
    } elseif (str_contains(strtolower($field), 'tujuan iklan')) {
        ?><select name="<?= h($name) ?>"><?php foreach (['Leads', 'Awareness', 'Traffic', 'Conversion'] as $option): ?><option<?= $value === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select><?php
    } elseif (str_contains(strtolower($field), 'jenis') || str_contains(strtolower($field), 'platform') || str_contains(strtolower($field), 'kategori')) {
        ?><select name="<?= h($name) ?>"><?php foreach ($options as $option): ?><option<?= $value === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select><?php
    } elseif (str_contains(strtolower($field), 'hasil') || str_contains(strtolower($field), 'catatan') || str_contains(strtolower($field), 'kendala') || str_contains(strtolower($field), 'tindak')) {
        ?><textarea name="<?= h($name) ?>" rows="3"><?= h($value) ?></textarea><?php
    } else {
        ?><input name="<?= h($name) ?>" value="<?= h($value) ?>"><?php
    }
}

function report_fields_for_type(string $type): array
{
    if ($type === 'ads') {
        return ['Tanggal', 'Wilayah', 'Unit/Kampus', 'Platform iklan', 'Nama campaign', 'Tujuan iklan', 'Anggaran diajukan', 'Anggaran disetujui', 'Realisasi pemakaian', 'CPL / Cost per Lead', 'Link campaign', 'Upload data hasil iklan (.xls/.xlsx)', 'Catatan performa iklan'];
    }
    if ($type === 'other') {
        return ['Tanggal', 'Wilayah', 'Unit/Kampus', 'Nama staff', 'Kategori aktivitas', 'Deskripsi aktivitas', 'Hasil aktivitas', 'Kendala', 'Tindak lanjut', 'Upload dokumentasi'];
    }
    return ['Tanggal kegiatan', 'Wilayah', 'Unit/Kampus', 'Nama staff', 'Jenis kegiatan', 'Nama kegiatan', 'Lokasi kegiatan', 'Target kegiatan', 'Hasil kegiatan', 'Jumlah prospek/leads', 'Catatan', 'Upload dokumentasi/foto'];
}

function report_options_for_type(string $type): array
{
    if ($type === 'ads') {
        return ['Meta Ads', 'Google Ads', 'TikTok Ads', 'WhatsApp Blast', 'Marketplace/Portal', 'Lainnya'];
    }
    if ($type === 'other') {
        return ['Meeting internal', 'Briefing', 'Training', 'Koordinasi kampus', 'Koordinasi mitra', 'Pelayanan calon mahasiswa', 'Administrasi PMB', 'Follow up pembayaran', 'Herregistrasi', 'Lainnya'];
    }
    return ['Follow up leads', 'Kunjungan sekolah', 'Kunjungan instansi', 'Sebar brosur', 'Pasang spanduk', 'Event kampus', 'Presentasi PMB', 'Aktivitas digital', 'Lainnya'];
}

function report_label_for_type(string $type): string
{
    return ['ads' => 'Laporan Iklan', 'other' => 'Aktivitas Lain', 'marketing' => 'Kegiatan Marketing'][$type] ?? 'Laporan';
}

function render_collab_source_note(array $sources): void
{
    $registrasi = $sources['registrasi'] ?? [];
    $herregistrasi = $sources['herregistrasi'] ?? [];
    $registrasiMode = (string) ($registrasi['mode'] ?? '');
    $herregistrasiMode = (string) ($herregistrasi['mode'] ?? '');
    $registrasiTimeLabel = str_starts_with($registrasiMode, 'live_url') ? 'Jam baca' : 'Jam snapshot';
    $herregistrasiTimeLabel = str_starts_with($herregistrasiMode, 'live_url') ? 'Jam baca' : 'Jam snapshot';
    ?>
    <div class="source-note-grid">
      <div>
        <span>Sumber registrasi</span>
        <strong><?= h((string) ($registrasi['label'] ?? 'Closing Collab')) ?></strong>
        <?php if (!empty($registrasi['month']) || !empty($registrasi['mode'])): ?><em><?= h(trim((string) ($registrasi['month'] ?? '') . ' ' . $registrasiMode)) ?></em><?php endif; ?>
        <?php if (!empty($registrasi['time'])): ?><small><?= h($registrasiTimeLabel) ?>: <?= h((string) $registrasi['time']) ?> WIB</small><?php endif; ?>
      </div>
      <div>
        <span>Sumber herregistrasi</span>
        <strong><?= h((string) ($herregistrasi['label'] ?? 'Herreg Collab')) ?></strong>
        <?php if (!empty($herregistrasi['month']) || !empty($herregistrasi['mode'])): ?><em><?= h(trim((string) ($herregistrasi['month'] ?? '') . ' ' . $herregistrasiMode)) ?></em><?php endif; ?>
        <?php if (!empty($herregistrasi['time'])): ?><small><?= h($herregistrasiTimeLabel) ?>: <?= h((string) $herregistrasi['time']) ?> WIB</small><?php endif; ?>
      </div>
    </div>
    <?php
}

function render_regional_achievement_summary(array $rows): void
{
    ?>
    <div class="regional-achievement-grid">
      <?php if (!$rows): ?><div class="empty-game">Belum ada data dari source Collab pada filter ini.</div><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <article>
          <span><?= h((string) ($row['regional'] ?? '-')) ?></span>
          <strong><?= h(number_format((float) ($row['registrasi'] ?? 0), 0, ',', '.')) ?> registrasi</strong>
          <small><?= h(number_format((float) ($row['herregistrasi'] ?? 0), 1, ',', '.')) ?> herregistrasi</small>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
}

function render_staff_achievement_table(array $rows): void
{
    ?>
    <div class="table-wrap achievement-table"><table><thead><tr><th>Regional</th><th>NIK</th><th>Staff</th><th>Registrasi</th><th>Herregistrasi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">Belum ada data staff pada periode/filter ini.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) (($row['regional'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['nik'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['name'] ?? '') ?: '-')) ?></td>
          <td><strong><?= h(number_format((float) ($row['registrasi'] ?? 0), 0, ',', '.')) ?></strong></td>
          <td><strong><?= h(number_format((float) ($row['herregistrasi'] ?? 0), 1, ',', '.')) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_ranking_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Rank</th><th>Unit/Kampus</th><th>Leads</th><th>Registrasi</th><th>Herregistrasi</th><th>Conversion</th><th>Spend</th><th>CPL</th><th>Cost/Registrasi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Belum ada data ranking pada periode/filter ini.</td></tr><?php endif; ?>
      <?php foreach ($rows as $index => $row): ?>
        <tr>
          <td><?= h((string) ($index + 1)) ?></td>
          <td><?= h((string) ($row['unit_label'] ?: '-')) ?></td>
          <td><?= h(number_format((float) ($row['leads_total'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((float) ($row['registrasi_total'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((float) ($row['herregistrasi_total'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(percent_label((float) ($row['conversion_rate'] ?? 0))) ?></td>
          <td><?= h(money_idr((float) ($row['spend_total'] ?? 0))) ?></td>
          <td><?= h(money_idr((float) ($row['cpl'] ?? 0))) ?></td>
          <td><?= h(money_idr((float) ($row['cost_per_registrasi'] ?? 0))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_daily_report_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Staff</th><th>Unit/Kampus</th><th>Jenis</th><th>Aktivitas</th><th>Hasil</th><th>Kendala</th><th>Rencana Berikutnya</th><th>Status</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Belum ada laporan staff pada periode/filter ini.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) ($row['report_date'] ?? '-')) ?></td>
          <td><?= h((string) ($row['staff_name'] ?? '-')) ?></td>
          <td><?= h((string) ($row['unit_name'] ?? '-')) ?></td>
          <td><?= h((string) ($row['report_type'] ?? '-')) ?></td>
          <td><?= h((string) (($row['category'] ?? '') ?: ($row['title'] ?? '-'))) ?></td>
          <td><?= h((string) (($row['result_text'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['obstacle_text'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['follow_up_text'] ?? '') ?: '-')) ?></td>
          <td><?= badge((string) ($row['status'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_status_mapping_panel(array $statusMap, array $buckets): void
{
    $labels = [
        'report_type' => 'Jenis Laporan',
        'status' => 'Status Laporan',
        'progress_status' => 'Progress Lead',
        'follow_up_result' => 'Hasil Follow Up',
        'closing_status' => 'Status Closing',
    ];
    ?>
    <div class="status-map-grid">
      <?php foreach ($labels as $key => $label): ?>
        <div class="status-map-card">
          <span><?= h($label) ?></span>
          <?php if (empty($statusMap[$key])): ?>
            <strong>Belum ada nilai</strong>
          <?php else: ?>
            <div class="status-chip-list">
              <?php foreach ($statusMap[$key] as $value): ?><b><?= h((string) $value) ?></b><?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mapping-note">
      <strong>Registrasi:</strong> <?= h($buckets['registrasi'] ? implode(', ', $buckets['registrasi']) : 'Belum ada status detail yang bisa dipetakan') ?>.
      <strong>Herregistrasi:</strong> <?= h($buckets['herregistrasi'] ? implode(', ', $buckets['herregistrasi']) : 'Belum ada status eksplisit herregistrasi') ?>.
    </div>
    <?php
}

function render_gamification_panel(array $gamification): void
{
    $myRank = $gamification['my_rank'] ?? null;
    ?>
    <div class="game-grid">
      <div class="game-card my-score-card">
        <span>Poin Saya</span>
        <?php if (is_array($myRank)): ?>
          <strong><?= h(number_format((float) ($myRank['points'] ?? 0), 0, ',', '.')) ?> poin</strong>
          <small>Rank #<?= h((string) ($myRank['rank'] ?? '-')) ?> - <?= h((string) ($myRank['staff_label'] ?? '-')) ?></small>
          <small>Closing acuan: <?= h(number_format((float) ($myRank['closing_for_points'] ?? 0), 0, ',', '.')) ?> dari <?= h((string) ($myRank['closing_points_source'] ?? 'RSM fallback')) ?></small>
          <small>Herregistrasi acuan: <?= h(number_format((float) ($myRank['herreg_for_points'] ?? 0), 0, ',', '.')) ?> dari <?= h((string) ($myRank['herreg_points_source'] ?? 'RSM fallback')) ?></small>
          <div class="badge-row">
            <?php foreach (($myRank['badges'] ?? []) as $badge): ?>
              <b class="game-badge badge-tone-<?= h((string) ($badge['tone'] ?? 'slate')) ?>"><?= h((string) ($badge['label'] ?? 'Badge')) ?></b>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <strong>0 poin</strong>
          <small>Belum ada poin pada periode ini</small>
        <?php endif; ?>
      </div>

      <div class="game-card challenge-card">
        <span><?= h((string) ($gamification['challenge']['title'] ?? 'Challenge')) ?></span>
        <ul>
          <?php foreach (($gamification['challenge']['items'] ?? []) as $item): ?>
            <li><?= h((string) $item) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="game-card rules-card">
        <span>Aturan Poin</span>
        <div class="rule-list">
          <?php foreach (($gamification['point_rules'] ?? []) as $rule): ?>
            <b><?= h((string) $rule) ?></b>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="leaderboard">
      <div class="leaderboard-head">
        <strong>Top Performer Bulan Ini</strong>
        <span>Ranking berdasarkan poin kualitas dan hasil</span>
      </div>
      <?php if (empty($gamification['leaderboard'])): ?>
        <div class="empty-game">Belum ada poin leaderboard pada periode/filter ini.</div>
      <?php endif; ?>
      <?php foreach (($gamification['leaderboard'] ?? []) as $index => $row): ?>
        <div class="leaderboard-row rank-<?= h((string) min(3, $index + 1)) ?>">
          <div class="rank-medal"><?= h((string) ($index + 1)) ?></div>
          <div class="leader-info">
            <strong><?= h((string) ($row['staff_label'] ?? '-')) ?></strong>
            <span><?= h((string) (($row['wilayah'] ?? '') ?: '-')) ?> - <?= h((string) (($row['unit_name'] ?? '') ?: '-')) ?></span>
          </div>
          <div class="leader-metrics">
            <b><?= h(number_format((float) ($row['points'] ?? 0), 0, ',', '.')) ?> poin</b>
            <small><?= h(number_format((float) ($row['closing_for_points'] ?? 0), 0, ',', '.')) ?> closing - <?= h((string) ($row['closing_points_source'] ?? 'RSM fallback')) ?></small>
            <small><?= h(number_format((float) ($row['herreg_for_points'] ?? 0), 0, ',', '.')) ?> herreg - <?= h((string) ($row['herreg_points_source'] ?? 'RSM fallback')) ?></small>
          </div>
          <div class="badge-row compact">
            <?php foreach (array_slice(($row['badges'] ?? []), 0, 2) as $badge): ?>
              <b class="game-badge badge-tone-<?= h((string) ($badge['tone'] ?? 'slate')) ?>"><?= h((string) ($badge['label'] ?? 'Badge')) ?></b>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

function render_activity_table(array $rows, string $role): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Wilayah</th><th>Unit/Kampus</th><th>Staff</th><th>Jenis</th><th>Nama kegiatan</th><th>Leads</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Belum ada data kegiatan marketing di database.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) $row['wilayah']) ?></td><td><?= h((string) $row['unit_name']) ?></td><td><?= h((string) $row['staff_name']) ?></td><td><?= h((string) ($row['activity_kind'] ?: '-')) ?></td><td><?= h((string) $row['title']) ?></td><td><?= h((string) $row['leads_count']) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_ads_table(array $rows, string $role): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Platform</th><th>Campaign</th><th>Anggaran</th><th>Realisasi</th><th>Leads</th><th>Closing</th><th>CPL</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="10" class="empty-row">Belum ada laporan anggaran iklan di database.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) ($row['platform'] ?: '-')) ?></td><td><?= h((string) ($row['campaign_name'] ?: $row['title'])) ?></td><td><?= h(money_idr((float) $row['budget_requested'])) ?></td><td><?= h(money_idr((float) $row['realization_amount'])) ?></td><td><?= h((string) $row['leads_count']) ?></td><td><?= h((string) ($row['closing_count'] ?? 0)) ?></td><td><?= h(money_idr((float) $row['cpl'])) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_ad_leads_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Nama Lead</th><th>No. HP/WA</th><th>Email</th><th>Kampus</th><th>Jurusan</th><th>Kota Asal</th><th>Follow Up</th><th>Status Progress</th><th>Status Closing</th><th>Update Closing</th><th>Catatan</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="empty-row">Belum ada data hasil iklan yang diupload untuk laporan ini.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h(display_or_empty($row['lead_name'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['whatsapp'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['email'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['campus_name'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['major_name'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['origin_city'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['follow_up_result'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['progress_status'] ?? '')) ?></td>
          <td><?= closing_status_form((int) $row['id'], (string) ($row['closing_status'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['closing_update'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['notes'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function closing_status_form(int $leadId, string $current): string
{
    $options = ['Belum closing', 'Potensi closing', 'Closing', 'Herregistrasi', 'Tidak closing'];
    $html = '<form method="post" class="compact-status-form">'
        . '<input type="hidden" name="action" value="update_lead_closing_status">'
        . '<input type="hidden" name="lead_id" value="' . h((string) $leadId) . '">'
        . '<select name="closing_status" onchange="this.form.submit()">';
    foreach ($options as $option) {
        $selected = strtolower($current) === strtolower($option) ? ' selected' : '';
        $html .= '<option' . $selected . '>' . h($option) . '</option>';
    }
    return $html . '</select></form>';
}

function display_or_empty(mixed $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : 'belum diisi';
}

function render_other_table(array $rows, string $role): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Kategori</th><th>Staff</th><th>Hasil</th><th>Tindak lanjut</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">Belum ada aktivitas lain di database.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) ($row['category'] ?: '-')) ?></td><td><?= h((string) $row['staff_name']) ?></td><td><?= h((string) ($row['result_text'] ?: '-')) ?></td><td><?= h((string) ($row['follow_up_text'] ?: '-')) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_users_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Nama</th><th>NIK</th><th>Username</th><th>Role</th><th>Jabatan</th><th>Regional</th><th>Area</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Belum ada user RSM.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) $row['name']) ?></td>
          <td><?= h(display_or_empty($row['nik'] ?? '')) ?></td>
          <td><?= h((string) $row['username']) ?></td>
          <td><?= h((string) $row['role']) ?></td>
          <td><?= h((string) $row['jabatan']) ?></td>
          <td><?= h(display_or_empty($row['regional'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['area'] ?? '')) ?></td>
          <td><?= ((int) $row['is_active'] === 1) ? badge('Aktif') : badge('Nonaktif') ?></td>
          <td>
            <div class="icon-actions">
              <button class="icon-action" type="button" title="Edit user" aria-label="Edit user" onclick="document.getElementById('edit-user-<?= h((string) $row['id']) ?>').classList.toggle('is-open')">&#9998;</button>
              <form method="post" class="inline-action" title="Reset password user">
                <input type="hidden" name="action" value="admin_reset_user_password">
                <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
                <input class="icon-password" name="new_password" placeholder="Password baru" required minlength="6" title="Isi password baru">
                <button class="icon-action" title="Reset password" aria-label="Reset password">&#8635;</button>
              </form>
              <form method="post" class="inline-action" title="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>">
              <input type="hidden" name="action" value="admin_set_user_active">
              <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
              <input type="hidden" name="is_active" value="<?= (int) $row['is_active'] === 1 ? '0' : '1' ?>">
                <button class="icon-action <?= (int) $row['is_active'] === 1 ? 'danger' : '' ?>" title="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>" aria-label="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>"><?= (int) $row['is_active'] === 1 ? '&#9211;' : '&#10003;' ?></button>
              </form>
              <form method="post" class="inline-action" onsubmit="return confirm('Hapus user ini?')" title="Hapus user">
                <input type="hidden" name="action" value="admin_delete_user">
                <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
                <button class="icon-action danger" title="Hapus user" aria-label="Hapus user">&times;</button>
              </form>
            </div>
          </td>
        </tr>
        <tr id="edit-user-<?= h((string) $row['id']) ?>" class="edit-user-row">
          <td colspan="9">
            <form method="post" class="edit-user-form">
              <input type="hidden" name="action" value="admin_update_user">
              <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
              <input name="name" value="<?= h((string) $row['name']) ?>" required title="Nama">
              <input name="nik" value="<?= h((string) ($row['nik'] ?? '')) ?>" placeholder="NIK" title="NIK">
              <input name="username" value="<?= h((string) $row['username']) ?>" required title="Username">
              <select name="user_role" title="Role">
                <?php foreach (['staff' => 'Staff Unit', 'koordinator' => 'Koordinator Wilayah', 'senior' => 'Senior Manager'] as $key => $label): ?>
                  <option value="<?= h($key) ?>" <?= (string) $row['role'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="jabatan" value="<?= h((string) $row['jabatan']) ?>" required title="Jabatan">
              <select name="regional" title="Regional">
                <option value="">Regional</option>
                <?php foreach (['Regional 1', 'Regional 2', 'Regional 3', 'Regional 4', 'Regional 5', 'Regional 6', 'Regional 7'] as $regionalOption): ?>
                  <option <?= (string) ($row['regional'] ?? '') === $regionalOption ? 'selected' : '' ?>><?= h($regionalOption) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="area" title="Area">
                <option value="">Area</option>
                <option <?= (string) ($row['area'] ?? '') === 'Regional A' ? 'selected' : '' ?>>Regional A</option>
                <option <?= (string) ($row['area'] ?? '') === 'Regional B' ? 'selected' : '' ?>>Regional B</option>
              </select>
              <input name="campus_name" value="<?= h((string) ($row['campus_name'] ?? '')) ?>" placeholder="Kampus/Unit" title="Kampus/Unit">
              <button class="icon-action" title="Simpan perubahan" aria-label="Simpan perubahan">&#10003;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function action_buttons(string $role, int $id, string $status): string
{
    $buttons = '<a class="text-btn" href="' . h(url_for('detail', $role) . '&id=' . $id) . '">Detail</a>';
    if (can_edit_report($role, $status)) {
        $buttons .= '<a class="text-btn" href="' . h(url_for('edit', $role) . '&id=' . $id) . '">Edit</a>';
        $buttons .= delete_form($id);
    }
    if (can_action($role, 'verifikasi')) {
        $buttons .= status_form($id, 'Diverifikasi', 'Verifikasi') . status_form($id, 'Revisi', 'Revisi', true);
    }
    if (can_action($role, 'setujui')) {
        $buttons .= status_form($id, 'Disetujui', 'Setujui') . status_form($id, 'Ditolak', 'Tolak', false, 'danger') . status_form($id, 'Revisi', 'Revisi', true);
    }
    return $buttons;
}

function can_edit_report(string $role, string $status): bool
{
    return can_action($role, 'edit-draft') && in_array(strtolower($status), ['draft', 'revisi'], true);
}

function delete_form(int $id): string
{
    return '<form method="post" class="inline-action" onsubmit="return confirm(\'Hapus laporan ini?\')">'
        . '<input type="hidden" name="action" value="delete_report">'
        . '<input type="hidden" name="report_id" value="' . h((string) $id) . '">'
        . '<button class="text-btn danger">Hapus</button>'
        . '</form>';
}

function report_page_for(string $type): string
{
    return [
        'marketing' => 'kegiatan',
        'ads' => 'anggaran',
        'other' => 'aktivitas',
    ][$type] ?? 'dashboard';
}

function detail_fields(array $report): array
{
    return [
        'ID Laporan' => (string) ($report['id'] ?? ''),
        'Area' => (string) ($report['area'] ?? ''),
        'Jenis Laporan' => (string) ($report['report_type'] ?? ''),
        'Tanggal' => (string) ($report['report_date'] ?? ''),
        'Wilayah' => (string) ($report['wilayah'] ?? ''),
        'Unit/Kampus' => (string) ($report['unit_name'] ?? ''),
        'Nama Staff' => (string) ($report['staff_name'] ?? ''),
        'Status' => (string) ($report['status'] ?? ''),
        'Jenis/Kategori' => (string) (($report['activity_kind'] ?? '') ?: ($report['category'] ?? '') ?: ($report['platform'] ?? '')),
        'Lokasi' => (string) ($report['location_name'] ?? ''),
        'Target' => (string) ($report['target_text'] ?? ''),
        'Hasil' => (string) ($report['result_text'] ?? ''),
        'Jumlah Leads' => (string) ($report['leads_count'] ?? '0'),
        'Jumlah Closing' => (string) ($report['closing_count'] ?? '0'),
        'Platform Iklan' => (string) ($report['platform'] ?? ''),
        'Campaign' => (string) ($report['campaign_name'] ?? ''),
        'Tujuan Iklan' => (string) ($report['ad_goal'] ?? ''),
        'Anggaran Diajukan' => money_idr((float) ($report['budget_requested'] ?? 0)),
        'Anggaran Disetujui' => money_idr((float) ($report['budget_approved'] ?? 0)),
        'Realisasi' => money_idr((float) ($report['realization_amount'] ?? 0)),
        'CPL' => money_idr((float) ($report['cpl'] ?? 0)),
        'Link Campaign' => (string) ($report['campaign_link'] ?? ''),
        'Kendala' => (string) ($report['obstacle_text'] ?? ''),
        'Tindak Lanjut' => (string) ($report['follow_up_text'] ?? ''),
        'Dibuat Oleh' => (string) ($report['created_by_name'] ?? ''),
        'Role Pembuat' => (string) ($report['created_by_role'] ?? ''),
        'Dibuat Pada' => (string) ($report['created_at'] ?? ''),
        'Diubah Pada' => (string) ($report['updated_at'] ?? ''),
    ];
}

function status_form(int $id, string $status, string $label, bool $needsNote = false, string $tone = ''): string
{
    $note = $needsNote ? '<input class="revision-note" name="revision_note" placeholder="Catatan revisi" required>' : '';
    return '<form method="post" class="inline-action">'
        . '<input type="hidden" name="action" value="status_update">'
        . '<input type="hidden" name="report_id" value="' . h((string) $id) . '">'
        . '<input type="hidden" name="new_status" value="' . h($status) . '">'
        . $note
        . '<button class="text-btn ' . h($tone ?: ($needsNote ? 'warning' : '')) . '">' . h($label) . '</button>'
        . '</form>';
}

function role_points(string $role): array
{
    return [
        'senior' => ['Melihat semua wilayah, unit, dan staff', 'Menyetujui atau menolak laporan', 'Melihat seluruh anggaran iklan', 'Export semua laporan', 'Wajib memberi catatan jika revisi'],
        'koordinator' => ['Melihat data wilayahnya saja', 'Memverifikasi laporan staff unit', 'Menambahkan kegiatan wilayah', 'Membuat rekap wilayah', 'Memberi catatan revisi kepada staff'],
        'staff' => ['Menambahkan laporan kegiatan', 'Melihat laporan milik sendiri', 'Edit hanya saat Draft atau Revisi', 'Tidak melihat wilayah lain', 'Tidak bisa menyetujui laporan'],
    ][$role] ?? [];
}
