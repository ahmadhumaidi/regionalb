<?php
declare(strict_types=1);

function rsm_profile_allowed_users(string $area, array $actor): array
{
    $sql = 'SELECT id, name, nik, username, role, jabatan, regional, area, campus_name, phone_number, work_duration, bio_text, photo_path, created_at FROM rsm_users WHERE is_active = 1 AND area = ?';
    $params = [$area];
    $role = (string) ($actor['role'] ?? '');
    if ($role === 'koordinator') {
        $sql .= ' AND regional = ?';
        $params[] = (string) ($actor['regional'] ?? '');
    } elseif ($role === 'staff') {
        $sql .= ' AND id = ?';
        $params[] = (int) ($actor['id'] ?? 0);
    } elseif (!in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
        $sql .= ' AND 1 = 0';
    }
    $sql .= " ORDER BY FIELD(role, 'super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator', 'staff'), regional ASC, name ASC";
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_profile_target_user(string $area, array $actor, int $targetId): array
{
    $allowed = rsm_profile_allowed_users($area, $actor);
    if ($targetId <= 0) {
        $targetId = (int) ($actor['id'] ?? 0);
    }
    foreach ($allowed as $user) {
        if ((int) ($user['id'] ?? 0) === $targetId) {
            return $user;
        }
    }
    if ($targetId !== (int) ($actor['id'] ?? 0)) {
        throw new RuntimeException('Profil user ini berada di luar scope akses Anda.');
    }
    return $actor;
}

function rsm_profile_report_filter_sql(array $target, string $alias = 'r'): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $role = (string) ($target['role'] ?? '');
    if (in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
        return ['', []];
    }
    if ($role === 'koordinator') {
        $regional = (string) ($target['regional'] ?? '');
        return $regional !== '' ? [" AND {$prefix}wilayah = ?", [$regional]] : ['', []];
    }

    $name = (string) ($target['name'] ?? '');
    $params = [(int) ($target['id'] ?? 0), $name, $name];
    $sql = " AND ({$prefix}user_id = ? OR {$prefix}staff_name = ? OR {$prefix}created_by_name = ?)";
    if (!empty($target['regional'])) {
        $sql .= " AND {$prefix}wilayah = ?";
        $params[] = (string) $target['regional'];
    }
    return [$sql, $params];
}

