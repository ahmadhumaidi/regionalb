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
    'mentor' => ['label' => 'Mentor', 'name' => 'Mentor', 'scope' => 'Monitoring dashboard dan target tanpa kelola user'],
    'koordinator' => ['label' => 'Koordinator Wilayah', 'name' => 'Koordinator Wilayah', 'scope' => 'Wilayah yang menjadi tanggung jawab'],
    'staff' => ['label' => 'Staff Unit', 'name' => 'Staff Unit', 'scope' => 'Laporan milik sendiri'],
];
if (!isset($roles[$role])) {
    $role = 'senior';
}

$menus = [
    'dashboard' => 'Dashboard Utama',
    'pencapaian' => 'Pencapaian Staff',
    'bdc-users' => 'BDC Marketing',
    'kegiatan' => 'Kegiatan Marketing',
    'anggaran' => 'Laporan Iklan',
    'aktivitas' => 'Aktivitas Lain',
    'rekap' => 'Laporan & Rekap',
    'role' => 'User & Role',
    'password' => 'Ganti Password',
];
$adminMenus = [
    'targets' => 'Target Bulanan',
    'users' => 'Kelola User',
];
$pageTitles = $menus + $adminMenus + ['profile' => 'Profil Saya', 'detail' => 'Detail Laporan', 'edit' => 'Edit Laporan'];
$pageTitles['closing-kampus'] = 'Top 5 Pencapaian Kampus';
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

function log_actor_label(array $log): string
{
    $actor = (string) (($log['actor_name'] ?? '') ?: '-');
    $viewRole = (string) (($log['actor_view_role'] ?? '') ?: ($log['actor_role'] ?? ''));
    $actualRole = (string) (($log['actor_actual_role'] ?? '') ?: '');
    $label = $actor;
    if ($viewRole !== '') {
        $label .= ' sebagai ' . $viewRole;
    }
    if (!empty($log['impersonator_name'])) {
        $label .= ' | akun asli: ' . (string) $log['impersonator_name'];
    } elseif ($actualRole !== '' && $actualRole !== $viewRole) {
        $label .= ' | role asli: ' . $actualRole;
    }
    return $label;
}

function can_action(string $role, string $action): bool
{
    $rules = [
        'senior' => ['detail', 'setujui', 'tolak', 'revisi', 'export'],
        'mentor' => ['detail', 'setujui', 'tolak', 'revisi', 'export'],
        'koordinator' => ['detail', 'verifikasi', 'revisi', 'export-wilayah'],
        'staff' => ['detail', 'edit-draft', 'hapus-draft'],
    ];
    return in_array($action, $rules[$role] ?? [], true);
}