function rsm_profile_gamification(string $area, array $actor, int $targetId = 0): array
{
    $target = rsm_profile_target_user($area, $actor, $targetId);
    $allowedUsers = rsm_profile_allowed_users($area, $actor);
    [$userSql, $userParams] = rsm_profile_report_filter_sql($target, 'r');
    $statusMap = rsm_dashboard_status_map($area, [], $actor);
    $buckets = rsm_dashboard_closing_buckets($statusMap['closing_status']);
    [$registrasiCondition, $registrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['registrasi']);
    [$herregistrasiCondition, $herregistrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['herregistrasi']);

    $detailSql = "SELECT
            report_id,
            COUNT(*) AS detail_leads,
            SUM(CASE WHEN COALESCE(follow_up_result, '') <> '' OR COALESCE(progress_status, '') <> '' THEN 1 ELSE 0 END) AS detail_follow_up,
            SUM(CASE WHEN {$registrasiCondition} THEN 1 ELSE 0 END) AS detail_registrasi,
            SUM(CASE WHEN {$herregistrasiCondition} THEN 1 ELSE 0 END) AS detail_herregistrasi
        FROM rsm_ad_leads
        GROUP BY report_id";

    $params = array_merge($registrasiParams, $herregistrasiParams, [$area], $userParams);
    $stmt = rsm_pdo()->prepare(
        "SELECT
            COUNT(*) AS total_reports,
            SUM(r.report_type = 'marketing') AS marketing_reports,
            SUM(r.report_type = 'ads') AS ads_reports,
            SUM(r.report_type = 'other') AS other_reports,
            SUM(r.status IN ('Diverifikasi','Disetujui','Disetujui Senior Manager','Selesai','Berjalan')) AS approved_reports,
            SUM(r.status IN ('Ditolak')) AS rejected_reports,
            COUNT(DISTINCT r.report_date) AS active_days,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END), 0) AS leads_total,
            COALESCE(SUM(COALESCE(la.detail_follow_up, 0)), 0) AS follow_up_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END), 0) AS closing_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_herregistrasi ELSE 0 END), 0) AS herregistrasi_total,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.realization_amount ELSE 0 END), 0) AS spend_total,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.budget_approved ELSE 0 END), 0) AS approved_budget,
            MIN(r.report_date) AS first_report_date,
            MAX(r.report_date) AS last_report_date
         FROM rsm_reports r
         LEFT JOIN ({$detailSql}) la ON la.report_id = r.id
         WHERE r.area = ?{$userSql}"
    );
    $stmt->execute($params);
    $stats = $stmt->fetch() ?: [];

    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-d');
    $monthly = rsm_profile_month_stats($area, $target, $monthStart, $monthEnd, $buckets);
    $history = rsm_profile_monthly_history($area, $target, $buckets);
    $activities = rsm_profile_recent_activities($area, $target);
    $streak = rsm_profile_streak($area, $target);

    $xp = rsm_profile_xp($area, $target, $buckets);
    $aggregateRoles = ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator'];
    if (in_array((string) ($target['role'] ?? ''), $aggregateRoles, true)) {
        $aggregate = rsm_profile_role_aggregate($area, $target, $monthStart, $monthEnd);
        if ($aggregate !== null) {
            $stats = array_merge($stats, $aggregate['stats']);
            $monthly = array_merge($monthly, $aggregate['monthly']);
            $xp = (int) $aggregate['xp'];
        }
    }
    $level = rsm_profile_level($xp);
    $closing = (int) ($stats['closing_total'] ?? 0);
    $leads = (int) ($stats['leads_total'] ?? 0);
    $reports = (int) ($stats['total_reports'] ?? 0);
    $activeDays = (int) ($stats['active_days'] ?? 0);
    $competencies = rsm_profile_competencies($stats, $monthly, $streak);
    $badges = rsm_profile_badges($stats, $streak, $competencies, $monthly);
    $league = rsm_profile_league($xp, $closing, (int) ($streak['current'] ?? 0));

    return [
        'target' => $target,
        'allowed_users' => $allowedUsers,
        'stats' => [
            'total_reports' => $reports,
            'marketing_reports' => (int) ($stats['marketing_reports'] ?? 0),
            'ads_reports' => (int) ($stats['ads_reports'] ?? 0),
            'other_reports' => (int) ($stats['other_reports'] ?? 0),
            'approved_reports' => (int) ($stats['approved_reports'] ?? 0),
            'rejected_reports' => (int) ($stats['rejected_reports'] ?? 0),
            'leads_total' => $leads,
            'follow_up_total' => (int) ($stats['follow_up_total'] ?? 0),
            'closing_total' => $closing,
            'herregistrasi_total' => (int) ($stats['herregistrasi_total'] ?? 0),
            'active_days' => $activeDays,
            'spend_total' => (float) ($stats['spend_total'] ?? 0),
            'approved_budget' => (float) ($stats['approved_budget'] ?? 0),
        ],
        'xp' => $xp,
        'level' => $level,
        'league' => $league,
        'status_performa' => rsm_profile_performance_status($league, $competencies),
        'joined_label' => rsm_profile_joined_label((string) ($target['created_at'] ?? '')),
        'streak' => $streak,
        'competencies' => $competencies,
        'badges' => $badges,
        'kpis' => rsm_profile_kpis($monthly, $stats, rsm_bdc_fu_hari_ini_for_user($target)),
        'activities' => $activities,
        'history' => $history,
        'score' => (int) round(array_sum(array_column($competencies, 'score')) / max(1, count($competencies))),
    ];
}

function rsm_profile_xp(string $area, array $target, array $buckets): int
{
    [$userSql, $userParams] = rsm_profile_report_filter_sql($target, 'r');
    [$registrasiCondition, $registrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['registrasi']);
    $detailSql = "SELECT report_id, COUNT(*) AS detail_leads, SUM(CASE WHEN {$registrasiCondition} THEN 1 ELSE 0 END) AS detail_registrasi FROM rsm_ad_leads GROUP BY report_id";
    $stmt = rsm_pdo()->prepare(
        "SELECT r.report_type, r.status,
            CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END AS leads_total,
            CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END AS closing_total
         FROM rsm_reports r
         LEFT JOIN ({$detailSql}) la ON la.report_id = r.id
         WHERE r.area = ?{$userSql}"
    );
    $stmt->execute(array_merge($registrasiParams, [$area], $userParams));
    $xp = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (!in_array((string) ($row['status'] ?? ''), ['Diverifikasi', 'Disetujui', 'Disetujui Senior Manager', 'Selesai', 'Berjalan'], true)) {
            continue;
        }
        $reportType = (string) ($row['report_type'] ?? '');
        $xp += ['marketing' => 10, 'ads' => 15, 'other' => 8][$reportType] ?? 0;
        $xp += (int) ($row['leads_total'] ?? 0) * 2;
        $xp += (int) ($row['closing_total'] ?? 0) * 25;
    }
    return $xp;
}

function rsm_profile_role_aggregate(string $area, array $target, string $from, string $to): ?array
{
    $filters = [
        'date_from' => $from,
        'date_to' => $to,
        'wilayah' => '',
        'unit_name' => '',
        'staff_name' => '',
        'platform' => '',
        'status' => '',
        'month' => date('Y-m', strtotime($from)),
    ];
    if ((string) ($target['role'] ?? '') === 'koordinator' && (string) ($target['regional'] ?? '') !== '') {
        $filters['wilayah'] = (string) $target['regional'];
    }

    $summary = rsm_gamification_summary($area, $filters, $target);
    $row = is_array($summary['my_rank'] ?? null) ? $summary['my_rank'] : null;
    if ($row === null) {
        return null;
    }

    $reports = (int) ($row['report_total'] ?? 0);
    $approved = (int) ($row['approved_reports'] ?? 0);
    $leads = (int) ($row['leads_total'] ?? 0);
    $followUp = (int) ($row['follow_up_total'] ?? 0);
    $closing = (int) (($row['closing_for_points'] ?? null) ?? ($row['registrasi_total'] ?? 0));
    $herreg = (int) (($row['herreg_for_points'] ?? null) ?? ($row['herregistrasi_total'] ?? 0));
    $adsReports = (int) ($row['uploaded_ad_reports'] ?? 0);
    $spend = (float) ($row['spend_total'] ?? 0);
    $points = (int) ($row['points'] ?? 0);

    return [
        'xp' => $points,
        'stats' => [
            'total_reports' => $reports,
            'marketing_reports' => (int) ($row['marketing_reports'] ?? 0),
            'ads_reports' => $adsReports,
            'other_reports' => (int) ($row['other_reports'] ?? 0),
            'approved_reports' => $approved,
            'rejected_reports' => 0,
            'active_days' => (int) ($row['report_days'] ?? 0),
            'leads_total' => $leads,
            'follow_up_total' => $followUp,
            'closing_total' => $closing,
            'herregistrasi_total' => $herreg,
            'spend_total' => $spend,
            'approved_budget' => max($spend, 0.0),
        ],
        'monthly' => [
            'reports' => $reports,
            'marketing_reports' => (int) ($row['marketing_reports'] ?? 0),
            'ads_reports' => $adsReports,
            'follow_up' => $followUp,
            'leads' => $leads,
            'closing' => $closing,
            'spend' => $spend,
        ],
    ];
}