function allowed_effective_roles(string $actualRole): array
{
    return [
        'senior' => ['senior', 'mentor', 'koordinator', 'staff'],
        'mentor' => ['mentor', 'koordinator', 'staff'],
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
$achievementUsers = [];
$impersonationSource = null;
$impersonationUsers = [];
$bdcReportUsers = [
    'kode' => '',
    'message' => '',
    'source_url' => '',
    'source_mode' => '',
    'fetched_at' => '',
    'listdata' => [],
];
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
$dashboardCampusClosing = [
    'rows' => [],
    'regionals' => [],
    'meta' => [],
    'max_value' => 0.0,
];
$staffAchievement = [
    'rows' => [],
    'regional_summary' => [],
    'totals' => ['staff_count' => 0, 'registrasi' => 0, 'herregistrasi' => 0],
    'sources' => [],
];
$regionalStaffAchievement = $staffAchievement;
$references = [
    'regionals' => [],
    'staff' => [],
    'campuses' => [],
];
$profileData = null;
$monthlyTargets = [];
$dashboardTarget = null;
$achievementRegionalTargets = [];
$syncHealth = [];
$rekapType = in_array((string) ($_GET['rekap_type'] ?? 'all'), ['all', 'marketing', 'ads', 'other', 'staff', 'wilayah', 'unit'], true) ? (string) ($_GET['rekap_type'] ?? 'all') : 'all';
$hasManualRekapDate = isset($_GET['date_from']) || isset($_GET['date_to']);
if ($page === 'rekap' && $rekapType === 'staff' && !$hasManualRekapDate) {
    $dashboardFilters['periode'] = 'monthly';
    $dashboardFilters['date_from'] = date('Y-m-01');
    $dashboardFilters['date_to'] = date('Y-m-d');
    $dashboardFilters['month'] = date('Y-m');
}
$rekapReports = [];
$rekapSummary = [];
$whatsappArtifact = null;

try {
    rsm_ensure_schema();
    $postAction = (string) ($_POST['action'] ?? '');
    if (isset($_GET['logout'])) {
        rsm_logout();
        header('Location: ./');
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $postAction === 'login') {
        rsm_verify_csrf();
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
    if ($page === 'users' && ($authUser['role'] ?? '') !== 'senior') {
        $page = 'dashboard';
    }
    if ($page === 'targets' && !in_array((string) ($authUser['role'] ?? ''), ['senior', 'mentor'], true)) {
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

    $impersonationSource = rsm_impersonation_source();
    $impersonationAdmin = $impersonationSource ?: $authUser;
    if (rsm_can_impersonate($impersonationAdmin)) {
        $impersonationUsers = rsm_users($area);
    }

    $references = rsm_reference_options($area, $authUser, $role);
    $summary = rsm_summary($area, $authUser);
    $activities = rsm_reports($area, 'marketing', 50, $authUser);
    $ads = rsm_reports($area, 'ads', 50, $authUser);
    $otherActivities = rsm_reports($area, 'other', 50, $authUser);
    $logs = rsm_logs($area, 20, $authUser);
    if ($page === 'dashboard' || $page === 'closing-kampus') {
        $campusClosingTotals = rsm_collab_campus_totals($dashboardFilters, $area, $authUser);
        $dashboardCampusClosing['meta'] = is_array($campusClosingTotals['__meta'] ?? null) ? $campusClosingTotals['__meta'] : [];
        unset($campusClosingTotals['__meta']);
        foreach ($campusClosingTotals as $campusRow) {
            if (!is_array($campusRow)) {
                continue;
            }
            $regional = (string) (($campusRow['regional'] ?? '') ?: 'Tanpa Regional');
            $registrasi = (float) ($campusRow['registrasi'] ?? 0);
            $row = [
                'regional' => $regional,
                'unit' => (string) (($campusRow['unit'] ?? '') ?: 'Unit belum diatur'),
                'registrasi' => $registrasi,
            ];
            $dashboardCampusClosing['rows'][] = $row;
            $dashboardCampusClosing['regionals'][$regional][] = $row;
            $dashboardCampusClosing['max_value'] = max((float) $dashboardCampusClosing['max_value'], $registrasi);
        }
        usort($dashboardCampusClosing['rows'], static fn (array $a, array $b): int => ((float) $b['registrasi'] <=> (float) $a['registrasi']) ?: strcmp((string) $a['unit'], (string) $b['unit']));
        foreach ($dashboardCampusClosing['regionals'] as &$regionalRows) {
            usort($regionalRows, static fn (array $a, array $b): int => ((float) $b['registrasi'] <=> (float) $a['registrasi']) ?: strcmp((string) $a['unit'], (string) $b['unit']));
        }
        unset($regionalRows);
    }
    if ($page === 'dashboard') {
        $dashboardOverview = rsm_dashboard_overview($area, $dashboardFilters, $authUser);
        $gamification = rsm_gamification_summary($area, $dashboardFilters, $authUser);
        $staffAchievement = rsm_collab_staff_performance($area, $dashboardFilters, $authUser);
        $regionalStaffAchievement = $staffAchievement;
        if (($authUser['role'] ?? '') === 'staff' && trim((string) ($authUser['regional'] ?? '')) !== '') {
            $regionalPanelFilters = $dashboardFilters;
            $regionalPanelFilters['wilayah'] = (string) $authUser['regional'];
            $regionalPanelFilters['staff_name'] = '';
            $regionalPanelUser = $authUser;
            $regionalPanelUser['role'] = 'koordinator';
            $regionalStaffAchievement = rsm_collab_staff_performance($area, $regionalPanelFilters, $regionalPanelUser);
        }
        $achievementUsers = rsm_users($area);
        $dashboardTarget = rsm_dashboard_target($area, $dashboardFilters, $authUser);
        $syncHealth = rsm_sync_health_status();
    } elseif ($page === 'pencapaian') {
        $staffAchievement = rsm_collab_staff_performance($area, $dashboardFilters, $authUser);
        $dashboardTarget = rsm_dashboard_target($area, $dashboardFilters, $authUser);
        $achievementRegionalTargets = rsm_monthly_target_regional_summary($area, $dashboardFilters, $authUser);
        $syncHealth = rsm_sync_health_status();
    } elseif ($page === 'bdc-users') {
        $bdcReportUsers = rsm_bdc_report_users();
        $syncHealth = rsm_sync_health_status();
    } elseif ($page === 'rekap') {
        $dashboardOverview = rsm_dashboard_overview($area, $dashboardFilters, $authUser);
        $staffAchievement = rsm_collab_staff_performance($area, $dashboardFilters, $authUser);
        $achievementUsers = rsm_users($area);
        $reportType = in_array($rekapType, ['marketing', 'ads', 'other'], true) ? $rekapType : null;
        $rekapReports = rsm_rekap_reports($area, $dashboardFilters, $reportType, 300, $authUser);
        $whatsappArtifact = rsm_latest_achievement_whatsapp_artifact();
    }
    if ($page === 'targets' && in_array((string) ($authUser['role'] ?? ''), ['senior', 'mentor'], true)) {
        $monthlyTargets = rsm_monthly_targets($area);
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
$targetRegistrasi = (float) ($dashboardTarget['target_registrasi'] ?? 0);
$targetHerregistrasi = (float) ($dashboardTarget['target_herregistrasi'] ?? 0);
$targetMonthLabel = $dashboardTarget ? date('F Y', strtotime((string) $dashboardTarget['target_month'] . '-01')) : '';
$targetProgress = $targetRegistrasi > 0 ? rsm_dashboard_percent($displayRegistrasi, $targetRegistrasi) : 0.0;
$targetHerregistrasiProgress = $targetHerregistrasi > 0 ? rsm_dashboard_percent($displayHerregistrasi, $targetHerregistrasi) : 0.0;

$dashboardOverview['kpi']['registrasi'] = $displayRegistrasi;
$dashboardOverview['kpi']['herregistrasi'] = $displayHerregistrasi;
$dashboardOverview['kpi']['conversion_rate'] = $displayConversionRate;
$dashboardOverview['funnel'][2]['value'] = $displayRegistrasi;
$dashboardOverview['funnel'][2]['rate'] = $targetProgress;
$dashboardOverview['funnel'][2]['base_label'] = $targetRegistrasi > 0 ? 'dari target ' . number_format($targetRegistrasi, 0, ',', '.') : 'target belum diatur';
$dashboardOverview['funnel'][3]['value'] = $displayHerregistrasi;
$dashboardOverview['funnel'][3]['rate'] = $targetHerregistrasiProgress;
$dashboardOverview['funnel'][3]['base_label'] = $targetHerregistrasi > 0 ? 'dari target ' . number_format($targetHerregistrasi, 0, ',', '.') : 'target belum diatur';
$dashboardOverview['budget']['cost_per_registrasi'] = $displayCostPerRegistrasi;

$targetCardValue = $dashboardTarget ? number_format($displayRegistrasi, 0, ',', '.') . ' / ' . number_format($targetRegistrasi, 0, ',', '.') : 'Belum diatur';
$targetCardNote = $dashboardTarget
    ? percent_label($targetProgress) . ' target registrasi ' . $targetMonthLabel . ' dari ' . number_format((int) ($dashboardTarget['staff_target_count'] ?? 0), 0, ',', '.') . ' staff'
    : 'Senior Manager belum mengatur target bulan ini';

$summaryCards = [
    ['label' => 'Leads', 'value' => number_format((float) $dashboardOverview['kpi']['leads'], 0, ',', '.'), 'tone' => 'blue', 'note' => 'Target belum diatur'],
    ['label' => 'Follow Up', 'value' => number_format((float) $dashboardOverview['kpi']['follow_up'], 0, ',', '.'), 'tone' => 'cyan', 'note' => 'Dari detail lead yang sudah ditindaklanjuti'],
    ['label' => 'Registrasi', 'value' => number_format($displayRegistrasi, 0, ',', '.'), 'tone' => 'green', 'note' => 'Acuan: ' . $displayRegistrationSource],
    ['label' => 'Herregistrasi', 'value' => number_format($displayHerregistrasi, 0, ',', '.'), 'tone' => 'purple', 'note' => 'Acuan: ' . $displayHerregistrationSource],
    ['label' => 'Conversion Rate', 'value' => percent_label($displayConversionRate), 'tone' => 'amber', 'note' => 'Registrasi dibagi leads'],
    ['label' => 'Target PMB', 'value' => $targetCardValue, 'tone' => 'slate', 'note' => $targetCardNote],
];
$registrationRecap = [
    'leads' => $displayLeads,
    'registrasi' => $displayRegistrasi,
    'target_registrasi' => $targetRegistrasi,
    'target_progress' => $targetProgress,
    'target_staff_count' => (int) ($dashboardTarget['staff_target_count'] ?? 0),
    'registrasi_source' => $displayRegistrationSource,
    'herregistrasi' => $displayHerregistrasi,
    'herregistrasi_source' => $displayHerregistrationSource,
    'conversion_rate' => $displayConversionRate,
    'cost_per_registrasi' => $displayCostPerRegistrasi,
    'top_units' => array_slice($dashboardOverview['ranking'], 0, 3),
];
$topStaffAchievement = array_values(array_filter((array) ($regionalStaffAchievement['rows'] ?? []), static function (array $row): bool {
    return trim((string) ($row['name'] ?? '')) !== '';
}));
usort($topStaffAchievement, static function (array $a, array $b): int {
    return ((float) ($b['registrasi'] ?? 0) <=> (float) ($a['registrasi'] ?? 0))
        ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$topStaffUsersByNik = [];
$topStaffUsersByName = [];
foreach ((array) $achievementUsers as $userRow) {
    if (!is_array($userRow)) {
        continue;
    }
    $nikKey = mb_strtolower(trim((string) ($userRow['nik'] ?? '')));
    $nameKey = mb_strtolower(trim((string) ($userRow['name'] ?? '')));
    if ($nikKey !== '') {
        $topStaffUsersByNik[$nikKey] = $userRow;
    }
    if ($nameKey !== '') {
        $topStaffUsersByName[$nameKey] = $userRow;
    }
}
foreach ($topStaffAchievement as &$topStaffRow) {
    $nikKey = mb_strtolower(trim((string) ($topStaffRow['nik'] ?? '')));
    $nameKey = mb_strtolower(trim((string) ($topStaffRow['name'] ?? '')));
    $matchedUser = ($nikKey !== '' ? ($topStaffUsersByNik[$nikKey] ?? null) : null) ?? ($nameKey !== '' ? ($topStaffUsersByName[$nameKey] ?? null) : null);
    if (is_array($matchedUser)) {
        $topStaffRow['photo_path'] = (string) ($matchedUser['photo_path'] ?? '');
        $topStaffRow['campus_name'] = (string) (($matchedUser['campus_name'] ?? '') ?: ($topStaffRow['campus_name'] ?? ''));
        $topStaffRow['user_role'] = (string) ($matchedUser['role'] ?? '');
    }
}
unset($topStaffRow);
$topStaffAchievement = array_values(array_filter($topStaffAchievement, static function (array $row): bool {
    return !in_array((string) ($row['user_role'] ?? ''), ['senior', 'mentor', 'koordinator'], true);
}));
if (($authUser['role'] ?? '') === 'senior') {
    $topStaffAchievement = array_slice($topStaffAchievement, 0, 5);
}
$topStaffMaxValue = 0.0;
foreach ($topStaffAchievement as $topStaffRow) {
    $topStaffMaxValue = max($topStaffMaxValue, (float) ($topStaffRow['registrasi'] ?? 0));
}
$rekapSummary = build_rekap_summary($rekapReports, $staffAchievement, $dashboardOverview);
if ($page === 'rekap' && $dbError === null) {
    $exportFormat = (string) ($_GET['export'] ?? '');
    if ($exportFormat === 'excel') {
        export_rekap_xlsx($area, $dashboardFilters, $rekapType, $rekapReports, $staffAchievement, $dashboardOverview);
        exit;
    }
    if ($exportFormat === 'pdf') {
        export_rekap_pdf($area, $dashboardFilters, $rekapType, $rekapReports, $staffAchievement, $dashboardOverview);
        exit;
    }
}
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitles[$page]) ?> - <?= h($area) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/style.css')) ?>">
  <?php if ($page === 'profile'): ?><link rel="stylesheet" href="assets/profile.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/profile.css')) ?>"><?php endif; ?>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="mobile-sidebar-head">
      <a class="brand user-brand" href="<?= h(url_for('profile', $role)) ?>">
        <?php
          $sidebarPhoto = trim((string) ($authUser['photo_path'] ?? ''));
          $sidebarName = (string) ($authUser['name'] ?? $roles[$role]['label']);
          $sidebarInitial = strtoupper(substr($sidebarName !== '' ? $sidebarName : 'U', 0, 1));
          $sidebarCampus = (string) (($authUser['campus_name'] ?? '') ?: 'Kampus belum diatur');
          $sidebarJabatan = (string) (($authUser['jabatan'] ?? '') ?: $roles[$role]['label']);
        ?>
        <span class="brand-avatar">
          <?php if ($sidebarPhoto !== ''): ?>
            <img src="<?= h($sidebarPhoto) ?>" alt="Foto profil <?= h($sidebarName) ?>">
          <?php else: ?>
            <?= h($sidebarInitial) ?>
          <?php endif; ?>
        </span>
        <span class="brand-identity">
          <strong><?= h($sidebarName) ?></strong>
          <small><?= h($sidebarCampus) ?></small>
          <small><?= h($sidebarJabatan) ?></small>
        </span>
      </a>
        <button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="sidebar-menu">
          <span></span>
          <span></span>
          <span></span>
          <strong>Menu</strong>
        </button>
      </div>
      <nav class="menu" id="sidebar-menu">
        <?php foreach ($menus as $key => $label): ?>
          <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key, $role)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php foreach ($adminMenus as $key => $label): ?>
          <?php
            $actualNavRole = (string) ($authUser['role'] ?? '');
            if ($key === 'users' && $actualNavRole !== 'senior') {
                continue;
            }
            if ($key === 'targets' && !in_array($actualNavRole, ['senior', 'mentor'], true)) {
                continue;
            }
          ?>
            <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key, $role)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>
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
          <?php if ($impersonationUsers): ?>
            <form method="post" class="role-form impersonate-form">
              <?= rsm_csrf_field() ?>
              <input type="hidden" name="action" value="impersonate_user">
              <label>
                <span>Masuk sebagai</span>
                <select name="target_user_id" onchange="this.form.submit()">
                  <?php foreach ($impersonationUsers as $impersonationUser): ?>
                    <option value="<?= h((string) ($impersonationUser['id'] ?? 0)) ?>" <?= (int) ($impersonationUser['id'] ?? 0) === (int) ($authUser['id'] ?? 0) ? 'selected' : '' ?>>
                      <?= h((string) ($impersonationUser['name'] ?? '-')) ?> - <?= h((string) ($impersonationUser['jabatan'] ?? $impersonationUser['role'] ?? '-')) ?>
                    </option>
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
      <?php if ($impersonationSource): ?>
        <div class="alert impersonation-banner">
          <span>Sedang masuk sebagai <strong><?= h((string) ($authUser['name'] ?? '-')) ?></strong>. Akun asli: <?= h((string) ($impersonationSource['name'] ?? '-')) ?>.</span>
          <form method="post"><?= rsm_csrf_field() ?><input type="hidden" name="action" value="stop_impersonation"><button class="secondary-btn">Kembali ke akun asli</button></form>
        </div>
      <?php endif; ?>
      <?php if ($dbError): ?>
        <div class="alert alert-danger">Database RSM belum tersambung: <?= h($dbError) ?></div>
      <?php endif; ?>

      <?php if ($page === 'dashboard'): ?>
        <form class="filter-bar dashboard-filter dashboard-main-filter campus-page-filter" method="get">
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
                <i style="width: <?= h((string) ($registrationRecap['target_registrasi'] > 0 ? max(3, min(100, $registrationRecap['target_progress'])) : 0)) ?>%"></i>
              </div>
              <?php if ($registrationRecap['target_registrasi'] > 0): ?>
                <p><?= h(percent_label($registrationRecap['target_progress'])) ?> dari target <?= h(number_format($registrationRecap['target_registrasi'], 0, ',', '.')) ?> registrasi<?= $registrationRecap['target_staff_count'] > 0 ? ' (' . h(number_format($registrationRecap['target_staff_count'], 0, ',', '.')) . ' staff)' : '' ?></p>
              <?php else: ?>
                <p>Target registrasi belum diatur untuk periode/filter ini.</p>
              <?php endif; ?>
            </div>
            <div class="registration-stat-list">
              <div><span>Leads Masuk</span><strong><?= h(number_format($registrationRecap['leads'], 0, ',', '.')) ?></strong></div>
              <div><span>Herregistrasi</span><strong><?= h(number_format($registrationRecap['herregistrasi'], 0, ',', '.')) ?></strong><small>Acuan: <?= h($registrationRecap['herregistrasi_source']) ?></small></div>
              <div><span>Cost / Registrasi</span><strong><?= h(money_idr($registrationRecap['cost_per_registrasi'])) ?></strong></div>
            </div>
            <div class="registration-top-units">
              <div class="registration-subhead">
                <strong>Monitoring Anggaran Iklan</strong>
                <span>pengajuan, spend, CPL</span>
              </div>
              <div class="registration-unit-row budget-inline-row">
                <span>Pengajuan</span>
                <strong><?= h(money_idr((float) $dashboardOverview['budget']['requested'])) ?></strong>
              </div>
              <div class="registration-unit-row budget-inline-row">
                <span>Spend</span>
                <strong><?= h(money_idr((float) $dashboardOverview['budget']['spend'])) ?></strong>
              </div>
              <div class="registration-unit-row budget-inline-row">
                <span>Sisa</span>
                <strong><?= h(money_idr((float) $dashboardOverview['budget']['remaining'])) ?></strong>
              </div>
              <div class="registration-unit-row budget-inline-row">
                <span>CPL</span>
                <strong><?= h(money_idr((float) $dashboardOverview['budget']['cpl'])) ?></strong>
              </div>
            </div>
          </div>
        </section>

        <section class="dashboard-grid">
          <article class="panel funnel-panel">
            <div class="panel-head">
              <div><h2>Top 5 Pencapaian Kampus</h2><span>Kampus tertinggi, acuan: Closing Kampus Regional</span></div>
              <a class="link-button" href="<?= h(url_for('closing-kampus', $role)) ?>">Selengkapnya</a>
            </div>
            <div class="campus-closing-list">
              <?php $topCampusClosing = array_slice($dashboardCampusClosing['rows'], 0, 5); ?>
              <?php if ($topCampusClosing === []): ?>
                <p class="muted campus-closing-empty">Belum ada closing kampus pada periode/filter ini.</p>
              <?php else: ?>
                <?php foreach ($topCampusClosing as $campusRow): ?>
                <?php
                  $value = (float) ($campusRow['registrasi'] ?? 0);
                  $rate = (float) $dashboardCampusClosing['max_value'] > 0 ? rsm_dashboard_percent($value, (float) $dashboardCampusClosing['max_value']) : 0.0;
                ?>
                <div class="funnel-row campus-closing-row">
                  <div><strong><?= h((string) $campusRow['unit']) ?></strong><span><?= h((string) $campusRow['regional']) ?></span></div>
                  <b><?= h(number_format($value, 0, ',', '.')) ?></b>
                  <i style="width: <?= h((string) max(3, min(100, $rate))) ?>%"></i>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>
          <article class="panel budget-panel top-staff-panel">
            <div class="panel-head"><h2>Pencapaian Staff Regional</h2><span>Semua staff sesuai regional/filter aktif</span></div>
            <div class="top-staff-list">
              <?php if ($topStaffAchievement === []): ?>
                <p class="muted campus-closing-empty">Belum ada pencapaian staff pada periode/filter ini.</p>
              <?php endif; ?>
              <?php foreach ($topStaffAchievement as $index => $staffRow): ?>
                <?php
                  $staffRegistrasi = (float) ($staffRow['registrasi'] ?? 0);
                  $staffRate = $topStaffMaxValue > 0 ? rsm_dashboard_percent($staffRegistrasi, $topStaffMaxValue) : 0.0;
                  $staffName = (string) (($staffRow['name'] ?? '') ?: '-');
                  $staffPhoto = trim((string) ($staffRow['photo_path'] ?? ''));
                  $staffInitial = strtoupper(substr($staffName !== '' ? $staffName : 'S', 0, 1));
                ?>
                <div class="registration-unit-row top-staff-row">
                  <b>#<?= h((string) ($index + 1)) ?></b>
                  <span class="top-staff-avatar">
                    <?php if ($staffPhoto !== ''): ?>
                      <img src="<?= h($staffPhoto) ?>" alt="Foto <?= h($staffName) ?>">
                    <?php else: ?>
                      <?= h($staffInitial) ?>
                    <?php endif; ?>
                  </span>
                  <span>
                    <strong><?= h($staffName) ?></strong>
                    <small><?= h((string) (($staffRow['regional'] ?? '') ?: '-')) ?> · Closing Collab</small>
                  </span>
                  <strong><?= h(number_format($staffRegistrasi, 0, ',', '.')) ?></strong>
                  <em class="top-staff-progress" style="width: <?= h((string) max(3, min(100, $staffRate))) ?>%"></em>
                </div>
              <?php endforeach; ?>
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
      <?php elseif ($page === 'closing-kampus'): ?>
        <form class="filter-bar dashboard-filter dashboard-main-filter" method="get">
          <input type="hidden" name="page" value="closing-kampus">
          <?php if (count($allowedRoleKeys) > 1): ?>
            <input type="hidden" name="role" value="<?= h($role) ?>">
          <?php endif; ?>
          <label><span>Dari tanggal</span><input type="date" name="date_from" value="<?= h((string) $dashboardFilters['date_from']) ?>"></label>
          <label><span>Sampai tanggal</span><input type="date" name="date_to" value="<?= h((string) $dashboardFilters['date_to']) ?>"></label>
          <label><span>Wilayah</span><select name="wilayah"><option value="">Semua Wilayah</option><?php foreach ($references['regionals'] as $regional): ?><option value="<?= h($regional) ?>" <?= selected_attr((string) $dashboardFilters['wilayah'], $regional) ?>><?= h($regional) ?></option><?php endforeach; ?></select></label>
          <label><span>Unit/Kampus</span><select name="unit_name"><option value="">Semua Unit/Kampus</option><?php foreach ($references['campuses'] as $campus): ?><option value="<?= h((string) $campus['label']) ?>" <?= selected_attr((string) $dashboardFilters['unit_name'], (string) $campus['label']) ?>><?= h((string) $campus['label']) ?></option><?php endforeach; ?></select></label>
          <div class="filter-actions"><button class="primary-btn">Terapkan</button><a class="secondary-btn" href="<?= h(url_for('closing-kampus', $role)) ?>">Reset</a></div>
        </form>
        <section class="panel">
          <div class="panel-head">
            <div><h2>Top 5 Pencapaian Kampus</h2><span>Seluruh kampus per Regional 4, 5, 6, dan 7</span></div>
            <a class="link-button" href="<?= h(url_for('dashboard', $role)) ?>">Kembali ke Dashboard</a>
          </div>
          <div class="campus-closing-list full-list campus-regional-grid">
            <?php foreach (rsm_area_regionals($area) as $regionalName): ?>
              <?php
                $regionalRows = $dashboardCampusClosing['regionals'][$regionalName] ?? [];
                $regionalTotal = array_sum(array_map(static fn (array $row): float => (float) ($row['registrasi'] ?? 0), $regionalRows));
              ?>
              <div class="campus-closing-region">
                <div class="campus-closing-region-head">
                  <strong><?= h($regionalName) ?></strong>
                  <b><?= h(number_format($regionalTotal, 0, ',', '.')) ?> closing</b>
                </div>
                <?php if ($regionalRows === []): ?>
                  <p class="muted campus-closing-empty">Belum ada closing kampus pada periode/filter ini.</p>
                <?php else: ?>
                  <?php foreach ($regionalRows as $campusRow): ?>
                    <?php
                      $value = (float) ($campusRow['registrasi'] ?? 0);
                      $rate = (float) $dashboardCampusClosing['max_value'] > 0 ? rsm_dashboard_percent($value, (float) $dashboardCampusClosing['max_value']) : 0.0;
                    ?>
                    <div class="funnel-row campus-closing-row">
                      <div><strong><?= h((string) $campusRow['unit']) ?></strong><span><?= h($regionalName) ?></span></div>
                      <b><?= h(number_format($value, 0, ',', '.')) ?></b>
                      <i style="width: <?= h((string) max(3, min(100, $rate))) ?>%"></i>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
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
        <?php if (rsm_can_sync_collab(rsm_admin_actor())): ?>
          <form method="post" class="sync-action-bar">
            <?= rsm_csrf_field() ?>
            <input type="hidden" name="action" value="sync_collab_cache">
            <button class="primary-btn">Sinkronisasi Data Pencapaian</button>
          </form>
        <?php endif; ?>
        <?php render_sync_health_panel($syncHealth); ?>

        <?php
          $achievementTargetRegistrasi = (float) ($dashboardTarget['target_registrasi'] ?? 0);
          $achievementTargetHerregistrasi = (float) ($dashboardTarget['target_herregistrasi'] ?? 0);
          $achievementTargetStaff = (int) ($dashboardTarget['staff_target_count'] ?? 0);
          $achievementRegistrasi = (float) ($staffAchievement['totals']['registrasi'] ?? 0);
          $achievementHerregistrasi = (float) ($staffAchievement['totals']['herregistrasi'] ?? 0);
          $achievementRegistrasiLabel = number_format($achievementRegistrasi, 0, ',', '.') . ($achievementTargetRegistrasi > 0 ? ' / ' . number_format($achievementTargetRegistrasi, 0, ',', '.') : '');
          $achievementHerregistrasiLabel = number_format($achievementHerregistrasi, 1, ',', '.') . ($achievementTargetHerregistrasi > 0 ? ' / ' . number_format($achievementTargetHerregistrasi, 0, ',', '.') : '');
          $achievementRegistrasiNote = $achievementTargetRegistrasi > 0
              ? percent_label(rsm_dashboard_percent($achievementRegistrasi, $achievementTargetRegistrasi)) . ' dari target registrasi' . ($achievementTargetStaff > 0 ? ' (' . number_format($achievementTargetStaff, 0, ',', '.') . ' staff)' : '')
              : 'Target registrasi belum diatur';
          $achievementHerregistrasiNote = $achievementTargetHerregistrasi > 0
              ? percent_label(rsm_dashboard_percent($achievementHerregistrasi, $achievementTargetHerregistrasi)) . ' dari target herregistrasi' . ($achievementTargetStaff > 0 ? ' (' . number_format($achievementTargetStaff, 0, ',', '.') . ' staff)' : '')
              : 'Target herregistrasi belum diatur';
        ?>
        <section class="summary-grid four">
          <article class="summary-card tone-green"><span>Total Registrasi</span><strong><?= h($achievementRegistrasiLabel) ?></strong><small><?= h($achievementRegistrasiNote) ?></small></article>
          <article class="summary-card tone-purple"><span>Total Herregistrasi</span><strong><?= h($achievementHerregistrasiLabel) ?></strong><small><?= h($achievementHerregistrasiNote) ?></small></article>
          <article class="summary-card tone-blue"><span>Staff Terbaca</span><strong><?= h(number_format((float) $staffAchievement['totals']['staff_count'], 0, ',', '.')) ?></strong><small>Dalam scope filter</small></article>
          <article class="summary-card tone-slate"><span>Sumber Data</span><strong>Collab</strong><small>Closing & Herreg</small></article>
        </section>

        <section class="panel achievement-panel">
          <div class="panel-head">
            <h2>Pencapaian Registrasi & Herregistrasi Staff</h2>
            <span>Sumber cache Closing Collab dan Herreg Collab sesuai rentang tanggal aktif</span>
          </div>
          <?php render_collab_source_note($staffAchievement['sources'] ?? []); ?>
          <?php render_regional_achievement_summary($staffAchievement['regional_summary'] ?? [], $achievementRegionalTargets); ?>
          <?php render_staff_achievement_table($staffAchievement['rows'] ?? []); ?>
        </section>
      <?php elseif ($page === 'bdc-users'): ?>
        <?php
          $bdcAllowedRegionals = rsm_area_regionals($area);
          $bdcRows = array_values(array_filter((array) ($bdcReportUsers['listdata'] ?? []), static function ($row) use ($bdcAllowedRegionals): bool {
              return is_array($row) && in_array((string) ($row['wilayah'] ?? ''), $bdcAllowedRegionals, true);
          }));
          usort($bdcRows, static fn (array $a, array $b): int => strnatcasecmp((string) ($a['wilayah'] ?? ''), (string) ($b['wilayah'] ?? '')) ?: ((float) ($b['closing'] ?? 0) <=> (float) ($a['closing'] ?? 0)) ?: strnatcasecmp((string) ($a['nama'] ?? ''), (string) ($b['nama'] ?? '')));
          $bdcTotals = [
              'staff' => count($bdcRows),
              'total' => array_sum(array_map(static fn (array $row): float => (float) ($row['total'] ?? 0), $bdcRows)),
              'closing' => array_sum(array_map(static fn (array $row): float => (float) ($row['closing'] ?? 0), $bdcRows)),
              'herreg' => array_sum(array_map(static fn (array $row): float => (float) ($row['herreg'] ?? 0), $bdcRows)),
              'fu_hari_ini' => array_sum(array_map(static fn (array $row): float => (float) ($row['fu_hari_ini'] ?? 0), $bdcRows)),
          ];
        ?>
        <?php render_sync_health_panel($syncHealth); ?>
        <section class="summary-grid four">
          <article class="summary-card tone-blue"><span>Staff Terbaca</span><strong><?= h(number_format($bdcTotals['staff'], 0, ',', '.')) ?></strong><small>Regional B saja</small></article>
          <article class="summary-card tone-cyan"><span>Total Data</span><strong><?= h(number_format($bdcTotals['total'], 0, ',', '.')) ?></strong><small>Regional 4, 5, 6, 7</small></article>
          <article class="summary-card tone-green"><span>Closing</span><strong><?= h(number_format($bdcTotals['closing'], 0, ',', '.')) ?></strong><small>Akumulasi Regional B</small></article>
          <article class="summary-card tone-purple"><span>FU Hari Ini</span><strong><?= h(number_format($bdcTotals['fu_hari_ini'], 0, ',', '.')) ?></strong><small>Follow up hari ini</small></article>
        </section>
        <section class="panel">
          <div class="panel-head">
            <div><h2>BDC Marketing Report Users</h2><span>Regional B only · <?= h((string) (($bdcReportUsers['message'] ?? '') ?: 'Source API P2K')) ?> · <?= h((string) (($bdcReportUsers['source_mode'] ?? '') ?: '-')) ?><?= !empty($bdcReportUsers['fetched_at']) ? ' · ' . h((string) $bdcReportUsers['fetched_at']) . ' WIB' : '' ?></span></div>
          </div>
          <?php if (!empty($bdcReportUsers['source_error'])): ?>
            <div class="alert alert-danger"><?= h((string) $bdcReportUsers['source_error']) ?></div>
          <?php endif; ?>
          <div class="table-wrap bdc-table">
            <table>
              <thead>
                <tr>
                  <th>NIK</th><th>Nama</th><th>Kampus</th><th>Wilayah</th><th>Total</th><th>Data Baru</th><th>Cold</th><th>Warm</th><th>Hot</th><th>Closing</th><th>Wawancara</th><th>Belum Herreg</th><th>Herreg</th><th>FU Hari Ini</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$bdcRows): ?><tr><td colspan="14" class="empty-row">Data API belum terbaca.</td></tr><?php endif; ?>
                <?php foreach ($bdcRows as $row): ?>
                  <tr>
                    <td><?= h((string) (($row['nik'] ?? '') ?: '-')) ?></td>
                    <td><strong><?= h((string) (($row['nama'] ?? '') ?: '-')) ?></strong></td>
                    <td><?= h((string) (($row['kampus'] ?? '') ?: '-')) ?></td>
                    <td><?= h((string) (($row['wilayah'] ?? '') ?: '-')) ?></td>
                    <td><?= h(number_format((float) ($row['total'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= h(number_format((float) ($row['data_baru'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= h(number_format((float) ($row['cold'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= h(number_format((float) ($row['warm'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= h(number_format((float) ($row['hot'] ?? 0), 0, ',', '.')) ?></td>
                    <td><strong><?= h(number_format((float) ($row['closing'] ?? 0), 0, ',', '.')) ?></strong></td>
                    <td><?= h(number_format((float) ($row['wawancara'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= h(number_format((float) ($row['belum_herreg'] ?? 0), 0, ',', '.')) ?></td>
                    <td><strong><?= h(number_format((float) ($row['herreg'] ?? 0), 0, ',', '.')) ?></strong></td>
                    <td><?= h(number_format((float) ($row['fu_hari_ini'] ?? 0), 0, ',', '.')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($page === 'targets'): ?>
        <section class="panel">
          <div class="panel-head"><h2>Target Pencapaian Per Bulan</h2><span>Diatur oleh Senior Manager atau Mentor sebagai acuan dashboard</span></div>
          <form class="data-form" method="post">
            <?= rsm_csrf_field() ?>
            <input type="hidden" name="action" value="save_monthly_target">
            <input type="hidden" name="scope_type" value="staff">
            <label><span>Bulan target</span><input type="month" name="target_month" value="<?= h(date('Y-m')) ?>" required></label>
            <label class="checkbox-line"><input type="checkbox" name="apply_all_scope" value="1"><span>Pilih semua staff</span><small>Target yang sama akan dibuat untuk semua staff dalam Regional B.</small></label>
            <label><span>Staff</span><select name="staff_name"><option value="">Pilih staff</option><?php foreach ($references['staff'] as $staffOption): ?><option value="<?= h((string) $staffOption['name']) ?>"><?= h((string) $staffOption['name']) ?><?= !empty($staffOption['regional']) ? ' - ' . h((string) $staffOption['regional']) : '' ?></option><?php endforeach; ?></select></label>
            <label><span>Target leads</span><input type="number" min="0" name="target_leads" value="0"></label>
            <label><span>Target follow up</span><input type="number" min="0" name="target_follow_up" value="0"></label>
            <label><span>Target registrasi</span><input type="number" min="0" name="target_registrasi" value="0"></label>
            <label><span>Target herregistrasi</span><input type="number" min="0" name="target_herregistrasi" value="0"></label>
            <label><span>Target anggaran</span><input type="number" min="0" step="1000" name="target_anggaran" value="0"></label>
            <label class="form-full"><span>Catatan target</span><textarea name="notes" rows="3" placeholder="Contoh: fokus peningkatan registrasi kelas karyawan dan follow up leads iklan."></textarea></label>
            <div class="form-actions form-full"><button class="primary-btn">Simpan Target</button></div>
          </form>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Daftar Target Bulanan</h2><span>Target terbaru berdasarkan bulan dan scope</span></div>
          <?php render_monthly_targets_table($monthlyTargets); ?>
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
        <?php
          $adsLeadsTotal = array_sum(array_map(static fn (array $row): int => (int) ($row['leads_count'] ?? 0), $ads));
          $adsClosingTotal = array_sum(array_map(static fn (array $row): int => (int) ($row['closing_count'] ?? 0), $ads));
        ?>
        <section class="summary-grid five">
          <article class="summary-card tone-purple"><span>Total anggaran</span><strong><?= h(money_idr((float) $summary['budget_total'])) ?></strong><small>Pengajuan bulan ini</small></article>
          <article class="summary-card tone-green"><span>Total realisasi</span><strong><?= h(money_idr((float) $summary['realization_total'])) ?></strong><small>Pemakaian aktual</small></article>
          <article class="summary-card tone-amber"><span>Sisa anggaran</span><strong><?= h(money_idr((float) $summary['budget_total'] - (float) $summary['realization_total'])) ?></strong><small>Belum digunakan</small></article>
          <article class="summary-card tone-blue"><span>Leads iklan</span><strong><?= h((string) $adsLeadsTotal) ?></strong><small>Semua platform</small></article>
          <article class="summary-card tone-cyan"><span>Closing iklan</span><strong><?= h((string) $adsClosingTotal) ?></strong><small>Dari status closing data hasil iklan</small></article>
        </section>
        <?php render_form_panel('Input Anggaran Iklan', [
            'Tanggal', 'Wilayah', 'Unit/Kampus', 'Platform iklan', 'Nama campaign', 'Tujuan iklan',
            'Anggaran diajukan', 'Anggaran disetujui', 'Realisasi pemakaian',
            'CPL / Cost per Lead', 'Link campaign', 'Upload bukti invoice/screenshot', 'Upload data hasil iklan (.xls/.xlsx)', 'Catatan performa iklan'
        ], ['Meta Ads', 'Google Ads', 'TikTok Ads', 'WhatsApp Blast', 'Marketplace/Portal', 'Lainnya'], ['Pengajuan', 'Disetujui', 'Ditolak', 'Berjalan', 'Selesai', 'Revisi'], 'create_ads', $references, $authUser); ?>
        <section class="panel">
          <div class="panel-head"><h2>Laporan Iklan</h2><button class="primary-btn">Tambah Iklan</button></div>
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
        <section class="panel achievement-report-panel">
          <div class="panel-head">
            <div>
              <h2>Laporan Pencapaian</h2>
              <span>Semua regional, unit yang tampil hanya yang memiliki closing</span>
            </div>
          </div>
          <?php render_achievement_report($area, $staffAchievement, $achievementUsers, $authUser); ?>
          <?php render_whatsapp_artifact_panel($whatsappArtifact); ?>
        </section>
        <section class="panel rekap-export-panel">
          <div class="panel-head">
            <h2>Laporan & Rekap</h2>
            <div class="actions">
              <a class="secondary-btn" href="<?= h(rekap_export_url('excel', $role, $dashboardFilters, $rekapType)) ?>">Export Excel</a>
              <a class="secondary-btn" href="<?= h(rekap_export_url('pdf', $role, $dashboardFilters, $rekapType)) ?>">Export PDF</a>
            </div>
          </div>
          <form class="filter-bar rekap-filter" method="get">
            <input type="hidden" name="page" value="rekap">
            <?php if (count($allowedRoleKeys) > 1): ?>
              <input type="hidden" name="role" value="<?= h($role) ?>">
            <?php endif; ?>
            <label><span>Periode</span><select name="periode"><option value="custom"<?= selected_attr((string) ($dashboardFilters['periode'] ?? ''), 'custom') ?>>Custom tanggal</option><option value="daily"<?= selected_attr((string) ($dashboardFilters['periode'] ?? ''), 'daily') ?>>Harian</option><option value="weekly"<?= selected_attr((string) ($dashboardFilters['periode'] ?? ''), 'weekly') ?>>Mingguan</option><option value="monthly"<?= selected_attr((string) ($dashboardFilters['periode'] ?? ''), 'monthly') ?>>Bulanan</option></select></label>
            <label><span>Dari tanggal</span><input type="date" name="date_from" value="<?= h((string) $dashboardFilters['date_from']) ?>"></label>
            <label><span>Sampai tanggal</span><input type="date" name="date_to" value="<?= h((string) $dashboardFilters['date_to']) ?>"></label>
            <label><span>Jenis laporan</span><select name="rekap_type">
              <?php foreach (rekap_type_options() as $value => $label): ?>
                <option value="<?= h($value) ?>"<?= selected_attr($rekapType, $value) ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select></label>
            <div class="filter-actions"><button class="primary-btn">Terapkan</button><a class="secondary-btn" href="<?= h(url_for('rekap', $role)) ?>">Reset</a></div>
          </form>
          <?php render_rekap_cards($rekapSummary, $role, $dashboardFilters, $rekapType); ?>
          <?php render_rekap_preview($rekapType, $rekapReports, $staffAchievement, $dashboardOverview, $dashboardFilters, $role); ?>
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
              <?php if (!empty($detailReport['attachment_path'])): ?>
                <div class="detail-item">
                  <span>Bukti invoice/screenshot</span>
                  <strong><a class="attachment-link" href="<?= h((string) $detailReport['attachment_path']) ?>" target="_blank" rel="noopener">Lihat bukti</a></strong>
                </div>
              <?php endif; ?>
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
                <div class="actions">
                  <button class="primary-btn" type="submit" form="ad-leads-update-form">Simpan Perubahan</button>
                  <a class="secondary-btn" href="download-ad-lead-template.php">Download Template .xlsx</a>
                </div>
              </div>
              <form class="inline-upload" method="post" enctype="multipart/form-data">
                <?= rsm_csrf_field() ?>
                <input type="hidden" name="action" value="upload_ad_leads">
                <input type="hidden" name="report_id" value="<?= h((string) $detailReport['id']) ?>">
                <input type="file" name="ad_leads_file" accept=".xls,.xlsx" required>
                <button class="primary-btn">Upload / Ganti Data</button>
              </form>
              <?php render_ad_leads_table($detailAdLeads, (int) $detailReport['id']); ?>
            </section>
          <?php endif; ?>

          <section class="panel">
            <div class="panel-head"><h2>Riwayat Status</h2><span>Log aktivitas laporan</span></div>
            <div class="log-list">
              <?php if (!$detailLogs): ?>
                <div><strong>Belum ada log</strong><span>Riwayat akan muncul setelah laporan dibuat atau status berubah.</span></div>
              <?php endif; ?>
              <?php foreach ($detailLogs as $log): ?>
                <div><strong><?= h((string) $log['created_at']) ?></strong><span><?= h(log_actor_label($log)) ?> - <?= h((string) $log['action_name']) ?> <?= h((string) ($log['old_status'] ?? '')) ?> <?= $log['new_status'] ? '-> ' . h((string) $log['new_status']) : '' ?></span></div>
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
            <?= rsm_csrf_field() ?>
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
            <?= rsm_csrf_field() ?>
            <input type="hidden" name="action" value="admin_create_user">
            <label><span>Nama</span><input name="name" required></label>
            <label><span>NIK</span><input name="nik" placeholder="Opsional"></label>
            <label><span>Username</span><input name="username" required></label>
            <label><span>Role</span><select name="user_role"><option value="staff">Staff Unit</option><option value="koordinator">Koordinator Wilayah</option><option value="mentor">Mentor</option><option value="senior">Senior Manager</option></select></label>
            <label><span>Area</span><select name="area" class="js-area-select"><option <?= $area === 'Regional A' ? 'selected' : '' ?>>Regional A</option><option <?= $area === 'Regional B' ? 'selected' : '' ?>>Regional B</option></select></label>
            <label><span>Regional</span><select name="regional" class="js-regional-select"><option value="">Pilih regional</option><?php foreach (['Regional 1', 'Regional 2', 'Regional 3', 'Regional 4', 'Regional 5', 'Regional 6', 'Regional 7'] as $regionalOption): $optionArea = in_array($regionalOption, ['Regional 1', 'Regional 2', 'Regional 3'], true) ? 'Regional A' : 'Regional B'; ?><option data-area="<?= h($optionArea) ?>"><?= h($regionalOption) ?></option><?php endforeach; ?></select></label>
            <label><span>Kampus/Unit</span><select name="campus_name"><option value="">Pilih kampus/unit</option><?php foreach ($references['campuses'] as $campusOption): $campusLabel = (string) ($campusOption['label'] ?? ''); if ($campusLabel === '') { continue; } ?><option value="<?= h($campusLabel) ?>"><?= h($campusLabel) ?><?= !empty($campusOption['regional']) ? ' - ' . h((string) $campusOption['regional']) : '' ?></option><?php endforeach; ?></select></label>
            <label><span>Password awal</span><input name="new_user_password" value="kptsukses" required></label>
            <div class="form-actions"><button class="primary-btn">Tambah User</button></div>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Daftar User RSM</h2><span>Password asli tidak bisa ditampilkan karena disimpan aman sebagai hash</span></div>
          <?php render_users_table($managedUsers, $references); ?>
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
              <div><strong><?= h((string) $log['created_at']) ?></strong><span><?= h(log_actor_label($log)) ?> - <?= h((string) $log['action_name']) ?> <?= h((string) ($log['new_status'] ?? '')) ?></span></div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>
  <script src="assets/app.js?v=<?= h((string) @filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
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
      <link rel="stylesheet" href="assets/style.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/style.css')) ?>">
    </head>
    <body class="login-body">
      <main class="login-card">
        <p class="eyebrow">Regional Senior Manager</p>
        <h1>Masuk Dashboard</h1>
        <p>Gunakan username dari NIK tanpa titik dan password yang diberikan admin.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="login-form">
          <?= rsm_csrf_field() ?>
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

function render_sync_health_panel(array $health): void
{
    if ($health === []) {
        return;
    }
    $collab = $health['collab'] ?? [];
    $bdc = $health['bdc'] ?? [];
    $collabErrors = (array) ($collab['errors'] ?? []);
    ?>
    <section class="sync-health-panel">
      <div>
        <span>Collab</span>
        <strong><?= h((string) ($collab['status'] ?? '-')) ?></strong>
        <small><?= h((string) (($collab['synced_at'] ?? '') ?: 'Belum sinkron')) ?><?= $collabErrors ? ' - ada error source' : '' ?></small>
      </div>
      <div>
        <span>BDC Marketing</span>
        <strong><?= h((string) ($bdc['status'] ?? '-')) ?></strong>
        <small><?= h((string) (($bdc['synced_at'] ?? '') ?: 'Belum sinkron')) ?><?= !empty($bdc['rows_count']) ? ' - ' . h(number_format((int) $bdc['rows_count'], 0, ',', '.')) . ' snapshot' : '' ?></small>
      </div>
    </section>
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
          <span><?= h((string) (($user['phone_number'] ?? '') ?: 'No. HP belum terisi')) ?></span>
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
          <article><b>LG</b><div><span>League</span><strong><?= h((string) ($profile['league'] ?? 'Starter')) ?></strong><small>XP, closing, konsistensi</small></div></article>
          <article><b>IN</b><div><span>In company</span><strong><?= h((string) (($user['work_duration'] ?? '') ?: ($profile['joined_label'] ?? '-'))) ?></strong><small>Acuan Closing Collab</small></div></article>
          <article><b>AU</b><div><span>Aura</span><strong><?= h((string) ($profile['status_performa'] ?? 'Belum ada data')) ?></strong><small>Skor <?= h((string) $score) ?>/100</small></div></article>
        </section>

        <section class="profile-hero-card">
          <div class="profile-radial" style="--value: <?= h((string) $circle) ?>">
            <div><span>Level</span><strong><?= h((string) ($level['number'] ?? 1)) ?></strong><small><?= h(number_format($xp, 0, ',', '.')) ?> XP</small></div>
          </div>
          <div class="profile-hero-copy">
            <span class="eyebrow">Profil User</span>
            <h2><?= h((string) ($user['name'] ?? '-')) ?></h2>
            <p><?= h((string) (($user['bio_text'] ?? '') ?: 'Biodata singkat belum diisi.')) ?></p>
            <div class="profile-core-metrics">
              <div><span>Aktivitas</span><strong><?= h(number_format((int) ($stats['total_reports'] ?? 0), 0, ',', '.')) ?></strong></div>
              <div><span>Konsistensi</span><strong><?= h(number_format((int) (($profile['streak']['current'] ?? 0)), 0, ',', '.')) ?> hari</strong></div>
              <div><span>Konversi</span><strong><?= h(percent_label(rsm_dashboard_percent((float) ($stats['closing_total'] ?? 0), (float) ($stats['leads_total'] ?? 0)))) ?></strong></div>
            </div>
            <div class="profile-identity-tags">
              <span><?= h((string) ($user['jabatan'] ?? '-')) ?></span>
              <span><?= h((string) (($user['campus_name'] ?? '') ?: 'Unit belum diatur')) ?></span>
              <span><?= h((string) (($user['regional'] ?? '') ?: 'Wilayah belum diatur')) ?></span>
              <span><?= h((string) (($user['phone_number'] ?? '') ?: 'No. HP belum terisi')) ?></span>
            </div>
          </div>
          <div class="profile-hero-portrait">
            <?php if ($photoPath !== ''): ?>
              <img src="<?= h($photoPath) ?>" alt="Foto profil <?= h((string) ($user['name'] ?? 'User')) ?>">
            <?php else: ?>
              <span><?= h($initial) ?></span>
            <?php endif; ?>
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
                  <div><strong><?= h((string) ($kpi['label'] ?? '-')) ?></strong><small><?= h((string) (($kpi['note'] ?? '') ?: 'Target belum diatur')) ?></small></div>
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
              <?= rsm_csrf_field() ?>
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
    $showRegionalBAggregate = $action === 'create_ads' && !is_staff_user($authUser);
    ?>
    <section class="panel form-panel">
      <div class="panel-head">
        <h2><?= h($title) ?></h2>
        <span>Form input operasional</span>
      </div>
      <form class="data-form" method="post" enctype="multipart/form-data">
        <?= rsm_csrf_field() ?>
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
                <?php if ($showRegionalBAggregate): ?>
                  <option value="Regional B">Regional B</option>
                <?php endif; ?>
                <?php foreach ($references['regionals'] as $regionalOption): ?>
                  <option value="<?= h((string) $regionalOption) ?>"><?= h((string) $regionalOption) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($field === 'Unit/Kampus'): ?>
              <select name="<?= h($name) ?>" required>
                <option value="">Pilih unit/kampus</option>
                <?php if ($showRegionalBAggregate): ?>
                  <option value="Regional B">Regional B</option>
                <?php endif; ?>
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
                <input type="file" name="<?= h($name) ?>" accept="image/jpeg,image/png,image/webp,application/pdf">
                <small class="field-hint">Format JPG, PNG, WEBP, atau PDF. Maksimal 5 MB.</small>
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
        <?= rsm_csrf_field() ?>
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
        ?><input type="file" name="<?= h($name) ?>" accept="image/jpeg,image/png,image/webp,application/pdf"><?php if (!empty($report[$name])): ?><small class="field-hint">Bukti saat ini: <a href="<?= h((string) $report[$name]) ?>" target="_blank" rel="noopener">Lihat bukti</a>. Kosongkan jika tidak ingin mengganti.</small><?php else: ?><small class="field-hint">Format JPG, PNG, WEBP, atau PDF. Maksimal 5 MB.</small><?php endif; ?><?php
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
        return ['Tanggal', 'Wilayah', 'Unit/Kampus', 'Platform iklan', 'Nama campaign', 'Tujuan iklan', 'Anggaran diajukan', 'Anggaran disetujui', 'Realisasi pemakaian', 'CPL / Cost per Lead', 'Link campaign', 'Upload bukti invoice/screenshot', 'Upload data hasil iklan (.xls/.xlsx)', 'Catatan performa iklan'];
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
    $registrasiTimeLabel = str_starts_with($registrasiMode, 'cache') ? 'Terakhir sinkron' : (str_starts_with($registrasiMode, 'live_url') ? 'Jam baca' : 'Jam snapshot');
    $herregistrasiTimeLabel = str_starts_with($herregistrasiMode, 'cache') ? 'Terakhir sinkron' : (str_starts_with($herregistrasiMode, 'live_url') ? 'Jam baca' : 'Jam snapshot');
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

function render_regional_achievement_summary(array $rows, array $targets = []): void
{
    ?>
    <div class="regional-achievement-grid">
      <?php if (!$rows): ?><div class="empty-game">Belum ada data dari source Collab pada filter ini.</div><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php
          $regional = (string) ($row['regional'] ?? '-');
          $target = $targets[$regional] ?? [];
          $registrasi = (float) ($row['registrasi'] ?? 0);
          $herregistrasi = (float) ($row['herregistrasi'] ?? 0);
          $targetRegistrasi = (float) ($target['target_registrasi'] ?? 0);
          $targetHerregistrasi = (float) ($target['target_herregistrasi'] ?? 0);
          $registrasiText = number_format($registrasi, 0, ',', '.') . ($targetRegistrasi > 0 ? ' / ' . number_format($targetRegistrasi, 0, ',', '.') : '');
          $herregistrasiText = number_format($herregistrasi, 1, ',', '.') . ($targetHerregistrasi > 0 ? ' / ' . number_format($targetHerregistrasi, 0, ',', '.') : '');
        ?>
        <article>
          <span><?= h($regional) ?></span>
          <strong><?= h($registrasiText) ?> registrasi</strong>
          <small><?= h($herregistrasiText) ?> herregistrasi</small>
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

function render_rekap_staff_achievement_table(array $rows): void
{
    $groups = [];
    foreach ($rows as $row) {
        $regional = (string) (($row['regional'] ?? '') ?: 'Regional belum diatur');
        if (!isset($groups[$regional])) {
            $groups[$regional] = [
                'registrasi' => 0.0,
                'herregistrasi' => 0.0,
                'staff_count' => 0,
                'rows' => [],
            ];
        }
        $groups[$regional]['registrasi'] += (float) ($row['registrasi'] ?? 0);
        $groups[$regional]['herregistrasi'] += (float) ($row['herregistrasi'] ?? 0);
        $groups[$regional]['staff_count']++;
        $groups[$regional]['rows'][] = $row;
    }
    ksort($groups);
    ?>
    <div class="table-wrap achievement-table rekap-staff-table"><table><thead><tr><th>Regional</th><th>NIK</th><th>Staff</th><th>Registrasi</th><th>Herregistrasi</th></tr></thead><tbody>
      <?php if (!$groups): ?><tr><td colspan="5" class="empty-row">Belum ada data staff pada periode/filter ini.</td></tr><?php endif; ?>
      <?php foreach ($groups as $regional => $group): ?>
        <tr class="rekap-regional-heading">
          <td colspan="3"><strong><?= h($regional) ?></strong><span><?= h(number_format((int) ($group['staff_count'] ?? 0), 0, ',', '.')) ?> staff</span></td>
          <td><strong><?= h(number_format((float) ($group['registrasi'] ?? 0), 0, ',', '.')) ?></strong></td>
          <td><strong><?= h(number_format((float) ($group['herregistrasi'] ?? 0), 1, ',', '.')) ?></strong></td>
        </tr>
        <?php usort($group['rows'], static fn (array $a, array $b): int => ((float) ($b['registrasi'] ?? 0) <=> (float) ($a['registrasi'] ?? 0)) ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))); ?>
        <?php foreach ($group['rows'] as $row): ?>
          <tr>
            <td><?= h($regional) ?></td>
            <td><?= h((string) (($row['nik'] ?? '') ?: '-')) ?></td>
            <td><?= h((string) (($row['name'] ?? '') ?: '-')) ?></td>
            <td><strong><?= h(number_format((float) ($row['registrasi'] ?? 0), 0, ',', '.')) ?></strong></td>
            <td><strong><?= h(number_format((float) ($row['herregistrasi'] ?? 0), 1, ',', '.')) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_achievement_report(string $area, array $achievement, array $users, ?array $authUser = null): void
{
    $actualRole = (string) ($authUser['role'] ?? '');
    $authRegional = trim((string) ($authUser['regional'] ?? ''));
    $authName = mb_strtolower(trim((string) ($authUser['name'] ?? '')));
    $authNik = mb_strtolower(trim((string) ($authUser['nik'] ?? '')));
    $scopedRegionals = rsm_area_regionals($area);
    if (in_array($actualRole, ['koordinator', 'staff'], true) && $authRegional !== '') {
        $scopedRegionals = [$authRegional];
    }
    $scopedRegionalLookup = array_fill_keys(array_map(static fn (string $value): string => mb_strtolower($value), $scopedRegionals), true);

    $usersByName = [];
    $usersByNik = [];
    $senior = null;
    $coordinators = [];
    foreach ($users as $user) {
        if ((int) ($user['is_active'] ?? 1) !== 1) {
            continue;
        }
        $role = (string) ($user['role'] ?? '');
        $userRegional = trim((string) ($user['regional'] ?? ''));
        if (in_array($role, ['koordinator', 'staff'], true) && $scopedRegionalLookup !== [] && $userRegional !== '' && !isset($scopedRegionalLookup[mb_strtolower($userRegional)])) {
            continue;
        }
        if ($actualRole === 'staff') {
            $userName = mb_strtolower(trim((string) ($user['name'] ?? '')));
            $userNik = mb_strtolower(trim((string) ($user['nik'] ?? '')));
            if ($role !== 'senior' && $role !== 'mentor' && $userName !== $authName && ($authNik === '' || $userNik !== $authNik)) {
                continue;
            }
        }
        $nameKey = mb_strtolower(trim((string) ($user['name'] ?? '')));
        $nikKey = mb_strtolower(trim((string) ($user['nik'] ?? '')));
        if ($nameKey !== '') {
            $usersByName[$nameKey] = $user;
        }
        if ($nikKey !== '') {
            $usersByNik[$nikKey] = $user;
        }
        if ($role === 'senior' && !$senior) {
            $senior = $user;
        }
        if ($role === 'koordinator') {
            $regional = (string) (($user['regional'] ?? '') ?: 'Tanpa Regional');
            $coordinators[$regional][] = $user;
        }
    }

    $regionalUnits = [];
    foreach (($achievement['rows'] ?? []) as $row) {
        $registrasi = (float) ($row['registrasi'] ?? 0);
        if ($registrasi <= 0) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $nik = trim((string) ($row['nik'] ?? ''));
        if ($actualRole === 'staff') {
            $rowName = mb_strtolower($name);
            $rowNik = mb_strtolower($nik);
            if ($rowName !== $authName && ($authNik === '' || $rowNik !== $authNik)) {
                continue;
            }
        }
        $staffUser = $usersByNik[mb_strtolower($nik)] ?? $usersByName[mb_strtolower($name)] ?? [];
        $regional = (string) (($row['regional'] ?? '') ?: ($staffUser['regional'] ?? 'Tanpa Regional'));
        if ($scopedRegionalLookup !== [] && !isset($scopedRegionalLookup[mb_strtolower($regional)])) {
            continue;
        }
        $unit = (string) (($staffUser['campus_name'] ?? '') ?: 'Unit belum diatur');
        if (!isset($regionalUnits[$regional][$unit])) {
            $regionalUnits[$regional][$unit] = [
                'unit' => $unit,
                'registrasi' => 0.0,
                'herregistrasi' => 0.0,
                'staff' => [],
            ];
        }
        $regionalUnits[$regional][$unit]['registrasi'] += $registrasi;
        $regionalUnits[$regional][$unit]['herregistrasi'] += (float) ($row['herregistrasi'] ?? 0);
        $regionalUnits[$regional][$unit]['staff'][] = [
            'name' => $name,
            'nik' => $nik,
            'photo_path' => (string) ($staffUser['photo_path'] ?? ''),
            'registrasi' => $registrasi,
            'herregistrasi' => (float) ($row['herregistrasi'] ?? 0),
        ];
    }

    $regionals = $scopedRegionals;
    if ($regionals === []) {
        $regionals = array_keys($regionalUnits);
    }
    $visibleTotalRegistrasi = 0.0;
    foreach ($regionalUnits as $regionalUnitGroups) {
        foreach ($regionalUnitGroups as $unitGroup) {
            $visibleTotalRegistrasi += (float) ($unitGroup['registrasi'] ?? 0);
        }
    }
    $leader = $senior ?: ['name' => 'Senior Manager', 'jabatan' => 'Senior Manager'];
    $leaderLabel = 'Senior Manager';
    if ($actualRole === 'koordinator') {
        $leader = $authUser ?: $leader;
        $leaderLabel = 'Korwil';
    } elseif ($actualRole === 'staff') {
        $leader = $authUser ?: $leader;
        $leaderLabel = 'Staff';
    }
    ?>
    <div class="achievement-report">
      <article class="achievement-leader">
        <?php render_achievement_person($leader, $leaderLabel, $visibleTotalRegistrasi, true); ?>
      </article>
      <div class="achievement-regional-grid">
        <?php for ($columnIndex = 0; $columnIndex < 2; $columnIndex++): ?>
        <div class="achievement-regional-column">
        <?php foreach ($regionals as $regionalIndex => $regional): ?>
          <?php if ($regionalIndex % 2 !== $columnIndex) { continue; } ?>
          <?php
            $units = array_values($regionalUnits[$regional] ?? []);
            usort($units, static fn (array $a, array $b): int => ((float) $b['registrasi'] <=> (float) $a['registrasi']) ?: strcmp((string) $a['unit'], (string) $b['unit']));
            $regionalRegistrasi = array_sum(array_map(static fn (array $unit): float => (float) $unit['registrasi'], $units));
          ?>
          <article class="achievement-regional-card <?= $units ? '' : 'no-closing' ?> regional-tone-<?= h(str_replace(' ', '-', strtolower($regional))) ?>" data-regional="<?= h($regional) ?>" data-closing="<?= h((string) $regionalRegistrasi) ?>">
            <div class="achievement-regional-head">
              <div class="achievement-regional-title">
                <span><?= h($regional) ?></span>
                <strong><?= h(number_format($regionalRegistrasi, 0, ',', '.')) ?> closing</strong>
              </div>
              <div class="achievement-korwil-list">
                <?php foreach (($coordinators[$regional] ?? []) as $coordinator): ?>
                  <?php render_achievement_person($coordinator, 'Korwil', $regionalRegistrasi, false); ?>
                <?php endforeach; ?>
                <?php if (empty($coordinators[$regional])): ?>
                  <?php render_achievement_person(['name' => 'Korwil belum diatur', 'photo_path' => ''], 'Korwil', $regionalRegistrasi, false); ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="achievement-unit-list">
              <?php if (!$units): ?>
                <p class="muted">Belum ada unit closing pada periode ini.</p>
              <?php endif; ?>
              <?php foreach ($units as $unit): ?>
                <div class="achievement-unit-card" data-unit="<?= h((string) $unit['unit']) ?>" data-closing="<?= h((string) $unit['registrasi']) ?>">
                  <div class="achievement-unit-title">
                    <strong><?= h((string) $unit['unit']) ?></strong>
                    <span><?= h(number_format((float) $unit['registrasi'], 0, ',', '.')) ?> closing</span>
                  </div>
                  <div class="achievement-staff-list">
                    <?php foreach ($unit['staff'] as $staff): ?>
                      <?php render_achievement_person($staff, 'Staff', (float) $staff['registrasi'], false); ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php
}

function render_achievement_person(array $person, string $label, float $closing, bool $large = false): void
{
    $name = (string) (($person['name'] ?? '') ?: '-');
    $photo = trim((string) ($person['photo_path'] ?? ''));
    $initial = strtoupper(substr($name !== '' ? $name : 'U', 0, 1));
    ?>
    <div class="achievement-person <?= $large ? 'large' : '' ?>" data-person-role="<?= h($label) ?>" data-person-name="<?= h($name) ?>" data-closing="<?= h((string) $closing) ?>">
      <span class="achievement-avatar">
        <?php if ($photo !== ''): ?>
          <img src="<?= h($photo) ?>" alt="Foto <?= h($name) ?>">
        <?php else: ?>
          <?= h($initial) ?>
        <?php endif; ?>
      </span>
      <div>
        <small><?= h($label) ?></small>
        <strong><?= h($name) ?></strong>
        <em><?= h(number_format($closing, 0, ',', '.')) ?> closing</em>
      </div>
    </div>
    <?php
}

function render_whatsapp_artifact_panel(?array $artifact): void
{
    $text = trim((string) ($artifact['text'] ?? ''));
    $generatedAt = (string) ($artifact['generated_at'] ?? '');
    $imageFile = (string) ($artifact['image_file'] ?? '');
    $imageError = (string) ($artifact['image_error'] ?? '');
    $period = is_array($artifact['period'] ?? null) ? $artifact['period'] : [];
    ?>
    <div class="whatsapp-safe-panel">
      <div class="whatsapp-safe-head">
        <div>
          <h3>Bahan WhatsApp Otomatis</h3>
          <span>Generate terjadwal, admin tetap copy/send manual.</span>
        </div>
        <?php if (rsm_can_sync_collab(rsm_admin_actor())): ?>
          <form method="post">
            <?= rsm_csrf_field() ?>
            <input type="hidden" name="action" value="generate_whatsapp_achievement">
            <button class="primary-btn" type="submit">Generate Sekarang</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$artifact): ?>
        <p class="muted">Belum ada bahan otomatis. Klik Generate Sekarang, atau tunggu jadwal server berikutnya.</p>
      <?php else: ?>
        <div class="whatsapp-safe-meta">
          <span>Periode: <?= h(rsm_format_date_id((string) ($period['date_from'] ?? ''))) ?> s/d <?= h(rsm_format_date_id((string) ($period['date_to'] ?? ''))) ?></span>
          <span>Jam generate: <?= h($generatedAt) ?></span>
          <?php if ($imageFile !== ''): ?><a href="<?= h($imageFile) ?>" target="_blank" rel="noopener">Buka gambar</a><?php endif; ?>
          <?php if ($imageError !== ''): ?><span>Gambar: <?= h($imageError) ?></span><?php endif; ?>
        </div>
        <textarea class="whatsapp-safe-text" readonly><?= h($text) ?></textarea>
        <div class="achievement-report-actions">
          <?php if ($imageFile !== ''): ?>
            <a class="primary-btn" href="<?= h($imageFile) ?>" download>Download Gambar</a>
          <?php endif; ?>
          <button class="secondary-btn whatsapp-safe-copy" type="button">Copy Text Otomatis</button>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

function rekap_type_options(): array
{
    return [
        'all' => 'Semua laporan',
        'marketing' => 'Rekap kegiatan marketing',
        'ads' => 'Rekap laporan iklan',
        'other' => 'Rekap aktivitas lain',
        'staff' => 'Rekap performa staff',
        'wilayah' => 'Rekap performa wilayah',
        'unit' => 'Rekap performa unit/kampus',
    ];
}

function rekap_export_url(string $format, string $role, array $filters, string $rekapType): string
{
    $params = [
        'page' => 'rekap',
        'role' => $role,
        'periode' => (string) ($filters['periode'] ?? ''),
        'date_from' => (string) ($filters['date_from'] ?? ''),
        'date_to' => (string) ($filters['date_to'] ?? ''),
        'wilayah' => (string) ($filters['wilayah'] ?? ''),
        'unit_name' => (string) ($filters['unit_name'] ?? ''),
        'staff_name' => (string) ($filters['staff_name'] ?? ''),
        'platform' => (string) ($filters['platform'] ?? ''),
        'status' => (string) ($filters['status'] ?? ''),
        'rekap_type' => $rekapType,
        'export' => $format,
    ];
    $params = array_filter($params, static fn (string $value): bool => $value !== '');
    return '?' . http_build_query($params);
}

function build_rekap_summary(array $reports, array $achievement, array $overview): array
{
    $summary = [
        'all' => ['count' => count($reports)],
        'marketing' => ['count' => 0, 'leads' => 0],
        'ads' => ['count' => 0, 'requested' => 0.0, 'realization' => 0.0, 'leads' => 0, 'closing' => 0],
        'other' => ['count' => 0],
        'staff' => [
            'staff_count' => (float) ($achievement['totals']['staff_count'] ?? 0),
            'registrasi' => (float) ($achievement['totals']['registrasi'] ?? 0),
            'herregistrasi' => (float) ($achievement['totals']['herregistrasi'] ?? 0),
        ],
        'wilayah' => [
            'count' => count((array) ($achievement['regional_summary'] ?? [])),
            'registrasi' => (float) ($achievement['totals']['registrasi'] ?? 0),
            'herregistrasi' => (float) ($achievement['totals']['herregistrasi'] ?? 0),
        ],
        'unit' => [
            'count' => count((array) ($overview['ranking'] ?? [])),
            'top' => (string) (($overview['ranking'][0]['unit_label'] ?? '') ?: '-'),
            'registrasi' => (float) (($overview['ranking'][0]['registrasi_total'] ?? 0) ?: 0),
        ],
    ];

    foreach ($reports as $report) {
        $type = (string) ($report['report_type'] ?? '');
        if (!isset($summary[$type])) {
            continue;
        }
        $summary[$type]['count']++;
        if ($type === 'marketing') {
            $summary[$type]['leads'] += (int) ($report['leads_count'] ?? 0);
        } elseif ($type === 'ads') {
            $summary[$type]['requested'] += (float) ($report['budget_requested'] ?? 0);
            $summary[$type]['realization'] += (float) ($report['realization_amount'] ?? 0);
            $summary[$type]['leads'] += (int) ($report['leads_count'] ?? 0);
            $summary[$type]['closing'] += (int) ($report['closing_count'] ?? 0);
        }
    }
    $summary['ads']['cpl'] = rsm_dashboard_divide((float) $summary['ads']['realization'], (float) $summary['ads']['leads']);

    return $summary;
}

function render_rekap_cards(array $summary, string $role, array $filters, string $activeType): void
{
    $cards = [
        'marketing' => ['title' => 'Rekap kegiatan marketing', 'value' => number_format((float) ($summary['marketing']['count'] ?? 0), 0, ',', '.') . ' laporan', 'meta' => number_format((float) ($summary['marketing']['leads'] ?? 0), 0, ',', '.') . ' leads'],
        'ads' => ['title' => 'Rekap laporan iklan', 'value' => money_idr((float) ($summary['ads']['realization'] ?? 0)), 'meta' => number_format((float) ($summary['ads']['count'] ?? 0), 0, ',', '.') . ' laporan | CPL ' . money_idr((float) ($summary['ads']['cpl'] ?? 0))],
        'other' => ['title' => 'Rekap aktivitas lain', 'value' => number_format((float) ($summary['other']['count'] ?? 0), 0, ',', '.') . ' laporan', 'meta' => 'Aktivitas non-marketing'],
        'staff' => ['title' => 'Rekap performa staff', 'value' => number_format((float) ($summary['staff']['registrasi'] ?? 0), 0, ',', '.') . ' registrasi', 'meta' => number_format((float) ($summary['staff']['staff_count'] ?? 0), 0, ',', '.') . ' staff terbaca'],
        'wilayah' => ['title' => 'Rekap performa wilayah', 'value' => number_format((float) ($summary['wilayah']['registrasi'] ?? 0), 0, ',', '.') . ' registrasi', 'meta' => number_format((float) ($summary['wilayah']['count'] ?? 0), 0, ',', '.') . ' wilayah'],
        'unit' => ['title' => 'Rekap performa unit/kampus', 'value' => (string) ($summary['unit']['top'] ?? '-'), 'meta' => number_format((float) ($summary['unit']['registrasi'] ?? 0), 0, ',', '.') . ' registrasi top unit'],
    ];
    ?>
    <div class="report-grid rekap-card-grid">
      <?php foreach ($cards as $type => $card): ?>
        <a class="report-card rekap-card <?= $activeType === $type ? 'active' : '' ?>" href="<?= h(rekap_export_url('', $role, $filters, $type)) ?>">
          <strong><?= h($card['title']) ?></strong>
          <b><?= h($card['value']) ?></b>
          <span><?= h($card['meta']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
}

function render_rekap_preview(string $type, array $reports, array $achievement, array $overview, array $filters = [], string $role = 'staff'): void
{
    $periodLabel = trim((string) (($filters['date_from'] ?? '') . ' s/d ' . ($filters['date_to'] ?? '')));
    ?>
    <div class="rekap-preview" id="rekap-preview">
      <div class="panel-head">
        <h3><?= h(rekap_type_options()[$type] ?? 'Semua laporan') ?></h3>
        <span><?= $periodLabel !== 's/d' ? 'History ' . h($periodLabel) : 'Preview mengikuti filter aktif' ?></span>
      </div>
      <?php if ($type === 'staff'): ?>
        <?php render_rekap_staff_achievement_table((array) ($achievement['rows'] ?? [])); ?>
      <?php elseif ($type === 'wilayah'): ?>
        <?php render_regional_achievement_summary((array) ($achievement['regional_summary'] ?? [])); ?>
      <?php elseif ($type === 'unit'): ?>
        <?php render_ranking_table((array) ($overview['ranking'] ?? [])); ?>
      <?php elseif ($type === 'ads'): ?>
        <?php render_ads_table($reports, $role); ?>
      <?php else: ?>
        <?php render_rekap_reports_table($reports); ?>
      <?php endif; ?>
    </div>
    <?php
}

function rekap_export_rows(string $area, array $filters, string $type, array $reports, array $achievement, array $overview): array
{
    $rows = [
        ['Area', $area],
        ['Periode', (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? '')],
        ['Jenis Rekap', rekap_type_options()[$type] ?? 'Semua laporan'],
        [],
    ];

    if ($type === 'staff') {
        $rows[] = ['Regional', 'NIK', 'Staff', 'Registrasi', 'Herregistrasi'];
        $groups = [];
        foreach ((array) ($achievement['rows'] ?? []) as $row) {
            $regional = (string) (($row['regional'] ?? '') ?: 'Regional belum diatur');
            $groups[$regional]['registrasi'] = (float) ($groups[$regional]['registrasi'] ?? 0) + (float) ($row['registrasi'] ?? 0);
            $groups[$regional]['herregistrasi'] = (float) ($groups[$regional]['herregistrasi'] ?? 0) + (float) ($row['herregistrasi'] ?? 0);
            $groups[$regional]['rows'][] = $row;
        }
        ksort($groups);
        foreach ($groups as $regional => $group) {
            $rows[] = [$regional, '', 'TOTAL REGIONAL', (float) ($group['registrasi'] ?? 0), (float) ($group['herregistrasi'] ?? 0)];
            foreach ((array) ($group['rows'] ?? []) as $row) {
                $rows[] = [$regional, (string) ($row['nik'] ?? ''), (string) ($row['name'] ?? ''), (float) ($row['registrasi'] ?? 0), (float) ($row['herregistrasi'] ?? 0)];
            }
        }
        return $rows;
    }

    if ($type === 'wilayah') {
        $rows[] = ['Regional', 'Registrasi', 'Herregistrasi'];
        foreach ((array) ($achievement['regional_summary'] ?? []) as $row) {
            $rows[] = [(string) ($row['regional'] ?? ''), (float) ($row['registrasi'] ?? 0), (float) ($row['herregistrasi'] ?? 0)];
        }
        return $rows;
    }

    if ($type === 'unit') {
        $rows[] = ['Unit/Kampus', 'Leads', 'Registrasi', 'Herregistrasi', 'Conversion', 'Spend', 'CPL', 'Cost/Registrasi'];
        foreach ((array) ($overview['ranking'] ?? []) as $row) {
            $rows[] = [
                (string) ($row['unit_label'] ?? ''),
                (float) ($row['leads_total'] ?? 0),
                (float) ($row['registrasi_total'] ?? 0),
                (float) ($row['herregistrasi_total'] ?? 0),
                percent_label((float) ($row['conversion_rate'] ?? 0)),
                (float) ($row['spend_total'] ?? 0),
                (float) ($row['cpl'] ?? 0),
                (float) ($row['cost_per_registrasi'] ?? 0),
            ];
        }
        return $rows;
    }

    if ($type === 'ads') {
        $rows[] = ['Tanggal', 'Regional', 'Unit/Kampus', 'Platform', 'Campaign', 'Anggaran', 'Realisasi', 'Leads', 'Closing', 'CPL', 'Status'];
        foreach (ads_grouped_reports($reports) as $regionalGroup) {
            $regionalTotals = $regionalGroup['totals'] ?? [];
            $rows[] = [
                'REGIONAL: ' . (string) ($regionalGroup['label'] ?? '-'),
                '',
                (int) ($regionalTotals['count'] ?? 0) . ' laporan',
                '',
                '',
                (float) ($regionalTotals['budget_requested'] ?? 0),
                (float) ($regionalTotals['realization_amount'] ?? 0),
                (float) ($regionalTotals['leads_count'] ?? 0),
                (float) ($regionalTotals['closing_count'] ?? 0),
                rsm_dashboard_divide((float) ($regionalTotals['realization_amount'] ?? 0), (float) ($regionalTotals['leads_count'] ?? 0)),
                '',
            ];
            foreach ((array) ($regionalGroup['campuses'] ?? []) as $campusGroup) {
                $campusTotals = $campusGroup['totals'] ?? [];
                $rows[] = [
                    'Nama Kampus: ' . (string) ($campusGroup['label'] ?? '-'),
                    '',
                    (int) ($campusTotals['count'] ?? 0) . ' laporan',
                    '',
                    '',
                    (float) ($campusTotals['budget_requested'] ?? 0),
                    (float) ($campusTotals['realization_amount'] ?? 0),
                    (float) ($campusTotals['leads_count'] ?? 0),
                    (float) ($campusTotals['closing_count'] ?? 0),
                    rsm_dashboard_divide((float) ($campusTotals['realization_amount'] ?? 0), (float) ($campusTotals['leads_count'] ?? 0)),
                    '',
                ];
                foreach ((array) ($campusGroup['rows'] ?? []) as $row) {
                    $rows[] = [
                        (string) ($row['report_date'] ?? ''),
                        (string) (($row['wilayah'] ?? '') ?: '-'),
                        (string) (($row['unit_name'] ?? '') ?: '-'),
                        (string) (($row['platform'] ?? '') ?: '-'),
                        (string) (($row['campaign_name'] ?? '') ?: ($row['title'] ?? '-')),
                        (float) ($row['budget_requested'] ?? 0),
                        (float) ($row['realization_amount'] ?? 0),
                        (float) ($row['leads_count'] ?? 0),
                        (float) ($row['closing_count'] ?? 0),
                        (float) ($row['cpl'] ?? 0),
                        (string) (($row['status'] ?? '') ?: '-'),
                    ];
                }
            }
        }
        return $rows;
    }

    $rows[] = ['Tanggal', 'Jenis', 'Wilayah', 'Unit/Kampus', 'Staff', 'Judul/Campaign', 'Leads', 'Closing', 'Anggaran', 'Realisasi', 'Status'];
    foreach ($reports as $row) {
        $rows[] = [
            (string) ($row['report_date'] ?? ''),
            report_type_label((string) ($row['report_type'] ?? '')),
            (string) ($row['wilayah'] ?? ''),
            (string) ($row['unit_name'] ?? ''),
            (string) ($row['staff_name'] ?? ''),
            (string) (($row['campaign_name'] ?? '') ?: ($row['title'] ?? '')),
            (float) ($row['leads_count'] ?? 0),
            (float) ($row['closing_count'] ?? 0),
            (float) ($row['budget_requested'] ?? 0),
            (float) ($row['realization_amount'] ?? 0),
            (string) ($row['status'] ?? ''),
        ];
    }
    return $rows;
}

function export_rekap_xlsx(string $area, array $filters, string $type, array $reports, array $achievement, array $overview): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Export XLSX membutuhkan ekstensi PHP ZipArchive di server.');
    }
    $filename = 'rekap-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($area)) . '-' . date('Ymd-His') . '.xlsx';
    export_rows_xlsx(rekap_export_rows($area, $filters, $type, $reports, $achievement, $overview), $filename);
}

function export_rows_xlsx(array $rows, string $filename): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'rsm-xlsx-');
    if ($tmp === false) {
        throw new RuntimeException('Gagal membuat file sementara export.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Gagal membuat arsip XLSX.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rekap" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsx_sheet_xml($rows));
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}

function xlsx_sheet_xml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $r = $rowIndex + 1;
        $xml .= '<row r="' . $r . '">';
        foreach (array_values((array) $row) as $colIndex => $value) {
            $cell = xlsx_col_name($colIndex + 1) . $r;
            $style = $rowIndex === 0 || ($rowIndex >= 4 && $rowIndex <= 4) ? ' s="1"' : '';
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $cell . '"' . $style . '><v>' . (0 + $value) . '</v></c>';
            } else {
                $xml .= '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t>' . h((string) $value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function xlsx_col_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function export_rekap_pdf(string $area, array $filters, string $type, array $reports, array $achievement, array $overview): void
{
    $rows = rekap_export_rows($area, $filters, $type, $reports, $achievement, $overview);
    $filename = 'rekap-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($area)) . '-' . date('Ymd-His') . '.pdf';
    $lines = [];
    foreach ($rows as $row) {
        $line = trim(implode(' | ', array_map(static function ($value): string {
            if (is_float($value) || is_int($value)) {
                return number_format((float) $value, 0, ',', '.');
            }
            return (string) $value;
        }, (array) $row)));
        $lines[] = $line;
    }
    export_text_pdf($lines, $filename);
}

function export_text_pdf(array $lines, string $filename): void
{
    $pages = array_chunk($lines, 38);
    if ($pages === []) {
        $pages = [[]];
    }
    $objects = [];
    $catalogId = 1;
    $pagesId = 2;
    $fontId = 3;
    $nextId = 4;
    $pageIds = [];
    $contentIds = [];
    foreach ($pages as $pageLines) {
        $pageIds[] = $nextId++;
        $contentIds[] = $nextId++;
    }
    $objects[$catalogId] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . count($pageIds) . ' >>';

    foreach ($pages as $pageIndex => $pageLines) {
        $content = "BT\n/F1 10 Tf\n50 800 Td\n";
        foreach ($pageLines as $line) {
            foreach (str_split($line, 120) as $part) {
                $content .= '(' . pdf_escape($part) . ") Tj\n0 -16 Td\n";
            }
        }
        $content .= "ET\n";
        $contentId = $contentIds[$pageIndex];
        $pageId = $pageIds[$pageIndex];
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[$pageId] = '<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
    }

    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string) ($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . ' /Root ' . $catalogId . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
}

function pdf_escape(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function render_rekap_reports_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Jenis</th><th>Wilayah</th><th>Unit/Kampus</th><th>Staff</th><th>Judul/Campaign</th><th>Leads</th><th>Closing</th><th>Anggaran</th><th>Realisasi</th><th>Status</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="empty-row">Belum ada data pada filter ini.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) ($row['report_date'] ?? '-')) ?></td>
          <td><?= h(report_type_label((string) ($row['report_type'] ?? ''))) ?></td>
          <td><?= h((string) (($row['wilayah'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['unit_name'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['staff_name'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['campaign_name'] ?? '') ?: ($row['title'] ?? '-'))) ?></td>
          <td><?= h(number_format((float) ($row['leads_count'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((float) ($row['closing_count'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(money_idr((float) ($row['budget_requested'] ?? 0))) ?></td>
          <td><?= h(money_idr((float) ($row['realization_amount'] ?? 0))) ?></td>
          <td><?= badge((string) ($row['status'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function export_rekap_csv(string $area, array $filters, string $type, array $reports, array $achievement, array $overview): void
{
    if ($type === 'ads') {
        export_ads_excel($area, $filters, $reports);
        return;
    }

    $filename = 'rekap-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($area)) . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Area', $area]);
    fputcsv($out, ['Periode', (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? '')]);
    fputcsv($out, ['Jenis Rekap', rekap_type_options()[$type] ?? 'Semua laporan']);
    fputcsv($out, []);

    if ($type === 'staff') {
        fputcsv($out, ['Regional', 'NIK', 'Staff', 'Registrasi', 'Herregistrasi']);
        $groups = [];
        foreach ((array) ($achievement['rows'] ?? []) as $row) {
            $regional = (string) (($row['regional'] ?? '') ?: 'Regional belum diatur');
            $groups[$regional]['registrasi'] = (float) ($groups[$regional]['registrasi'] ?? 0) + (float) ($row['registrasi'] ?? 0);
            $groups[$regional]['herregistrasi'] = (float) ($groups[$regional]['herregistrasi'] ?? 0) + (float) ($row['herregistrasi'] ?? 0);
            $groups[$regional]['rows'][] = $row;
        }
        ksort($groups);
        foreach ($groups as $regional => $group) {
            fputcsv($out, [$regional, '', 'TOTAL REGIONAL', (float) ($group['registrasi'] ?? 0), (float) ($group['herregistrasi'] ?? 0)]);
            foreach ((array) ($group['rows'] ?? []) as $row) {
                fputcsv($out, [$regional, (string) ($row['nik'] ?? ''), (string) ($row['name'] ?? ''), (float) ($row['registrasi'] ?? 0), (float) ($row['herregistrasi'] ?? 0)]);
            }
        }
    } elseif ($type === 'wilayah') {
        fputcsv($out, ['Regional', 'Registrasi', 'Herregistrasi']);
        foreach ((array) ($achievement['regional_summary'] ?? []) as $row) {
            fputcsv($out, [(string) ($row['regional'] ?? ''), (float) ($row['registrasi'] ?? 0), (float) ($row['herregistrasi'] ?? 0)]);
        }
    } elseif ($type === 'unit') {
        fputcsv($out, ['Unit/Kampus', 'Leads', 'Registrasi', 'Herregistrasi', 'Conversion', 'Spend', 'CPL', 'Cost/Registrasi']);
        foreach ((array) ($overview['ranking'] ?? []) as $row) {
            fputcsv($out, [(string) ($row['unit_label'] ?? ''), (float) ($row['leads_total'] ?? 0), (float) ($row['registrasi_total'] ?? 0), (float) ($row['herregistrasi_total'] ?? 0), percent_label((float) ($row['conversion_rate'] ?? 0)), (float) ($row['spend_total'] ?? 0), (float) ($row['cpl'] ?? 0), (float) ($row['cost_per_registrasi'] ?? 0)]);
        }
    } else {
        fputcsv($out, ['Tanggal', 'Jenis', 'Wilayah', 'Unit/Kampus', 'Staff', 'Judul/Campaign', 'Leads', 'Closing', 'Anggaran', 'Realisasi', 'Status']);
        foreach ($reports as $row) {
            fputcsv($out, [(string) ($row['report_date'] ?? ''), report_type_label((string) ($row['report_type'] ?? '')), (string) ($row['wilayah'] ?? ''), (string) ($row['unit_name'] ?? ''), (string) ($row['staff_name'] ?? ''), (string) (($row['campaign_name'] ?? '') ?: ($row['title'] ?? '')), (float) ($row['leads_count'] ?? 0), (float) ($row['closing_count'] ?? 0), (float) ($row['budget_requested'] ?? 0), (float) ($row['realization_amount'] ?? 0), (string) ($row['status'] ?? '')]);
        }
    }
    fclose($out);
}

function export_ads_excel(string $area, array $filters, array $reports): void
{
    $filename = 'rekap-laporan-iklan-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($area)) . '-' . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $groups = ads_grouped_reports($reports);
    echo "\xEF\xBB\xBF";
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }
        th, td { border: 1px solid #9ca3af; padding: 7px 8px; vertical-align: top; }
        th { background: #e5e7eb; font-weight: bold; text-align: left; }
        .meta td { border: 0; font-weight: bold; }
        .regional td { background: #2f3d8f; color: #fff; font-weight: bold; }
        .campus td { background: #dff0ff; font-weight: bold; }
        .num { text-align: right; mso-number-format:"\#\,\#\#0"; }
        .money { text-align: right; mso-number-format:"Rp \#\,\#\#0"; }
      </style>
    </head>
    <body>
      <table class="meta">
        <tr><td>Area</td><td><?= h($area) ?></td></tr>
        <tr><td>Periode</td><td><?= h((string) ($filters['date_from'] ?? '')) ?> s/d <?= h((string) ($filters['date_to'] ?? '')) ?></td></tr>
        <tr><td>Jenis Rekap</td><td>Rekap laporan iklan</td></tr>
      </table>
      <br>
      <table>
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Regional</th>
            <th>Unit/Kampus</th>
            <th>Platform</th>
            <th>Campaign</th>
            <th>Anggaran</th>
            <th>Realisasi</th>
            <th>Leads</th>
            <th>Closing</th>
            <th>CPL</th>
            <th>Bukti</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$reports): ?>
            <tr><td colspan="12">Belum ada laporan iklan pada filter ini.</td></tr>
          <?php endif; ?>
          <?php foreach ($groups as $regionalGroup): ?>
            <?php $regionalTotals = ads_group_total_cells($regionalGroup['totals']); ?>
            <tr class="regional">
              <td colspan="5">Regional: <?= h((string) $regionalGroup['label']) ?> - <?= h(number_format((int) $regionalGroup['totals']['count'], 0, ',', '.')) ?> laporan</td>
              <td class="money"><?= h($regionalTotals['anggaran']) ?></td>
              <td class="money"><?= h($regionalTotals['realisasi']) ?></td>
              <td class="num"><?= h($regionalTotals['leads']) ?></td>
              <td class="num"><?= h($regionalTotals['closing']) ?></td>
              <td class="money"><?= h($regionalTotals['cpl']) ?></td>
              <td colspan="2"></td>
            </tr>
            <?php foreach ($regionalGroup['campuses'] as $campusGroup): ?>
              <?php $campusTotals = ads_group_total_cells($campusGroup['totals']); ?>
              <tr class="campus">
              <td colspan="5">Nama Kampus: <?= h((string) $campusGroup['label']) ?> - <?= h(number_format((int) $campusGroup['totals']['count'], 0, ',', '.')) ?> laporan</td>
                <td class="money"><?= h($campusTotals['anggaran']) ?></td>
                <td class="money"><?= h($campusTotals['realisasi']) ?></td>
                <td class="num"><?= h($campusTotals['leads']) ?></td>
                <td class="num"><?= h($campusTotals['closing']) ?></td>
                <td class="money"><?= h($campusTotals['cpl']) ?></td>
                <td colspan="2"></td>
              </tr>
              <?php foreach ($campusGroup['rows'] as $row): ?>
                <tr>
                  <td><?= h((string) ($row['report_date'] ?? '')) ?></td>
                  <td><?= h((string) (($row['wilayah'] ?? '') ?: '-')) ?></td>
                  <td><?= h((string) (($row['unit_name'] ?? '') ?: '-')) ?></td>
                  <td><?= h((string) (($row['platform'] ?? '') ?: '-')) ?></td>
                  <td><?= h((string) (($row['campaign_name'] ?? '') ?: ($row['title'] ?? '-'))) ?></td>
                  <td class="money"><?= h(money_idr((float) ($row['budget_requested'] ?? 0))) ?></td>
                  <td class="money"><?= h(money_idr((float) ($row['realization_amount'] ?? 0))) ?></td>
                  <td class="num"><?= h(number_format((int) ($row['leads_count'] ?? 0), 0, ',', '.')) ?></td>
                  <td class="num"><?= h(number_format((int) ($row['closing_count'] ?? 0), 0, ',', '.')) ?></td>
                  <td class="money"><?= h(money_idr((float) ($row['cpl'] ?? 0))) ?></td>
                  <td><?= h((string) (($row['attachment_path'] ?? '') ?: '-')) ?></td>
                  <td><?= h((string) (($row['status'] ?? '') ?: '-')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </body>
    </html>
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
          <span class="leader-avatar">
            <?php
              $leaderPhoto = trim((string) ($row['photo_path'] ?? ''));
              $leaderName = (string) (($row['staff_label'] ?? '') ?: 'Staff');
              $leaderInitial = strtoupper(substr($leaderName !== '' ? $leaderName : 'S', 0, 1));
            ?>
            <?php if ($leaderPhoto !== ''): ?>
              <img src="<?= h($leaderPhoto) ?>" alt="Foto <?= h($leaderName) ?>">
            <?php else: ?>
              <?= h($leaderInitial) ?>
            <?php endif; ?>
          </span>
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
        <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) $row['wilayah']) ?></td><td><?= h((string) $row['unit_name']) ?></td><td><?= h((string) $row['staff_name']) ?></td><td><?= h((string) ($row['activity_kind'] ?: '-')) ?></td><td><?= h((string) $row['title']) ?></td><td><?= h((string) $row['leads_count']) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status'], (string) ($row['report_type'] ?? 'marketing')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_ads_table(array $rows, string $role): void
{
    $groups = ads_grouped_reports($rows);
    ?>
    <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Unit/Kampus</th><th>Platform</th><th>Campaign</th><th>Anggaran</th><th>Realisasi</th><th>Leads</th><th>Closing</th><th>CPL</th><th>Bukti</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="12" class="empty-row">Belum ada laporan iklan di database.</td></tr><?php endif; ?>
      <?php foreach ($groups as $regionalGroup): ?>
        <?php $regionalTotals = ads_group_total_cells($regionalGroup['totals']); ?>
        <tr class="group-heading">
          <td colspan="5">Regional: <?= h((string) $regionalGroup['label']) ?> <span><?= h(number_format((int) $regionalGroup['totals']['count'], 0, ',', '.')) ?> laporan</span></td>
          <td><?= h($regionalTotals['anggaran']) ?></td>
          <td><?= h($regionalTotals['realisasi']) ?></td>
          <td><?= h($regionalTotals['leads']) ?></td>
          <td><?= h($regionalTotals['closing']) ?></td>
          <td><?= h($regionalTotals['cpl']) ?></td>
          <td colspan="3"></td>
        </tr>
        <?php foreach ($regionalGroup['campuses'] as $campusGroup): ?>
          <?php $campusTotals = ads_group_total_cells($campusGroup['totals']); ?>
          <tr class="group-subheading">
            <td colspan="5">Nama Kampus: <?= h((string) $campusGroup['label']) ?> <span><?= h(number_format((int) $campusGroup['totals']['count'], 0, ',', '.')) ?> laporan</span></td>
            <td><?= h($campusTotals['anggaran']) ?></td>
            <td><?= h($campusTotals['realisasi']) ?></td>
            <td><?= h($campusTotals['leads']) ?></td>
            <td><?= h($campusTotals['closing']) ?></td>
            <td><?= h($campusTotals['cpl']) ?></td>
            <td colspan="3"></td>
          </tr>
          <?php foreach ($campusGroup['rows'] as $row): ?>
            <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) (($row['unit_name'] ?? '') ?: '-')) ?></td><td><?= h((string) ($row['platform'] ?: '-')) ?></td><td><?= h((string) ($row['campaign_name'] ?: $row['title'])) ?></td><td><?= h(money_idr((float) $row['budget_requested'])) ?></td><td><?= h(money_idr((float) $row['realization_amount'])) ?></td><td><?= h((string) $row['leads_count']) ?></td><td><?= h((string) ($row['closing_count'] ?? 0)) ?></td><td><?= h(money_idr((float) $row['cpl'])) ?></td><td><?= attachment_badge((string) ($row['attachment_path'] ?? '')) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status'], (string) ($row['report_type'] ?? 'ads')) ?></td></tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function ads_grouped_reports(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $regional = (string) (($row['wilayah'] ?? '') ?: 'Regional belum diatur');
        $campus = (string) (($row['unit_name'] ?? '') ?: 'Kampus belum diatur');
        $regionalKey = strtolower($regional);
        $campusKey = strtolower($campus);
        if (!isset($groups[$regionalKey])) {
            $groups[$regionalKey] = ['label' => $regional, 'totals' => ads_group_totals(), 'campuses' => []];
        }
        if (!isset($groups[$regionalKey]['campuses'][$campusKey])) {
            $groups[$regionalKey]['campuses'][$campusKey] = ['label' => $campus, 'totals' => ads_group_totals(), 'rows' => []];
        }
        ads_group_add($groups[$regionalKey]['totals'], $row);
        ads_group_add($groups[$regionalKey]['campuses'][$campusKey]['totals'], $row);
        $groups[$regionalKey]['campuses'][$campusKey]['rows'][] = $row;
    }

    return $groups;
}

function ads_group_totals(): array
{
    return ['count' => 0, 'requested' => 0.0, 'realization' => 0.0, 'leads' => 0, 'closing' => 0];
}

function ads_group_add(array &$totals, array $row): void
{
    $totals['count']++;
    $totals['requested'] += (float) ($row['budget_requested'] ?? 0);
    $totals['realization'] += (float) ($row['realization_amount'] ?? 0);
    $totals['leads'] += (int) ($row['leads_count'] ?? 0);
    $totals['closing'] += (int) ($row['closing_count'] ?? 0);
}

function ads_group_total_cells(array $totals): array
{
    $cpl = (int) $totals['leads'] > 0 ? ((float) $totals['realization'] / (int) $totals['leads']) : 0.0;

    return [
        'anggaran' => money_idr((float) $totals['requested']),
        'realisasi' => money_idr((float) $totals['realization']),
        'leads' => number_format((int) $totals['leads'], 0, ',', '.'),
        'closing' => number_format((int) $totals['closing'], 0, ',', '.'),
        'cpl' => money_idr($cpl),
    ];
}

function attachment_badge(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '<span class="attachment-empty">-</span>';
    }
    return '<a class="attachment-link" href="' . h($path) . '" target="_blank" rel="noopener" title="Lihat bukti invoice/screenshot">Bukti</a>';
}

function render_monthly_targets_table(array $rows): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>Bulan</th><th>Scope</th><th>Wilayah</th><th>Unit/Kampus</th><th>Staff</th><th>Leads</th><th>Follow Up</th><th>Registrasi</th><th>Herregistrasi</th><th>Anggaran</th><th>Catatan</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="empty-row">Belum ada target bulanan yang diatur.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) ($row['target_month'] ?? '-')) ?></td>
          <td><?= h(ucfirst((string) ($row['scope_type'] ?? 'regional'))) ?></td>
          <td><?= h((string) (($row['wilayah'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['unit_name'] ?? '') ?: '-')) ?></td>
          <td><?= h((string) (($row['staff_name'] ?? '') ?: '-')) ?></td>
          <td><?= h(number_format((int) ($row['target_leads'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((int) ($row['target_follow_up'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((int) ($row['target_registrasi'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(number_format((int) ($row['target_herregistrasi'] ?? 0), 0, ',', '.')) ?></td>
          <td><?= h(money_idr((float) ($row['target_anggaran'] ?? 0))) ?></td>
          <td><?= h((string) (($row['notes'] ?? '') ?: '-')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_ad_leads_table(array $rows, int $reportId): void
{
    ?>
    <form id="ad-leads-update-form" method="post">
      <?= rsm_csrf_field() ?>
      <input type="hidden" name="action" value="update_ad_leads">
      <input type="hidden" name="report_id" value="<?= h((string) $reportId) ?>">
      <div class="table-wrap"><table><thead><tr><th>Nama Lead</th><th>No. HP/WA</th><th>Email</th><th>Kampus</th><th>Jurusan</th><th>Kota Asal</th><th>Follow Up</th><th>Status Closing</th><th>Catatan</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Belum ada data hasil iklan yang diupload untuk laporan ini.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <?php $leadId = (int) $row['id']; ?>
          <tr>
            <td><?= h(display_or_empty($row['lead_name'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['whatsapp'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['email'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['campus_name'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['major_name'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['origin_city'] ?? '')) ?></td>
            <td><?= h(display_or_empty($row['follow_up_result'] ?? '')) ?></td>
            <td><?= closing_status_select($leadId, (string) ($row['closing_status'] ?? '')) ?></td>
            <td><textarea class="compact-note-input" name="lead_notes[<?= h((string) $leadId) ?>]" rows="2"><?= h((string) ($row['notes'] ?? '')) ?></textarea></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </form>
    <?php
}

function closing_status_select(int $leadId, string $current): string
{
    $options = ['Belum closing', 'Potensi closing', 'Closing', 'Herregistrasi', 'Tidak closing'];
    $isClosing = strtolower(trim($current)) === 'closing';
    $class = 'compact-status-select' . ($isClosing ? ' is-closing' : '');
    $style = $isClosing ? ' style="border-color:#93c5fd;background:#dbeafe;color:#1e3a8a;font-weight:700;"' : '';
    $html = '<select class="' . h($class) . '" name="lead_status[' . h((string) $leadId) . ']"' . $style . '>';
    foreach ($options as $option) {
        $selected = strtolower($current) === strtolower($option) ? ' selected' : '';
        $html .= '<option' . $selected . '>' . h($option) . '</option>';
    }
    return $html . '</select>';
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
        <tr><td><?= h((string) $row['report_date']) ?></td><td><?= h((string) ($row['category'] ?: '-')) ?></td><td><?= h((string) $row['staff_name']) ?></td><td><?= h((string) ($row['result_text'] ?: '-')) ?></td><td><?= h((string) ($row['follow_up_text'] ?: '-')) ?></td><td><?= badge((string) $row['status']) ?></td><td><?= action_buttons($role, (int) $row['id'], (string) $row['status'], (string) ($row['report_type'] ?? 'other')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function render_users_table(array $rows, array $references = []): void
{
    $campusOptions = [];
    foreach (($references['campuses'] ?? []) as $campusOption) {
        $label = trim((string) ($campusOption['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $campusOptions[$label] = [
            'label' => $label,
            'regional' => (string) ($campusOption['regional'] ?? ''),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $regionalCompare = strnatcasecmp((string) (($a['regional'] ?? '') ?: 'Tanpa Regional'), (string) (($b['regional'] ?? '') ?: 'Tanpa Regional'));
        if ($regionalCompare !== 0) {
            return $regionalCompare;
        }
        $roleOrder = ['senior' => 0, 'mentor' => 1, 'koordinator' => 2, 'staff' => 3];
        $roleCompare = ($roleOrder[(string) ($a['role'] ?? '')] ?? 9) <=> ($roleOrder[(string) ($b['role'] ?? '')] ?? 9);
        if ($roleCompare !== 0) {
            return $roleCompare;
        }
        return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    $currentRegional = null;
    ?>
    <div class="table-wrap"><table><thead><tr><th>Nama</th><th>NIK</th><th>Username</th><th>No. HP</th><th>Lama Bekerja</th><th>Role/Jabatan</th><th>Regional</th><th>Area</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="10" class="empty-row">Belum ada user RSM.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php
          $regionalLabel = (string) (($row['regional'] ?? '') ?: 'Tanpa Regional');
          if ($regionalLabel !== $currentRegional):
            $currentRegional = $regionalLabel;
        ?>
          <tr class="group-heading"><td colspan="10">Regional: <?= h($regionalLabel) ?></td></tr>
        <?php endif; ?>
        <tr>
          <td><?= h((string) $row['name']) ?></td>
          <td><?= h(display_or_empty($row['nik'] ?? '')) ?></td>
          <td><?= h((string) $row['username']) ?></td>
          <td><?= h(display_or_empty($row['phone_number'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['work_duration'] ?? '')) ?></td>
          <td><?= h((string) $row['role']) ?></td>
          <td><?= h(display_or_empty($row['regional'] ?? '')) ?></td>
          <td><?= h(display_or_empty($row['area'] ?? '')) ?></td>
          <td><?= ((int) $row['is_active'] === 1) ? badge('Aktif') : badge('Nonaktif') ?></td>
          <td>
            <div class="icon-actions">
              <button class="icon-action" type="button" title="Edit user" aria-label="Edit user" onclick="document.getElementById('edit-user-<?= h((string) $row['id']) ?>').classList.toggle('is-open')">&#9998;</button>
              <form method="post" class="inline-action" title="Reset password user">
                <?= rsm_csrf_field() ?>
                <input type="hidden" name="action" value="admin_reset_user_password">
                <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
                <input class="icon-password" name="new_password" placeholder="Password baru" required minlength="6" title="Isi password baru">
                <button class="icon-action" title="Reset password" aria-label="Reset password">&#8635;</button>
              </form>
              <form method="post" class="inline-action" title="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>">
              <?= rsm_csrf_field() ?>
              <input type="hidden" name="action" value="admin_set_user_active">
              <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
              <input type="hidden" name="is_active" value="<?= (int) $row['is_active'] === 1 ? '0' : '1' ?>">
                <button class="icon-action <?= (int) $row['is_active'] === 1 ? 'danger' : '' ?>" title="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>" aria-label="<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan user' : 'Aktifkan user' ?>"><?= (int) $row['is_active'] === 1 ? '&#9211;' : '&#10003;' ?></button>
              </form>
              <form method="post" class="inline-action" onsubmit="return confirm('Hapus user ini?')" title="Hapus user">
                <?= rsm_csrf_field() ?>
                <input type="hidden" name="action" value="admin_delete_user">
                <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
                <button class="icon-action danger" title="Hapus user" aria-label="Hapus user">&times;</button>
              </form>
            </div>
          </td>
        </tr>
        <tr id="edit-user-<?= h((string) $row['id']) ?>" class="edit-user-row">
          <td colspan="10">
            <form method="post" class="edit-user-form">
              <?= rsm_csrf_field() ?>
              <input type="hidden" name="action" value="admin_update_user">
              <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
              <input name="name" value="<?= h((string) $row['name']) ?>" required title="Nama">
              <input name="nik" value="<?= h((string) ($row['nik'] ?? '')) ?>" placeholder="NIK" title="NIK">
              <input name="username" value="<?= h((string) $row['username']) ?>" required title="Username">
              <select name="user_role" title="Role">
                <?php foreach (['staff' => 'Staff Unit', 'koordinator' => 'Koordinator Wilayah', 'mentor' => 'Mentor', 'senior' => 'Senior Manager'] as $key => $label): ?>
                  <option value="<?= h($key) ?>" <?= (string) $row['role'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="regional" class="js-regional-select" title="Regional">
                <option value="">Regional</option>
                <?php foreach (['Regional 1', 'Regional 2', 'Regional 3', 'Regional 4', 'Regional 5', 'Regional 6', 'Regional 7'] as $regionalOption): ?>
                  <?php $optionArea = in_array($regionalOption, ['Regional 1', 'Regional 2', 'Regional 3'], true) ? 'Regional A' : 'Regional B'; ?>
                  <option data-area="<?= h($optionArea) ?>" <?= (string) ($row['regional'] ?? '') === $regionalOption ? 'selected' : '' ?>><?= h($regionalOption) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="area" class="js-area-select" title="Area">
                <option value="">Area</option>
                <option <?= (string) ($row['area'] ?? '') === 'Regional A' ? 'selected' : '' ?>>Regional A</option>
                <option <?= (string) ($row['area'] ?? '') === 'Regional B' ? 'selected' : '' ?>>Regional B</option>
              </select>
              <select name="campus_name" title="Kampus/Unit">
                <?php
                  $currentCampus = (string) ($row['campus_name'] ?? '');
                  if ($currentCampus !== '' && !isset($campusOptions[$currentCampus])) {
                      $campusOptions[$currentCampus] = ['label' => $currentCampus, 'regional' => ''];
                  }
                ?>
                <option value="">Kampus/Unit</option>
                <?php foreach ($campusOptions as $campusOption): ?>
                  <?php $campusLabel = (string) ($campusOption['label'] ?? ''); ?>
                  <option value="<?= h($campusLabel) ?>" <?= $currentCampus === $campusLabel ? 'selected' : '' ?>><?= h($campusLabel) ?><?= !empty($campusOption['regional']) ? ' - ' . h((string) $campusOption['regional']) : '' ?></option>
                <?php endforeach; ?>
              </select>
              <input name="phone_number" value="<?= h((string) ($row['phone_number'] ?? '')) ?>" placeholder="No. HP/Marketing" title="No. HP/Marketing">
              <input name="work_duration" value="<?= h((string) ($row['work_duration'] ?? '')) ?>" placeholder="Lama bekerja" title="Lama bekerja">
              <button class="icon-action" title="Simpan perubahan" aria-label="Simpan perubahan">&#10003;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function action_buttons(string $role, int $id, string $status, string $reportType = ''): string
{
    $buttons = '<div class="report-actions">';
    $buttons .= '<a class="icon-action mini" href="' . h(url_for('detail', $role) . '&id=' . $id) . '" title="Detail" data-tooltip="Detail" aria-label="Detail"><span aria-hidden="true">&#128065;</span></a>';
    if (can_edit_report($role, $status, $reportType)) {
        $buttons .= '<a class="icon-action mini" href="' . h(url_for('edit', $role) . '&id=' . $id) . '" title="Edit" data-tooltip="Edit" aria-label="Edit"><span aria-hidden="true">&#9998;</span></a>';
    }
    if (can_delete_report($role, $status, $reportType)) {
        $buttons .= delete_form($id);
    }
    if (can_action($role, 'verifikasi')) {
        $buttons .= status_form($id, 'Diverifikasi', 'Verifikasi') . status_form($id, 'Revisi', 'Revisi', true);
    }
    if (can_action($role, 'setujui')) {
        $buttons .= status_form($id, 'Disetujui', 'Setujui') . status_form($id, 'Ditolak', 'Tolak', false, 'danger') . status_form($id, 'Revisi', 'Revisi', true);
    }
    return $buttons . '</div>';
}

function can_edit_report(string $role, string $status, string $reportType = ''): bool
{
    if ($role === 'koordinator' && $reportType === 'ads') {
        return true;
    }
    return can_action($role, 'edit-draft') && in_array(strtolower($status), ['draft', 'revisi'], true);
}

function can_delete_report(string $role, string $status, string $reportType = ''): bool
{
    if ($role === 'koordinator' && $reportType === 'ads') {
        return true;
    }
    return in_array($role, ['senior', 'mentor'], true) || can_edit_report($role, $status, $reportType);
}

function delete_form(int $id): string
{
    return '<form method="post" class="inline-action" onsubmit="return confirm(\'Hapus laporan ini?\')">'
        . rsm_csrf_field()
        . '<input type="hidden" name="action" value="delete_report">'
        . '<input type="hidden" name="report_id" value="' . h((string) $id) . '">'
        . '<button class="icon-action mini danger" title="Hapus" data-tooltip="Hapus" aria-label="Hapus"><span aria-hidden="true">&#128465;</span></button>'
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
    $icons = [
        'Diverifikasi' => '&#10003;',
        'Disetujui' => '&#10003;',
        'Ditolak' => '&#10005;',
        'Revisi' => '&#8635;',
    ];
    $icon = $icons[$status] ?? '&#8226;';
    return '<form method="post" class="inline-action">'
        . rsm_csrf_field()
        . '<input type="hidden" name="action" value="status_update">'
        . '<input type="hidden" name="report_id" value="' . h((string) $id) . '">'
        . '<input type="hidden" name="new_status" value="' . h($status) . '">'
        . $note
        . '<button class="icon-action mini ' . h($tone ?: ($needsNote ? 'warning' : 'success')) . '" title="' . h($label) . '" data-tooltip="' . h($label) . '" aria-label="' . h($label) . '"><span aria-hidden="true">' . $icon . '</span></button>'
        . '</form>';
}

function role_points(string $role): array
{
    return [
        'senior' => ['Melihat semua wilayah, unit, dan staff', 'Menyetujui atau menolak laporan', 'Melihat seluruh anggaran iklan', 'Export semua laporan', 'Wajib memberi catatan jika revisi'],
        'mentor' => ['Melihat semua wilayah, unit, dan staff', 'Mengatur target pencapaian staff', 'Menyetujui atau menolak laporan', 'Tidak melihat menu Kelola User', 'Wajib memberi catatan jika revisi'],
        'koordinator' => ['Melihat data wilayahnya saja', 'Memverifikasi laporan staff unit', 'Menambahkan kegiatan wilayah', 'Membuat rekap wilayah', 'Memberi catatan revisi kepada staff'],
        'staff' => ['Menambahkan laporan kegiatan', 'Melihat laporan milik sendiri', 'Edit hanya saat Draft atau Revisi', 'Tidak melihat wilayah lain', 'Tidak bisa menyetujui laporan'],
    ][$role] ?? [];
}