function rsm_profile_level(int $xp): array
{
    $thresholds = [1 => 0, 2 => 200, 3 => 500, 4 => 1000, 5 => 2000, 6 => 3500, 7 => 5500];
    $level = 1;
    foreach ($thresholds as $candidate => $minXp) {
        if ($xp >= $minXp) {
            $level = $candidate;
        }
    }
    $currentMin = $thresholds[$level] ?? (5500 + (($level - 7) * 2500));
    $nextMin = $thresholds[$level + 1] ?? ($currentMin + 2500);
    $progress = $nextMin > $currentMin ? (($xp - $currentMin) / ($nextMin - $currentMin)) * 100 : 100;
    return [
        'number' => $level,
        'current_min' => $currentMin,
        'next_min' => $nextMin,
        'progress' => max(0, min(100, $progress)),
        'remaining' => max(0, $nextMin - $xp),
    ];
}

function rsm_profile_league(int $xp, int $closing, int $streak): string
{
    $score = $xp + ($closing * 40) + ($streak * 30);
    if ($score >= 5000) {
        return 'Champion';
    }
    if ($score >= 2500) {
        return 'Achiever';
    }
    if ($score >= 1000) {
        return 'Performer';
    }
    if ($score >= 250) {
        return 'Rising Star';
    }
    return 'Starter';
}

function rsm_profile_performance_status(string $league, array $competencies): string
{
    $score = (int) round(array_sum(array_column($competencies, 'score')) / max(1, count($competencies)));
    if (in_array($league, ['Champion', 'Achiever'], true) && $score >= 70) {
        return 'Unggul';
    }
    if ($score >= 45) {
        return 'Berkembang';
    }
    if ($score > 0) {
        return 'Perlu didorong';
    }
    return 'Belum ada data';
}

function rsm_profile_streak(string $area, array $target): array
{
    [$userSql, $userParams] = rsm_profile_report_filter_sql($target, 'r');
    $stmt = rsm_pdo()->prepare(
        "SELECT DISTINCT r.report_date
         FROM rsm_reports r
         WHERE r.area = ?{$userSql}
           AND r.status NOT IN ('Draft','Revisi','Ditolak')
         ORDER BY r.report_date DESC"
    );
    $stmt->execute(array_merge([$area], $userParams));
    $dates = array_map(static fn (array $row): string => (string) $row['report_date'], $stmt->fetchAll());
    $set = array_flip($dates);
    $today = new DateTimeImmutable(date('Y-m-d'));
    $current = 0;
    for ($day = $today; isset($set[$day->format('Y-m-d')]); $day = $day->modify('-1 day')) {
        $current++;
    }
    $longest = 0;
    $run = 0;
    $previous = null;
    foreach (array_reverse($dates) as $date) {
        $currentDate = new DateTimeImmutable($date);
        if ($previous && $currentDate->diff($previous)->days === 1) {
            $run++;
        } else {
            $run = 1;
        }
        $longest = max($longest, $run);
        $previous = $currentDate;
    }
    return ['current' => $current, 'longest' => $longest];
}

function rsm_profile_month_stats(string $area, array $target, string $from, string $to, array $buckets): array
{
    [$userSql, $userParams] = rsm_profile_report_filter_sql($target, 'r');
    [$registrasiCondition, $registrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['registrasi']);
    $detailSql = "SELECT report_id, COUNT(*) AS detail_leads, SUM(CASE WHEN COALESCE(follow_up_result, '') <> '' OR COALESCE(progress_status, '') <> '' THEN 1 ELSE 0 END) AS detail_follow_up, SUM(CASE WHEN {$registrasiCondition} THEN 1 ELSE 0 END) AS detail_registrasi FROM rsm_ad_leads GROUP BY report_id";
    $stmt = rsm_pdo()->prepare(
        "SELECT
            COUNT(*) AS reports,
            SUM(r.report_type = 'marketing') AS marketing_reports,
            SUM(r.report_type = 'ads') AS ads_reports,
            COALESCE(SUM(COALESCE(la.detail_follow_up, 0)), 0) AS follow_up,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END), 0) AS leads,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END), 0) AS closing,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.realization_amount ELSE 0 END), 0) AS spend
         FROM rsm_reports r
         LEFT JOIN ({$detailSql}) la ON la.report_id = r.id
         WHERE r.area = ?{$userSql} AND r.report_date BETWEEN ? AND ?"
    );
    $stmt->execute(array_merge($registrasiParams, [$area], $userParams, [$from, $to]));
    return $stmt->fetch() ?: [];
}

function rsm_profile_monthly_history(string $area, array $target, array $buckets): array
{
    $rows = [];
    for ($i = 5; $i >= 0; $i--) {
        $start = date('Y-m-01', strtotime("-{$i} months"));
        $end = date('Y-m-t', strtotime($start));
        $stats = rsm_profile_month_stats($area, $target, $start, $end, $buckets);
        $xp = ((int) ($stats['reports'] ?? 0) * 10) + ((int) ($stats['leads'] ?? 0) * 2) + ((int) ($stats['closing'] ?? 0) * 25);
        $rows[] = [
            'label' => date('M Y', strtotime($start)),
            'activities' => (int) ($stats['reports'] ?? 0),
            'leads' => (int) ($stats['leads'] ?? 0),
            'closing' => (int) ($stats['closing'] ?? 0),
            'xp' => $xp,
        ];
    }
    return $rows;
}

function rsm_profile_competencies(array $stats, array $monthly, array $streak): array
{
    $reports = (int) ($stats['total_reports'] ?? 0);
    $leads = (int) ($stats['leads_total'] ?? 0);
    $followUp = (int) ($stats['follow_up_total'] ?? 0);
    $closing = (int) ($stats['closing_total'] ?? 0);
    $adsReports = (int) ($stats['ads_reports'] ?? 0);
    $spend = (float) ($stats['spend_total'] ?? 0);
    $approvedBudget = (float) ($stats['approved_budget'] ?? 0);
    return [
        ['label' => 'Konsistensi', 'score' => min(100, ((int) ($streak['longest'] ?? 0)) * 10)],
        ['label' => 'Aktivitas', 'score' => min(100, $reports * 8)],
        ['label' => 'Follow Up', 'score' => $leads > 0 ? min(100, ($followUp / $leads) * 100) : 0],
        ['label' => 'Closing', 'score' => $leads > 0 ? min(100, ($closing / $leads) * 100 * 5) : min(100, $closing * 12)],
        ['label' => 'Efektivitas Iklan', 'score' => $adsReports > 0 ? max(0, min(100, 100 - rsm_dashboard_percent($spend, max(1, $approvedBudget)) + (($monthly['closing'] ?? 0) * 5))) : 0],
        ['label' => 'Kelengkapan Laporan', 'score' => $reports > 0 ? min(100, ((int) ($stats['approved_reports'] ?? 0) / max(1, $reports)) * 100) : 0],
    ];
}

function rsm_profile_badges(array $stats, array $streak, array $competencies, array $monthly): array
{
    $leads = (int) ($stats['leads_total'] ?? 0);
    $closing = (int) ($stats['closing_total'] ?? 0);
    $reports = (int) ($stats['total_reports'] ?? 0);
    $marketing = (int) ($stats['marketing_reports'] ?? 0);
    $ads = (int) ($stats['ads_reports'] ?? 0);
    $competencyMap = [];
    foreach ($competencies as $item) {
        $competencyMap[(string) $item['label']] = (float) $item['score'];
    }
    return [
        ['icon' => '7', 'name' => 'Konsisten 7 Hari', 'desc' => 'Mengirim laporan valid 7 hari berturut-turut.', 'unlocked' => (int) ($streak['longest'] ?? 0) >= 7],
        ['icon' => '30', 'name' => 'Konsisten 30 Hari', 'desc' => 'Menjaga ritme laporan selama 30 hari.', 'unlocked' => (int) ($streak['longest'] ?? 0) >= 30],
        ['icon' => '100', 'name' => '100 Leads', 'desc' => 'Mengumpulkan minimal 100 leads.', 'unlocked' => $leads >= 100],
        ['icon' => '10', 'name' => '10 Closing', 'desc' => 'Mencapai minimal 10 registrasi.', 'unlocked' => $closing >= 10],
        ['icon' => 'M', 'name' => 'Aktivitas Lapangan', 'desc' => 'Aktif membuat laporan marketing.', 'unlocked' => $marketing >= 5],
        ['icon' => 'OK', 'name' => 'Laporan Tepat Waktu', 'desc' => 'Kelengkapan laporan minimal 80%.', 'unlocked' => ($competencyMap['Kelengkapan Laporan'] ?? 0) >= 80],
        ['icon' => 'AD', 'name' => 'Iklan Efektif', 'desc' => 'Memiliki laporan iklan dengan hasil closing.', 'unlocked' => $ads > 0 && (int) ($monthly['closing'] ?? 0) > 0],
        ['icon' => 'TP', 'name' => 'Top Performer Bulanan', 'desc' => 'Skor aktivitas bulan ini tinggi.', 'unlocked' => $reports >= 12 && $closing >= 3],
    ];
}

function rsm_profile_kpis(array $monthly, array $stats, int $bdcFuHariIni = 0): array
{
    $kpis = [
        ['label' => 'Laporan harian bulan ini', 'value' => (int) ($monthly['reports'] ?? 0)],
        ['label' => 'Follow Up Data BDC', 'value' => $bdcFuHariIni, 'note' => 'FU Hari Ini'],
        ['label' => 'Aktivitas marketing', 'value' => (int) ($monthly['marketing_reports'] ?? 0)],
        ['label' => 'Closing', 'value' => (int) ($monthly['closing'] ?? 0)],
        ['label' => 'Ketepatan laporan', 'value' => (int) ($stats['approved_reports'] ?? 0)],
        ['label' => 'Efektivitas iklan', 'value' => (int) ($monthly['ads_reports'] ?? 0)],
    ];
    foreach ($kpis as &$kpi) {
        $kpi['target'] = null;
        $kpi['percent'] = null;
        $kpi['status'] = ((int) $kpi['value']) > 0 ? 'Berjalan' : 'Belum mulai';
    }
    unset($kpi);
    return $kpis;
}

function rsm_profile_recent_activities(string $area, array $target): array
{
    [$userSql, $userParams] = rsm_profile_report_filter_sql($target, 'r');
    $stmt = rsm_pdo()->prepare(
        "SELECT r.report_date, r.report_type, r.title, r.unit_name, r.leads_count, r.closing_count, r.status
         FROM rsm_reports r
         WHERE r.area = ?{$userSql}
         ORDER BY r.report_date DESC, r.id DESC
         LIMIT 10"
    );
    $stmt->execute(array_merge([$area], $userParams));
    return $stmt->fetchAll();
}

function rsm_profile_joined_label(string $createdAt): string
{
    if ($createdAt === '') {
        return '-';
    }
    try {
        $created = new DateTimeImmutable($createdAt);
        $diff = $created->diff(new DateTimeImmutable('now'));
        if ($diff->y > 0) {
            return $diff->y . ' tahun ' . $diff->m . ' bulan';
        }
        if ($diff->m > 0) {
            return $diff->m . ' bulan ' . $diff->d . ' hari';
        }
        return max(0, $diff->d) . ' hari';
    } catch (Throwable) {
        return '-';
    }
}
