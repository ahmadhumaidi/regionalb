<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$sessionDir = __DIR__ . '/runtime/sessions';
if (session_status() === PHP_SESSION_NONE) {
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir)) {
        session_save_path($sessionDir);
    }
    session_start();
}

function rsm_load_config(): void
{
    $candidates = [
        getenv('RSM_CONFIG_PATH') ?: '',
        __DIR__ . '/../source_activity/config.php',
        __DIR__ . '/../../source_activity/config.php',
        __DIR__ . '/config.php',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            require_once $candidate;
            return;
        }
    }

    throw new RuntimeException('Config database belum ditemukan. Buat config.php dari config.example.php atau letakkan source_activity/config.php di folder private hosting.');
}

rsm_load_config();

function rsm_pdo(): PDO
{
    if (!function_exists('db')) {
        throw new RuntimeException('Koneksi database project belum tersedia.');
    }

    return db();
}

function rsm_csrf_token(): string
{
    if (empty($_SESSION['rsm_csrf_token']) || !is_string($_SESSION['rsm_csrf_token'])) {
        $_SESSION['rsm_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['rsm_csrf_token'];
}

function rsm_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(rsm_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function rsm_verify_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['rsm_csrf_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Sesi keamanan form kedaluwarsa. Muat ulang halaman lalu ulangi aksi.');
    }
}

function rsm_ensure_schema(): void
{
    $pdo = rsm_pdo();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            nik VARCHAR(80) NULL,
            username VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('super_user','executive_director','director','senior','mentor','koordinator','staff') NOT NULL DEFAULT 'staff',
            jabatan VARCHAR(120) NOT NULL,
            regional VARCHAR(120) NULL,
            area VARCHAR(40) NULL,
            campus_name VARCHAR(180) NULL,
            must_change_password TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_users_username (username),
            INDEX idx_rsm_users_role (role),
            INDEX idx_rsm_users_regional (regional),
            INDEX idx_rsm_users_area (area)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_deleted_usernames (
            username VARCHAR(100) PRIMARY KEY,
            deleted_name VARCHAR(180) NULL,
            deleted_role VARCHAR(40) NULL,
            deleted_by_user_id INT UNSIGNED NULL,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    rsm_ensure_user_role_enum();
    rsm_seed_users();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(40) NOT NULL,
            report_type ENUM('marketing','ads','other') NOT NULL,
            report_date DATE NOT NULL,
            user_id INT NULL,
            partner_campus_id INT NULL,
            wilayah VARCHAR(120) NOT NULL,
            unit_name VARCHAR(180) NOT NULL,
            staff_name VARCHAR(180) NOT NULL,
            created_by_name VARCHAR(180) NULL,
            created_by_role VARCHAR(40) NOT NULL,
            status VARCHAR(60) NOT NULL DEFAULT 'Draft',
            title VARCHAR(220) NOT NULL,
            activity_kind VARCHAR(120) NULL,
            location_name VARCHAR(220) NULL,
            target_text TEXT NULL,
            result_text TEXT NULL,
            leads_count INT UNSIGNED NOT NULL DEFAULT 0,
            closing_count INT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT NULL,
            platform VARCHAR(120) NULL,
            ad_period VARCHAR(40) NULL,
            campaign_name VARCHAR(220) NULL,
            ad_goal VARCHAR(80) NULL,
            budget_requested DECIMAL(15,2) NOT NULL DEFAULT 0,
            budget_approved DECIMAL(15,2) NOT NULL DEFAULT 0,
            realization_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            cpl DECIMAL(15,2) NOT NULL DEFAULT 0,
            campaign_link VARCHAR(500) NULL,
            category VARCHAR(120) NULL,
            obstacle_text TEXT NULL,
            follow_up_text TEXT NULL,
            attachment_path VARCHAR(500) NULL,
            revision_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rsm_reports_area_type (area, report_type),
            INDEX idx_rsm_reports_status (status),
            INDEX idx_rsm_reports_date (report_date),
            INDEX idx_rsm_reports_staff (staff_name),
            INDEX idx_rsm_reports_user (user_id),
            INDEX idx_rsm_reports_campus (partner_campus_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    rsm_add_column_if_missing('rsm_reports', 'user_id', 'INT NULL AFTER report_date');
    rsm_add_column_if_missing('rsm_reports', 'partner_campus_id', 'INT NULL AFTER user_id');
    rsm_add_column_if_missing('rsm_reports', 'closing_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER leads_count');
    rsm_add_column_if_missing('rsm_reports', 'ad_period', 'VARCHAR(40) NULL AFTER platform');
    rsm_add_column_if_missing('rsm_users', 'bio_text', 'TEXT NULL AFTER campus_name');
    rsm_add_column_if_missing('rsm_users', 'photo_path', 'VARCHAR(500) NULL AFTER bio_text');
    rsm_add_column_if_missing('rsm_users', 'phone_number', 'VARCHAR(80) NULL AFTER campus_name');
    rsm_add_column_if_missing('rsm_users', 'work_duration', 'VARCHAR(120) NULL AFTER phone_number');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_ad_budget_limits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(40) NOT NULL,
            ad_period VARCHAR(40) NOT NULL,
            wilayah VARCHAR(120) NOT NULL,
            budget_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_by_name VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_ad_budget_limits_scope (area, ad_period, wilayah),
            INDEX idx_rsm_ad_budget_limits_period (area, ad_period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_social_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(40) NOT NULL,
            wilayah VARCHAR(120) NOT NULL,
            unit_name VARCHAR(180) NOT NULL,
            instagram_username VARCHAR(180) NOT NULL,
            instagram_business_id VARCHAR(180) NULL,
            facebook_page_id VARCHAR(180) NULL,
            facebook_page_token TEXT NULL,
            token_expires_at DATETIME NULL,
            connected_at DATETIME NULL,
            pic_name VARCHAR(180) NULL,
            pic_phone VARCHAR(80) NULL,
            connection_status VARCHAR(60) NOT NULL DEFAULT 'Manual',
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_by_name VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_social_account (area, unit_name, instagram_username),
            INDEX idx_rsm_social_account_scope (area, wilayah, unit_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach ([
        "ALTER TABLE rsm_social_accounts ADD COLUMN facebook_page_id VARCHAR(180) NULL AFTER instagram_business_id",
        "ALTER TABLE rsm_social_accounts ADD COLUMN facebook_page_token TEXT NULL AFTER facebook_page_id",
        "ALTER TABLE rsm_social_accounts ADD COLUMN token_expires_at DATETIME NULL AFTER facebook_page_token",
        "ALTER TABLE rsm_social_accounts ADD COLUMN connected_at DATETIME NULL AFTER token_expires_at",
    ] as $alterSql) {
        try {
            $pdo->exec($alterSql);
        } catch (Throwable $e) {
            // Column already exists or the database driver rejected a repeated migration.
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_social_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            area VARCHAR(40) NOT NULL,
            post_date DATE NOT NULL,
            media_type ENUM('no_post','feed','reels','story') NOT NULL,
            post_time TIME NULL,
            caption TEXT NULL,
            post_url VARCHAR(500) NULL,
            keyword_match TINYINT(1) NOT NULL DEFAULT 0,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            reach_count INT UNSIGNED NOT NULL DEFAULT 0,
            like_count INT UNSIGNED NOT NULL DEFAULT 0,
            comment_count INT UNSIGNED NOT NULL DEFAULT 0,
            source_name VARCHAR(60) NOT NULL DEFAULT 'Manual',
            created_by_user_id INT UNSIGNED NULL,
            created_by_name VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rsm_social_posts_date (area, post_date),
            INDEX idx_rsm_social_posts_account (account_id),
            CONSTRAINT fk_rsm_social_posts_account
                FOREIGN KEY (account_id) REFERENCES rsm_social_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    try {
        $pdo->exec("ALTER TABLE rsm_social_posts MODIFY media_type ENUM('no_post','feed','reels','story') NOT NULL");
    } catch (Throwable $e) {
        // Ignore when the column is already compatible or the database driver rejects a no-op alter.
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_ad_leads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            lead_name VARCHAR(180) NULL,
            whatsapp VARCHAR(80) NULL,
            email VARCHAR(180) NULL,
            campus_name VARCHAR(180) NULL,
            major_name VARCHAR(180) NULL,
            origin_city VARCHAR(120) NULL,
            follow_up_result TEXT NULL,
            progress_status VARCHAR(120) NULL,
            closing_status VARCHAR(80) NULL,
            closing_update VARCHAR(180) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rsm_ad_leads_report (report_id),
            CONSTRAINT fk_rsm_ad_leads_report
                FOREIGN KEY (report_id) REFERENCES rsm_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    rsm_add_column_if_missing('rsm_ad_leads', 'email', 'VARCHAR(180) NULL AFTER whatsapp');
    rsm_add_column_if_missing('rsm_ad_leads', 'closing_status', 'VARCHAR(80) NULL AFTER progress_status');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_monthly_targets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(40) NOT NULL,
            target_month CHAR(7) NOT NULL,
            scope_type ENUM('regional','wilayah','unit','staff') NOT NULL DEFAULT 'regional',
            scope_key VARCHAR(260) NOT NULL,
            wilayah VARCHAR(120) NULL,
            unit_name VARCHAR(180) NULL,
            staff_name VARCHAR(180) NULL,
            target_leads INT UNSIGNED NOT NULL DEFAULT 0,
            target_follow_up INT UNSIGNED NOT NULL DEFAULT 0,
            target_registrasi INT UNSIGNED NOT NULL DEFAULT 0,
            target_herregistrasi INT UNSIGNED NOT NULL DEFAULT 0,
            target_anggaran DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_by_name VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_monthly_targets_scope (area, target_month, scope_key),
            INDEX idx_rsm_monthly_targets_area_month (area, target_month),
            INDEX idx_rsm_monthly_targets_scope (scope_type, scope_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_coordinator_schedules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(40) NOT NULL,
            schedule_date DATE NOT NULL,
            koordinator_user_id INT UNSIGNED NULL,
            koordinator_name VARCHAR(180) NOT NULL,
            koordinator_home VARCHAR(120) NULL,
            wilayah VARCHAR(120) NOT NULL,
            unit_name VARCHAR(180) NOT NULL,
            visit_type ENUM('Fisik','Zoom','Telepon') NOT NULL DEFAULT 'Zoom',
            agenda VARCHAR(220) NOT NULL,
            status ENUM('Rencana','Dijadwalkan','Selesai','Reschedule') NOT NULL DEFAULT 'Rencana',
            result_text TEXT NULL,
            next_action TEXT NULL,
            attachment_path VARCHAR(500) NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_by_name VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rsm_coord_schedule_area_date (area, schedule_date),
            INDEX idx_rsm_coord_schedule_wilayah (area, wilayah),
            INDEX idx_rsm_coord_schedule_korwil (koordinator_name),
            UNIQUE KEY uq_rsm_coord_schedule_day_unit (area, schedule_date, koordinator_name, unit_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    rsm_add_column_if_missing('rsm_coordinator_schedules', 'attachment_path', 'VARCHAR(500) NULL AFTER next_action');
    rsm_add_column_if_missing('rsm_coordinator_schedules', 'checklist_json', 'TEXT NULL AFTER attachment_path');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_bdc_report_user_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            snapshot_date DATE NOT NULL,
            fetched_at DATETIME NOT NULL,
            nik VARCHAR(80) NOT NULL,
            name VARCHAR(180) NOT NULL,
            campus_name VARCHAR(180) NULL,
            wilayah VARCHAR(120) NULL,
            total_count INT UNSIGNED NOT NULL DEFAULT 0,
            data_baru_count INT UNSIGNED NOT NULL DEFAULT 0,
            cold_count INT UNSIGNED NOT NULL DEFAULT 0,
            warm_count INT UNSIGNED NOT NULL DEFAULT 0,
            hot_count INT UNSIGNED NOT NULL DEFAULT 0,
            closing_count INT UNSIGNED NOT NULL DEFAULT 0,
            wawancara_count INT UNSIGNED NOT NULL DEFAULT 0,
            belum_herreg_count INT UNSIGNED NOT NULL DEFAULT 0,
            herreg_count INT UNSIGNED NOT NULL DEFAULT 0,
            fu_hari_ini_count INT UNSIGNED NOT NULL DEFAULT 0,
            raw_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bdc_snapshot_user_day (snapshot_date, nik),
            INDEX idx_bdc_snapshot_wilayah (wilayah),
            INDEX idx_bdc_snapshot_name (name),
            INDEX idx_bdc_snapshot_fetched (fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_activity_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NULL,
            area VARCHAR(40) NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            actor_role VARCHAR(40) NOT NULL,
            actor_actual_role VARCHAR(40) NULL,
            actor_view_role VARCHAR(40) NULL,
            actor_name VARCHAR(180) NULL,
            impersonator_user_id INT UNSIGNED NULL,
            impersonator_name VARCHAR(180) NULL,
            action_name VARCHAR(120) NOT NULL,
            old_status VARCHAR(60) NULL,
            new_status VARCHAR(60) NULL,
            note TEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rsm_logs_report (report_id),
            INDEX idx_rsm_logs_area (area),
            CONSTRAINT fk_rsm_logs_report
                FOREIGN KEY (report_id) REFERENCES rsm_reports(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    rsm_add_column_if_missing('rsm_activity_logs', 'actor_user_id', 'INT UNSIGNED NULL AFTER area');
    rsm_add_column_if_missing('rsm_activity_logs', 'actor_actual_role', 'VARCHAR(40) NULL AFTER actor_role');
    rsm_add_column_if_missing('rsm_activity_logs', 'actor_view_role', 'VARCHAR(40) NULL AFTER actor_actual_role');
    rsm_add_column_if_missing('rsm_activity_logs', 'impersonator_user_id', 'INT UNSIGNED NULL AFTER actor_name');
    rsm_add_column_if_missing('rsm_activity_logs', 'impersonator_name', 'VARCHAR(180) NULL AFTER impersonator_user_id');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_collab_report_archive (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(80) NOT NULL,
            report_month VARCHAR(7) NOT NULL,
            payload LONGTEXT NOT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_collab_report_archive_scope (report_name, report_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsm_collab_daily_metrics (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(80) NOT NULL,
            metric_date DATE NOT NULL,
            entity_key VARCHAR(191) NOT NULL,
            staff_nik VARCHAR(80) NULL,
            staff_name VARCHAR(180) NULL,
            regional VARCHAR(120) NULL,
            campus_name VARCHAR(180) NULL,
            value DECIMAL(12,2) NOT NULL DEFAULT 0,
            synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsm_collab_daily_metrics (report_name, metric_date, entity_key),
            INDEX idx_rsm_collab_daily_metrics_lookup (report_name, metric_date, regional)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function rsm_seed_users(): void
{
    $passwordHash = password_hash('kptsukses', PASSWORD_DEFAULT);
    $pdo = rsm_pdo();

    $specialUsers = [
        ['Ahmad Humaidi', 'SG.0202.2014', 'super_user', 'Super User', 'Regional 7', 'Regional B', 'ITB STIKOM Bali'],
        ['Nugroho Budi Santoso', 'SG.0631.2021', 'koordinator', 'Koordinator Regional 7', 'Regional 7', 'Regional B', 'POLNAS'],
        ['Hamaruddin', null, 'koordinator', 'Koordinator Regional 4', 'Regional 4', 'Regional B', null],
        ['Kundi Harto', null, 'koordinator', 'Koordinator Regional 5', 'Regional 5', 'Regional B', null],
        ['M. Nor Abidin', null, 'koordinator', 'Koordinator Regional 6', 'Regional 6', 'Regional B', null],
    ];

    foreach ($specialUsers as $user) {
        rsm_upsert_user($user[0], $user[1], $user[2], $user[3], $user[4], $user[5], $user[6], $passwordHash);
    }

    // Staff master is synchronized from Collab achievement sources, not historical activities.
}

function rsm_upsert_user(string $name, ?string $nik, string $role, string $jabatan, ?string $regional, ?string $area, ?string $campus, string $passwordHash): void
{
    $username = rsm_username_from_nik_or_name($nik, $name);
    if (rsm_is_username_deleted($username)) {
        return;
    }
    $stmt = rsm_pdo()->prepare('SELECT id FROM rsm_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        if ($role !== 'staff') {
            rsm_pdo()->prepare('UPDATE rsm_users SET role = ?, jabatan = ?, regional = ?, area = ?, campus_name = COALESCE(NULLIF(campus_name, ""), ?), is_active = 1, updated_at = NOW() WHERE id = ?')
                ->execute([$role, $jabatan, $regional, $area, $campus, $existingId]);
        }
        return;
    }

    rsm_pdo()->prepare(
        'INSERT INTO rsm_users (name, nik, username, password_hash, role, jabatan, regional, area, campus_name, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$name, $nik, $username, $passwordHash, $role, $jabatan, $regional, $area, $campus]);
}

function rsm_is_username_deleted(string $username): bool
{
    $stmt = rsm_pdo()->prepare('SELECT COUNT(*) FROM rsm_deleted_usernames WHERE username = ?');
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn() > 0;
}

function rsm_username_from_nik_or_name(?string $nik, string $name): string
{
    if ($nik !== null && trim($nik) !== '') {
        return str_replace('.', '', trim($nik));
    }
    $username = strtolower(trim($name));
    $username = preg_replace('/[^a-z0-9]+/i', '', $username) ?: $username;
    return $username !== '' ? $username : 'rsmuser';
}

function rsm_area_for_regional(string $regional): ?string
{
    if (in_array($regional, ['Regional 1', 'Regional 2', 'Regional 3'], true)) {
        return 'Regional A';
    }
    if (in_array($regional, ['Regional 4', 'Regional 5', 'Regional 6', 'Regional 7'], true)) {
        return 'Regional B';
    }
    return null;
}

function rsm_add_column_if_missing(string $table, string $column, string $definition): void
{
    $stmt = rsm_pdo()->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        rsm_pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function rsm_ensure_user_role_enum(): void
{
    $stmt = rsm_pdo()->prepare(
        "SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'rsm_users'
           AND COLUMN_NAME = 'role'"
    );
    $stmt->execute();
    $columnType = (string) $stmt->fetchColumn();
    if (!str_contains($columnType, "'executive_director'") || !str_contains($columnType, "'director'") || !str_contains($columnType, "'super_user'")) {
        rsm_pdo()->exec("ALTER TABLE rsm_users MODIFY role ENUM('super_user','executive_director','director','senior','mentor','koordinator','staff') NOT NULL DEFAULT 'staff'");
    }
}

function rsm_input(string $key, string $fallback = ''): string
{
    return trim((string) ($_POST[$key] ?? $fallback));
}

function rsm_month_name_id(int $month): string
{
    $names = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    return $names[$month] ?? date('F');
}

function rsm_default_ad_period(string $date = ''): string
{
    $timestamp = $date !== '' ? strtotime($date) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    return rsm_month_name_id((int) date('n', $timestamp)) . ' ' . date('Y', $timestamp);
}

function rsm_ad_period_options(int $monthsBack = 11, int $monthsForward = 1): array
{
    $options = [];
    $base = new DateTimeImmutable('first day of this month');
    for ($offset = $monthsForward; $offset >= -$monthsBack; $offset--) {
        $target = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' months');
        $options[] = rsm_month_name_id((int) $target->format('n')) . ' ' . $target->format('Y');
    }

    return $options;
}

function rsm_number_input(string $key): float
{
    $value = preg_replace('/[^0-9,.-]/', '', rsm_input($key)) ?? '';
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float) $value : 0.0;
}

function rsm_current_actor(string $role): array
{
    $user = rsm_auth_user();
    if ($user) {
        return [
            'role' => (string) $user['role'],
            'name' => (string) $user['name'],
        ];
    }

    $labels = [
        'super_user' => 'Super User',
        'executive_director' => 'Executive Director',
        'director' => 'Director',
        'senior' => 'Senior Manager',
        'mentor' => 'Mentor',
        'koordinator' => 'Koordinator Wilayah',
        'staff' => 'Staff Unit',
    ];

    return [
        'role' => $role,
        'name' => $labels[$role] ?? 'User RSM',
    ];
}

function rsm_seed_campus_master(): void
{
    $pdo = rsm_pdo();
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM rsm_campus_master WHERE area = 'Regional B'")->fetchColumn();
    if ($exists > 0) {
        return;
    }

    $rows = [
        ['Regional 4', 'Universitas Hamzanwadi', 'UHAMZAH', 'UHAMZAH', 'Universitas Hamzanwadi; Hamzanwadi'],
        ['Regional 4', 'UIN Al-Azhaar Lubuklinggau', 'UIN Al-Azhaar Lubuklinggau', 'UINAZ', 'UIN Al Azhaar; UIN Al-Azhaar'],
        ['Regional 4', 'Universitas Taman Siswa', 'UTS', 'UTS', 'Universitas Taman Siswa; Taman Siswa'],
        ['Regional 4', 'Universitas Ubudiyah Indonesia', 'UUI', 'UUI', 'Universitas Ubudiyah Indonesia (UUI)'],
        ['Regional 4', 'Universitas Patria Artha', 'Universitas Patria Artha', 'UPA', 'Universitas Patria Artha (UPA)'],
        ['Regional 5', 'Universitas AKI Semarang', 'UNAKI', 'UNAKI', 'Universitas AKI; Universitas AKI Semarang'],
        ['Regional 5', 'Institut Manajemen dan Administrasi Yogyakarta', 'IMA', 'IMA', 'IMA Yogyakarta'],
        ['Regional 5', 'Universitas Ivet', 'IVET Semarang', 'IVET', 'Universitas Ivet; IVET'],
        ['Regional 5', 'STBA LIA Yogyakarta', 'STBA Lia Yogyakarta', 'STBA LIA', 'STBA Lia; STBA LIA Yogyakarta'],
        ['Regional 5', 'Universitas Boyolali', 'UBY', 'UBY', 'Universitas Boyolali'],
        ['Regional 5', 'Universitas Darul Ulum Islamic Centre Sudirman', 'UNDARIS', 'UNDARIS', 'UNDARIS Semarang'],
        ['Regional 5', 'Universitas Nahdlatul Ulama Al Ghazali Cilacap', 'UNUGHA Cilacap', 'UNUGHA', 'UNUGHA; UNU Al Ghazali Cilacap'],
        ['Regional 6', 'IKIP Widya Darma Surabaya', 'IKIP Widya Darma', 'IKIP WD', 'Institut Keguruan dan Ilmu Pendidikan Widya Darma Surabaya (IKIP) [IWD]'],
        ['Regional 6', 'Universitas Muhammadiyah Surabaya', 'UM Surabaya', 'UMS', 'Universitas Muhammadiyah Surabaya (UM Surabaya) [UMS]'],
        ['Regional 6', 'Universitas Gresik', 'UNIGRES', 'GRS', 'Universitas Gresik (UNIGRES) [GRS]'],
        ['Regional 6', 'Universitas Widya Kartika', 'UWK Surabaya', 'UWK', 'Universitas Widya Kartika; Universitas Widya Kartika (UWIKA) [UWK]'],
        ['Regional 6', 'STIESIA Surabaya', 'STIESIA Surabaya', 'SIA', 'Sekolah Tinggi Ilmu Ekonomi Indonesia (STIESIA) [SIA]'],
        ['Regional 6', 'STIE Artha Bodhi Iswara', 'STIE ABI', 'SAS', 'Sekolah Tinggi Ilmu Ekonomi Artha Bodhi Iswara (STIE ABI) [SAS]'],
        ['Regional 7', 'Institut Teknologi dan Bisnis STIKOM Bali', 'ITB STIKOM Bali', 'STIKOM', 'STIKOM Bali; ITB STIKOM Bali'],
        ['Regional 7', 'Universitas Bhinneka Nusantara', 'UNIBHINUS', 'UNIBHINUS', 'Universitas Bhinneka Nusantara (UNIBHINUS)'],
        ['Regional 7', 'Universitas Bakti Indonesia Banyuwangi', 'UNIBABWI', 'UNIBABWI', 'Universitas Bakti Indonesia; UNIBABWI'],
        ['Regional 7', 'Universitas Mochammad Sroedji Jember', 'Universitas Mochammad Sroedji', 'UMSJ', 'Universitas Mochammad Sroedji Jember; UMSJ'],
        ['Regional 7', 'Politeknik Nasional Denpasar', 'POLNAS', 'POLNAS', 'Politeknik Nasional Denpasar (POLNAS)'],
        ['Regional 7', 'Universitas Kahuripan Kediri', 'UKK Kediri', 'UKK', 'Universitas Kahuripan Kediri (UKK Kediri)'],
        ['Regional 7', 'Universitas Nahdlatul Ulama Kalimantan Timur', 'UNU KALTIM', 'UNU KALTIM', 'Universitas Nahdlatul Ulama Kalimantan Timur (UNU KALTIM)'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO rsm_campus_master
            (area, regional, official_name, display_name, campus_code, aliases, partner_status, source_name)
         VALUES
            ("Regional B", ?, ?, ?, ?, ?, "Aktif", "Seed awal RSM")'
    );
    foreach ($rows as $row) {
        $stmt->execute($row);
    }
}

function rsm_master_campuses(string $area): array
{
    $sql = "SELECT cm.*,
              COALESCE(NULLIF(cm.pic_staff_name, ''), NULLIF(u.name, '')) AS resolved_pic_name,
              COALESCE(NULLIF(cm.pic_nik, ''), NULLIF(u.nik, '')) AS resolved_pic_nik,
              COALESCE(NULLIF(cm.pic_phone, ''), NULLIF(u.phone_number, '')) AS resolved_pic_phone
            FROM rsm_campus_master cm
            LEFT JOIN rsm_users u
              ON u.area = cm.area
             AND u.role = 'staff'
             AND u.is_active = 1
             AND (
                NULLIF(cm.pic_nik, '') = u.nik
                OR (NULLIF(cm.pic_staff_name, '') IS NOT NULL AND LOWER(cm.pic_staff_name) = LOWER(u.name))
                OR LOWER(cm.display_name) = LOWER(u.campus_name)
                OR LOWER(cm.official_name) = LOWER(u.campus_name)
             )
            WHERE cm.area = ?
            GROUP BY cm.id
            ORDER BY cm.regional ASC, cm.display_name ASC";
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute([$area]);
    return $stmt->fetchAll();
}

function rsm_save_master_campus(string $area, array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior', 'mentor'], true)) {
        throw new RuntimeException('Hanya Senior Manager atau Mentor yang bisa mengubah master kampus.');
    }

    $id = (int) rsm_input('campus_master_id');
    $regional = rsm_input('regional');
    $officialName = rsm_input('official_name');
    $displayName = rsm_input('display_name');
    $campusCode = rsm_input('campus_code');
    $aliases = rsm_input('aliases');
    $picStaffName = rsm_input('pic_staff_name');
    $picNik = rsm_input('pic_nik');
    $picPhone = rsm_input('pic_phone');
    $partnerStatus = rsm_input('partner_status', 'Aktif');
    $sourceName = rsm_input('source_name');
    $sourceUrl = rsm_input('source_url');
    $notes = rsm_input('notes');
    $isActive = rsm_input('is_active', '1') === '1' ? 1 : 0;

    if ($regional === '' || $officialName === '' || $displayName === '') {
        throw new InvalidArgumentException('Regional, nama resmi, dan nama tampil pendek wajib diisi.');
    }
    if (!in_array($regional, rsm_area_regionals($area), true)) {
        throw new InvalidArgumentException('Regional tidak sesuai area aktif.');
    }

    if ($id > 0) {
        rsm_pdo()->prepare(
            'UPDATE rsm_campus_master
             SET regional = ?, official_name = ?, display_name = ?, campus_code = ?, aliases = ?,
                 pic_staff_name = ?, pic_nik = ?, pic_phone = ?, partner_status = ?,
                 source_name = ?, source_url = ?, notes = ?, is_active = ?, updated_at = NOW()
             WHERE id = ? AND area = ?'
        )->execute([
            $regional,
            $officialName,
            $displayName,
            $campusCode !== '' ? $campusCode : null,
            $aliases !== '' ? $aliases : null,
            $picStaffName !== '' ? $picStaffName : null,
            $picNik !== '' ? $picNik : null,
            $picPhone !== '' ? $picPhone : null,
            $partnerStatus !== '' ? $partnerStatus : 'Aktif',
            $sourceName !== '' ? $sourceName : null,
            $sourceUrl !== '' ? $sourceUrl : null,
            $notes !== '' ? $notes : null,
            $isActive,
            $id,
            $area,
        ]);
        return;
    }

    rsm_pdo()->prepare(
        'INSERT INTO rsm_campus_master
            (area, regional, official_name, display_name, campus_code, aliases, pic_staff_name, pic_nik, pic_phone, partner_status, source_name, source_url, notes, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $area,
        $regional,
        $officialName,
        $displayName,
        $campusCode !== '' ? $campusCode : null,
        $aliases !== '' ? $aliases : null,
        $picStaffName !== '' ? $picStaffName : null,
        $picNik !== '' ? $picNik : null,
        $picPhone !== '' ? $picPhone : null,
        $partnerStatus !== '' ? $partnerStatus : 'Aktif',
        $sourceName !== '' ? $sourceName : null,
        $sourceUrl !== '' ? $sourceUrl : null,
        $notes !== '' ? $notes : null,
        $isActive,
    ]);
}

function rsm_delete_master_campus(string $area, array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior', 'mentor'], true)) {
        throw new RuntimeException('Hanya Senior Manager atau Mentor yang bisa menghapus master kampus.');
    }
    $id = (int) rsm_input('campus_master_id');
    if ($id <= 0) {
        throw new InvalidArgumentException('Data kampus tidak valid.');
    }
    rsm_pdo()->prepare('DELETE FROM rsm_campus_master WHERE id = ? AND area = ?')->execute([$id, $area]);
}

function rsm_auth_user(): ?array
{
    $id = (int) ($_SESSION['rsm_user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function rsm_user_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function rsm_impersonation_source(): ?array
{
    $id = (int) ($_SESSION['rsm_original_user_id'] ?? 0);
    return $id > 0 ? rsm_user_by_id($id) : null;
}

function rsm_admin_actor(): ?array
{
    return rsm_impersonation_source() ?: rsm_auth_user();
}

function rsm_can_impersonate(?array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior'], true);
}

function rsm_can_manage_targets(?array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true);
}

function rsm_can_sync_collab(?array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true);
}

function rsm_can_manage_ad_budget(?array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['super_user', 'senior'], true);
}

function rsm_impersonate_user(string $area, int $targetUserId): void
{
    $actor = rsm_admin_actor();
    if (!rsm_can_impersonate($actor)) {
        throw new RuntimeException('Hanya Senior Manager yang bisa masuk sebagai user lain.');
    }
    $target = rsm_user_by_id($targetUserId);
    if (!$target) {
        throw new InvalidArgumentException('User tujuan tidak ditemukan atau tidak aktif.');
    }
    if (!empty($target['area']) && (string) $target['area'] !== $area) {
        throw new RuntimeException('User tujuan berada di luar area ini.');
    }
    if ((int) ($target['id'] ?? 0) === (int) ($actor['id'] ?? 0)) {
        rsm_stop_impersonation();
        return;
    }
    if (empty($_SESSION['rsm_original_user_id'])) {
        $_SESSION['rsm_original_user_id'] = (int) ($actor['id'] ?? 0);
    }
    $_SESSION['rsm_user_id'] = (int) $target['id'];
}

function rsm_stop_impersonation(): void
{
    $originalId = (int) ($_SESSION['rsm_original_user_id'] ?? 0);
    if ($originalId > 0) {
        $_SESSION['rsm_user_id'] = $originalId;
    }
    unset($_SESSION['rsm_original_user_id']);
}

function rsm_login(string $username, string $password): bool
{
    $username = trim($username);
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['rsm_user_id'] = (int) $user['id'];
    unset($_SESSION['rsm_original_user_id']);
    return true;
}

function rsm_logout(): void
{
    unset($_SESSION['rsm_user_id']);
    unset($_SESSION['rsm_original_user_id']);
}

function rsm_change_password(int $userId, string $oldPassword, string $newPassword, string $confirmPassword): void
{
    if (strlen($newPassword) < 6) {
        throw new InvalidArgumentException('Password baru minimal 6 karakter.');
    }
    if ($newPassword !== $confirmPassword) {
        throw new InvalidArgumentException('Konfirmasi password tidak sama.');
    }

    $stmt = rsm_pdo()->prepare('SELECT password_hash FROM rsm_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hash = (string) $stmt->fetchColumn();
    if ($hash === '' || !password_verify($oldPassword, $hash)) {
        throw new InvalidArgumentException('Password lama tidak sesuai.');
    }

    rsm_pdo()->prepare('UPDATE rsm_users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
}

function rsm_update_profile(array $actor): void
{
    $userId = (int) ($actor['id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('User aktif tidak ditemukan.');
    }

    $bio = trim((string) ($_POST['bio_text'] ?? ''));
    if (strlen($bio) > 800) {
        throw new InvalidArgumentException('Biodata singkat maksimal 800 karakter.');
    }

    $photoPath = rsm_profile_photo_upload($userId);
    if ($photoPath !== null) {
        rsm_pdo()->prepare('UPDATE rsm_users SET bio_text = ?, photo_path = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$bio !== '' ? $bio : null, $photoPath, $userId]);
        return;
    }

    rsm_pdo()->prepare('UPDATE rsm_users SET bio_text = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$bio !== '' ? $bio : null, $userId]);
}

function rsm_profile_photo_upload(int $userId): ?string
{
    $file = $_FILES['profile_photo'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto profil gagal.');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran foto maksimal 2 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmpName);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Foto profil harus berupa JPG, PNG, atau WEBP.');
    }

    $uploadDir = __DIR__ . '/runtime/uploads/profile';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $relativePath = 'runtime/uploads/profile/user-' . $userId . '-' . date('Ymd-His') . '.' . $extensions[$mime];
    $target = __DIR__ . '/' . $relativePath;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Foto profil tidak bisa disimpan.');
    }

    return $relativePath;
}

function rsm_report_attachment_upload(int $reportId): ?string
{
    $file = $_FILES['attachment_path'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload bukti invoice/screenshot gagal.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran bukti invoice/screenshot maksimal 5 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'bukti');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $mime = (string) (@mime_content_type($tmpName) ?: '');
    if (!in_array($extension, $allowedExtensions, true) || !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('Bukti invoice/screenshot wajib JPG, PNG, WEBP, atau PDF.');
    }

    $uploadDir = __DIR__ . '/runtime/uploads/attachments';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $safeName = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'bukti';
    $relativePath = 'runtime/uploads/attachments/report-' . $reportId . '-' . date('Ymd-His') . '-' . $safeName . '.' . $extension;
    $target = __DIR__ . '/' . $relativePath;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Bukti invoice/screenshot tidak bisa disimpan.');
    }

    return $relativePath;
}

function rsm_schedule_attachment_upload(int $scheduleId): ?string
{
    $file = $_FILES['schedule_attachment'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload dokumentasi aktivitas gagal.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran dokumentasi aktivitas maksimal 5 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'dokumentasi');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $mime = (string) (@mime_content_type($tmpName) ?: '');
    if (!in_array($extension, $allowedExtensions, true) || !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('Dokumentasi aktivitas wajib JPG, PNG, WEBP, atau PDF.');
    }

    $uploadDir = __DIR__ . '/runtime/uploads/schedules';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $safeName = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'dokumentasi';
    $relativePath = 'runtime/uploads/schedules/schedule-' . $scheduleId . '-' . date('Ymd-His') . '-' . $safeName . '.' . $extension;
    $target = __DIR__ . '/' . $relativePath;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Dokumentasi aktivitas tidak bisa disimpan.');
    }

    return $relativePath;
}

function rsm_add_unique_coordinator(array &$coordinators, string $regional, array $user): void
{
    $nameKey = mb_strtolower(trim((string) ($user['name'] ?? '')));
    if ($nameKey === '') {
        $coordinators[$regional][] = $user;
        return;
    }
    foreach ($coordinators[$regional] ?? [] as $index => $existing) {
        if (mb_strtolower(trim((string) ($existing['name'] ?? ''))) !== $nameKey) {
            continue;
        }
        $existingHasPhoto = trim((string) ($existing['photo_path'] ?? '')) !== '';
        $newHasPhoto = trim((string) ($user['photo_path'] ?? '')) !== '';
        if (!$existingHasPhoto && $newHasPhoto) {
            $coordinators[$regional][$index] = $user;
        }
        return;
    }
    $coordinators[$regional][] = $user;
}

function rsm_users(string $area, bool $activeOnly = false): array
{
    $sql = $activeOnly ? "SELECT * FROM rsm_users WHERE is_active = 1" : "SELECT * FROM rsm_users WHERE is_active IN (0,1)";
    $params = [];
    if ($area !== 'Regional') {
        $sql .= " AND (area = ? OR area IS NULL OR role IN ('super_user','executive_director','director','senior'))";
        $params[] = $area;
    }
    $sql .= " ORDER BY FIELD(role, 'super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator', 'staff'), regional ASC, name ASC";
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_normalize_nik_key(?string $nik): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $nik) ?? '');
}

function rsm_sync_user_contact_reference(string $area = 'Regional B'): int
{
    $references = rsm_closing_collab_user_reference($area);
    if ($references === []) {
        return 0;
    }

    $stmt = rsm_pdo()->prepare(
        "UPDATE rsm_users
         SET phone_number = COALESCE(NULLIF(?, ''), phone_number),
             work_duration = COALESCE(NULLIF(?, ''), work_duration),
             updated_at = NOW()
         WHERE (
             REPLACE(REPLACE(REPLACE(UPPER(COALESCE(nik, '')), '.', ''), ',', ''), ' ', '') = ?
             OR REPLACE(REPLACE(REPLACE(UPPER(COALESCE(username, '')), '.', ''), ',', ''), ' ', '') = ?
         )
           AND (area = ? OR area IS NULL OR regional IN ('Regional 4','Regional 5','Regional 6','Regional 7'))"
    );

    $updated = 0;
    foreach ($references as $nikKey => $reference) {
        $stmt->execute([
            (string) ($reference['phone_number'] ?? ''),
            (string) ($reference['work_duration'] ?? ''),
            $nikKey,
            $nikKey,
            $area,
        ]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

function rsm_closing_collab_user_reference(string $area = 'Regional B'): array
{
    $report = rsm_collab_report_from_url('Closing Collab');
    if ($report === []) {
        $report = rsm_collab_report('Closing Collab');
    }
    $tables = $report['tables'] ?? [];
    if (!is_array($tables) || $tables === []) {
        return [];
    }

    $references = [];
    $allowedRegionals = rsm_area_regionals($area);
    foreach ($tables as $table) {
        foreach ((array) $table as $row) {
            if (!is_array($row) || count($row) < 8) {
                continue;
            }
            $row = array_values(array_map(static fn (mixed $value): string => trim((string) $value), $row));
            $nikIndexes = [];
            foreach ($row as $index => $value) {
                if (preg_match('/^SG[.\d-]+$/i', $value)) {
                    $nikIndexes[] = (int) $index;
                }
            }
            if ($nikIndexes === []) {
                continue;
            }
            $nikIndex = max($nikIndexes);

            $nik = $row[$nikIndex] ?? '';
            $name = $row[$nikIndex + 1] ?? '';
            $regional = '';
            foreach ($row as $value) {
                if (preg_match('/^[1-7]$/', $value)) {
                    $regional = 'Regional ' . $value;
                    break;
                }
            }
            if ($allowedRegionals !== [] && $regional !== '' && !in_array($regional, $allowedRegionals, true)) {
                continue;
            }

            $nikKey = rsm_normalize_nik_key($nik);
            if ($nikKey === '' || $name === '' || !str_starts_with($nikKey, 'SG')) {
                continue;
            }

            $phone = '';
            $workDuration = '';
            for ($i = count($row) - 1; $i >= 0; $i--) {
                $value = trim((string) ($row[$i] ?? ''));
                if ($workDuration === '' && (preg_match('/\bTahun\b/i', $value) || $value === '-')) {
                    $workDuration = $value;
                    continue;
                }
                if ($phone === '' && preg_match('/^(?:\+?62|0)[0-9\s-]{6,}$/', $value)) {
                    $phone = $value;
                }
                if ($phone !== '' && $workDuration !== '') {
                    break;
                }
            }

            $references[$nikKey] = [
                'nik' => $nik,
                'name' => $name,
                'regional' => $regional,
                'phone_number' => $phone,
                'work_duration' => $workDuration,
            ];
        }
    }

    return $references;
}

function rsm_sync_users_from_collab(string $area): array
{
    $performance = rsm_collab_staff_performance($area, [], null);
    $rows = $performance['rows'] ?? [];
    $passwordHash = password_hash('kptsukses', PASSWORD_DEFAULT);
    $seenUsernames = [];
    $inserted = 0;
    $updated = 0;
    $reactivated = 0;

    $findByName = rsm_pdo()->prepare("SELECT id, is_active FROM rsm_users WHERE LOWER(name) = LOWER(?) AND role = 'staff' LIMIT 1");
    $findByUsername = rsm_pdo()->prepare("SELECT id, is_active FROM rsm_users WHERE username = ? LIMIT 1");
    $insert = rsm_pdo()->prepare(
        'INSERT INTO rsm_users (name, nik, username, password_hash, role, jabatan, regional, area, campus_name, must_change_password, is_active)
         VALUES (?, ?, ?, ?, "staff", "Staff Unit", ?, ?, NULL, 1, 1)'
    );
    $update = rsm_pdo()->prepare(
        'UPDATE rsm_users
         SET name = ?, nik = ?, role = "staff", jabatan = "Staff Unit", regional = ?, area = ?, is_active = 1, updated_at = NOW()
         WHERE id = ?'
    );

    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $nik = trim((string) ($row['nik'] ?? ''));
        $regional = trim((string) ($row['regional'] ?? ''));
        if ($name === '' || $regional === '') {
            continue;
        }
        $userArea = rsm_area_for_regional($regional) ?: $area;
        if ($userArea !== $area) {
            continue;
        }

        $username = rsm_username_from_nik_or_name($nik !== '' ? $nik : null, $name);
        if ($username === '' || rsm_deleted_username_exists($username)) {
            continue;
        }
        $seenUsernames[] = $username;

        $findByUsername->execute([$username]);
        $existing = $findByUsername->fetch();
        if (!$existing && $nik === '') {
            $findByName->execute([$name]);
            $existing = $findByName->fetch();
        }

        if ($existing) {
            $update->execute([$name, $nik !== '' ? $nik : null, $regional, $userArea, (int) $existing['id']]);
            $updated++;
            if ((int) ($existing['is_active'] ?? 0) === 0) {
                $reactivated++;
            }
            continue;
        }

        $insert->execute([$name, $nik !== '' ? $nik : null, $username, $passwordHash, $regional, $userArea]);
        $inserted++;
    }

    $deactivated = 0;
    $seenUsernames = array_values(array_unique(array_filter($seenUsernames)));
    if ($seenUsernames !== []) {
        $placeholders = implode(',', array_fill(0, count($seenUsernames), '?'));
        $params = [$area];
        $allowedRegionals = rsm_area_regionals($area);
        $regionalSql = '';
        if ($allowedRegionals !== []) {
            $regionalSql = ' OR regional IN (' . implode(',', array_fill(0, count($allowedRegionals), '?')) . ')';
            $params = array_merge($params, $allowedRegionals);
        }
        $params = array_merge($params, $seenUsernames);
        $stmt = rsm_pdo()->prepare("UPDATE rsm_users SET is_active = 0, updated_at = NOW() WHERE role = 'staff' AND (area = ? OR area IS NULL{$regionalSql}) AND username NOT IN ({$placeholders})");
        $stmt->execute($params);
        $deactivated = $stmt->rowCount();
    }
    $contactUpdated = rsm_sync_user_contact_reference($area);

    return [
        'collab_staff' => count($seenUsernames),
        'inserted' => $inserted,
        'updated' => $updated,
        'reactivated' => $reactivated,
        'deactivated' => $deactivated,
        'contact_updated' => $contactUpdated,
    ];
}

function rsm_admin_save_user(string $area, array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior'], true)) {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengelola user.');
    }

    $name = rsm_input('name');
    $nik = rsm_input('nik');
    $username = rsm_input('username');
    $role = rsm_input('user_role', 'staff');
    $jabatan = rsm_jabatan_from_role($role);
    $regional = rsm_input('regional');
    $userArea = rsm_input('area', $area);
    $campus = rsm_input('campus_name');
    $phoneNumber = rsm_input('phone_number');
    $workDuration = rsm_input('work_duration');
    $password = rsm_input('new_user_password', 'kptsukses');

    if ($name === '' || $username === '' || $role === '') {
        throw new InvalidArgumentException('Nama, username, dan role wajib diisi.');
    }
    if (!in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator', 'staff'], true)) {
        throw new InvalidArgumentException('Role user tidak valid.');
    }
    if (strlen($password) < 6) {
        throw new InvalidArgumentException('Password minimal 6 karakter.');
    }

    $stmt = rsm_pdo()->prepare('SELECT id FROM rsm_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('Username sudah digunakan.');
    }

    rsm_pdo()->prepare(
        'INSERT INTO rsm_users (name, nik, username, password_hash, role, jabatan, regional, area, campus_name, phone_number, work_duration, must_change_password, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
    )->execute([
        $name,
        $nik !== '' ? $nik : null,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        $jabatan,
        $regional !== '' ? $regional : null,
        $userArea !== '' ? $userArea : null,
        $campus !== '' ? $campus : null,
        $phoneNumber !== '' ? $phoneNumber : null,
        $workDuration !== '' ? $workDuration : null,
    ]);
}

function rsm_admin_update_user(array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior'], true)) {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengedit user.');
    }

    $userId = (int) rsm_input('user_id');
    $name = rsm_input('name');
    $nik = rsm_input('nik');
    $username = rsm_input('username');
    $role = rsm_input('user_role', 'staff');
    $jabatan = rsm_jabatan_from_role($role);
    $regional = rsm_input('regional');
    $area = rsm_input('area');
    $campus = rsm_input('campus_name');
    $phoneNumber = rsm_input('phone_number');
    $workDuration = rsm_input('work_duration');

    if ($userId <= 0 || $name === '' || $username === '') {
        throw new InvalidArgumentException('Data edit user belum lengkap.');
    }
    if (!in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator', 'staff'], true)) {
        throw new InvalidArgumentException('Role user tidak valid.');
    }

    $stmt = rsm_pdo()->prepare('SELECT id FROM rsm_users WHERE username = ? AND id <> ? LIMIT 1');
    $stmt->execute([$username, $userId]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('Username sudah digunakan user lain.');
    }

    rsm_pdo()->prepare(
        'UPDATE rsm_users
         SET name = ?, nik = ?, username = ?, role = ?, jabatan = ?, regional = ?, area = ?, campus_name = ?, phone_number = ?, work_duration = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([
        $name,
        $nik !== '' ? $nik : null,
        $username,
        $role,
        $jabatan,
        $regional !== '' ? $regional : null,
        $area !== '' ? $area : null,
        $campus !== '' ? $campus : null,
        $phoneNumber !== '' ? $phoneNumber : null,
        $workDuration !== '' ? $workDuration : null,
        $userId,
    ]);
}

function rsm_jabatan_from_role(string $role): string
{
    return [
        'super_user' => 'Super User',
        'executive_director' => 'Executive Director',
        'director' => 'Director',
        'senior' => 'Senior Manager',
        'mentor' => 'Mentor',
        'koordinator' => 'Koordinator Wilayah',
        'staff' => 'Staff Unit',
    ][$role] ?? 'Staff Unit';
}

function rsm_admin_reset_user_password(array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior'], true)) {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa reset password user.');
    }
    $userId = (int) rsm_input('user_id');
    $password = rsm_input('new_password');
    if ($userId <= 0 || strlen($password) < 6) {
        throw new InvalidArgumentException('User dan password baru wajib diisi minimal 6 karakter.');
    }

    rsm_pdo()->prepare('UPDATE rsm_users SET password_hash = ?, must_change_password = 1, updated_at = NOW() WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
}

function rsm_admin_delete_user(array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior'], true)) {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa menghapus user.');
    }
    $userId = (int) rsm_input('user_id');
    if ($userId <= 0) {
        throw new InvalidArgumentException('User tidak valid.');
    }
    if ($userId === (int) ($actor['id'] ?? 0)) {
        throw new InvalidArgumentException('User aktif tidak bisa menghapus akunnya sendiri.');
    }
    $stmt = rsm_pdo()->prepare('SELECT username, name, role FROM rsm_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $deletedUser = $stmt->fetch();
    if ($deletedUser) {
        rsm_pdo()->prepare(
            'INSERT INTO rsm_deleted_usernames (username, deleted_name, deleted_role, deleted_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE deleted_name = VALUES(deleted_name), deleted_role = VALUES(deleted_role), deleted_by_user_id = VALUES(deleted_by_user_id), deleted_at = NOW()'
        )->execute([
            (string) ($deletedUser['username'] ?? ''),
            (string) ($deletedUser['name'] ?? ''),
            (string) ($deletedUser['role'] ?? ''),
            (int) ($actor['id'] ?? 0),
        ]);
    }
    rsm_pdo()->prepare('DELETE FROM rsm_users WHERE id = ?')->execute([$userId]);
}

function rsm_admin_set_user_active(array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'senior'], true)) {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengubah status user.');
    }
    $userId = (int) rsm_input('user_id');
    $active = (int) rsm_input('is_active');
    if ($userId <= 0) {
        throw new InvalidArgumentException('User tidak valid.');
    }
    if ($active) {
        $stmt = rsm_pdo()->prepare('SELECT username FROM rsm_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $username = (string) ($stmt->fetchColumn() ?: '');
        if ($username !== '') {
            rsm_pdo()->prepare('DELETE FROM rsm_deleted_usernames WHERE username = ?')->execute([$username]);
        }
    }
    rsm_pdo()->prepare('UPDATE rsm_users SET is_active = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$active ? 1 : 0, $userId]);
}

function rsm_area_regionals(string $area): array
{
    return $area === 'Regional A'
        ? ['Regional 1', 'Regional 2', 'Regional 3']
        : ($area === 'Regional B' ? ['Regional 4', 'Regional 5', 'Regional 6', 'Regional 7'] : []);
}

function rsm_reference_options(string $area, ?array $user = null, string $effectiveRole = ''): array
{
    $regionals = rsm_area_regionals($area);
    if ($user && (string) ($user['role'] ?? '') === 'staff') {
        $staffRegional = (string) ($user['regional'] ?? '');
        $staffCampus = (string) ($user['campus_name'] ?? '');
        return [
            'regionals' => $staffRegional !== '' ? [$staffRegional] : $regionals,
            'staff' => [[
                'id' => null,
                'name' => (string) ($user['name'] ?? ''),
                'username' => (string) ($user['username'] ?? ''),
                'regional' => $staffRegional,
                'campus_name' => $staffCampus,
            ]],
            'campuses' => $staffCampus !== '' ? [[
                'id' => null,
                'label' => $staffCampus,
                'kode_kampus' => null,
                'regional' => $staffRegional,
            ]] : [],
        ];
    }
    if ($user && (string) ($user['role'] ?? '') === 'staff' && !empty($user['regional'])) {
        $regionals = [(string) $user['regional']];
    }
    if ($user && (string) ($user['role'] ?? '') === 'koordinator' && !empty($user['regional'])) {
        $regionals = [(string) $user['regional']];
    }
    $pdo = rsm_pdo();

    $regionalOptions = $regionals;
    if ($regionalOptions === []) {
        $regionalOptions = $pdo->query("SELECT DISTINCT regional FROM rsm_users WHERE role = 'staff' AND is_active = 1 AND NULLIF(regional, '') IS NOT NULL ORDER BY regional")->fetchAll(PDO::FETCH_COLUMN);
    }

    $params = $regionals;
    $regionalWhere = '';
    if ($regionals !== []) {
        $regionalWhere = 'AND u.regional IN (' . implode(',', array_fill(0, count($regionals), '?')) . ')';
    }

    $staffSql = "SELECT u.id, u.name, u.username, u.regional, u.campus_name
                 FROM rsm_users u
                 WHERE u.role = 'staff'
                   AND u.is_active = 1
                   AND NULLIF(u.name, '') IS NOT NULL
                   {$regionalWhere}
                 ORDER BY u.regional ASC, u.name ASC";
    $stmt = $pdo->prepare($staffSql);
    $stmt->execute($params);
    $staff = $stmt->fetchAll();

    $regionalPlaceholders = $regionals !== [] ? implode(',', array_fill(0, count($regionals), '?')) : '';
    $campusUserWhere = $regionals !== [] ? "AND src.regional IN ({$regionalPlaceholders})" : '';
    $campusUserCampusWhere = $regionals !== [] ? "AND owner.regional IN ({$regionalPlaceholders})" : '';
    $campusParams = array_merge($regionals, $regionals, $params);
    if ($regionals === []) {
        $campusParams = $params;
    }

    $campusSql = "SELECT
                      MIN(id) AS id,
                      label,
                      MIN(kode_kampus) AS kode_kampus,
                      MIN(regional) AS regional
                  FROM (
                      SELECT DISTINCT
                          pc.id AS id,
                          CASE
                              WHEN NULLIF(pc.name, '') IS NOT NULL THEN CONVERT(pc.name USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              WHEN NULLIF(pc.display_name, '') IS NOT NULL THEN CONVERT(pc.display_name USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              ELSE CONVERT(pc.kode_kampus USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          END AS label,
                          CONVERT(pc.kode_kampus USING utf8mb4) COLLATE utf8mb4_unicode_ci AS kode_kampus,
                          CONVERT(src.regional USING utf8mb4) COLLATE utf8mb4_unicode_ci AS regional
                      FROM partner_campuses pc
                      INNER JOIN users src ON src.partner_campus_id = pc.id
                      WHERE NULLIF(COALESCE(pc.name, pc.display_name, pc.kode_kampus), '') IS NOT NULL
                        {$campusUserWhere}
                      UNION
                      SELECT DISTINCT
                          pc.id AS id,
                          CASE
                              WHEN NULLIF(pc.name, '') IS NOT NULL THEN CONVERT(pc.name USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              WHEN NULLIF(pc.display_name, '') IS NOT NULL THEN CONVERT(pc.display_name USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              ELSE CONVERT(pc.kode_kampus USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          END AS label,
                          CONVERT(pc.kode_kampus USING utf8mb4) COLLATE utf8mb4_unicode_ci AS kode_kampus,
                          CONVERT(owner.regional USING utf8mb4) COLLATE utf8mb4_unicode_ci AS regional
                      FROM partner_campuses pc
                      INNER JOIN user_campuses uc ON uc.partner_campus_id = pc.id
                      INNER JOIN users owner ON owner.id = uc.user_id
                      WHERE NULLIF(COALESCE(pc.name, pc.display_name, pc.kode_kampus), '') IS NOT NULL
                        {$campusUserCampusWhere}
                      UNION
                      SELECT DISTINCT
                          NULL AS id,
                          CONVERT(u.campus_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS label,
                          NULL AS kode_kampus,
                          CONVERT(MIN(u.regional) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS regional
                      FROM rsm_users u
                      WHERE u.role = 'staff'
                        AND u.is_active = 1
                        AND NULLIF(u.campus_name, '') IS NOT NULL
                        {$regionalWhere}
                      GROUP BY u.campus_name
                  ) campus_options
                  WHERE NULLIF(label, '') IS NOT NULL
                  GROUP BY label
                  ORDER BY regional IS NULL, regional ASC, label ASC";
    $stmt = $pdo->prepare($campusSql);
    $stmt->execute($campusParams);
    $campuses = array_values(array_filter(
        rsm_canonical_campus_options($stmt->fetchAll()),
        static fn (array $campus): bool => !rsm_is_inactive_campus_label((string) ($campus['label'] ?? ''), (string) ($campus['kode_kampus'] ?? ''))
    ));

    return [
        'regionals' => $regionalOptions,
        'staff' => $staff,
        'campuses' => $campuses,
    ];
}

function rsm_normalize_campus_alias(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function rsm_is_inactive_campus_label(string $label, string $code = ''): bool
{
    $keys = [
        strtoupper(trim($label)),
        strtoupper(trim($code)),
        rsm_normalize_campus_alias($label),
        rsm_normalize_campus_alias($code),
    ];
    $inactiveKeys = ['UCM'];
    foreach ($keys as $key) {
        if ($key !== '' && in_array($key, $inactiveKeys, true)) {
            return true;
        }
    }
    return false;
}

function rsm_master_campus_reference_options(string $area, array $regionals = []): array
{
    $params = [$area];
    $regionalSql = '';
    if ($regionals !== []) {
        $regionalSql = ' AND regional IN (' . implode(',', array_fill(0, count($regionals), '?')) . ')';
        $params = array_merge($params, $regionals);
    }
    $stmt = rsm_pdo()->prepare(
        "SELECT id, display_name AS label, campus_code AS kode_kampus, regional
         FROM rsm_campus_master
         WHERE area = ?
           AND is_active = 1
           {$regionalSql}
         ORDER BY regional ASC, display_name ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_canonical_campus_options(array $rows): array
{
    $result = [];
    $seenKeys = [];
    $pics = rsm_campus_pic_lookup();
    foreach ($rows as $row) {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $cleanLabel = rsm_campus_canonical_label($label);
        $key = rsm_campus_alias_key($cleanLabel);
        if ($key === '') {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $cleanLabel) ?? $cleanLabel);
        }
        if ($key !== '' && isset($seenKeys[$key])) {
            continue;
        }
        if ($key !== '') {
            $seenKeys[$key] = true;
        }
        $row['label'] = $cleanLabel;
        $row['kode_kampus'] = null;
        $pic = $pics[$key] ?? [];
        $row['pic_name'] = (string) ($pic['name'] ?? '');
        $row['pic_phone'] = (string) ($pic['phone_number'] ?? '');
        $result[] = $row;
    }

    usort($result, static fn (array $a, array $b): int => strcmp((string) ($a['regional'] ?? ''), (string) ($b['regional'] ?? '')) ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
    return $result;
}

function rsm_campus_pic_lookup(): array
{
    try {
        $stmt = rsm_pdo()->query(
            "SELECT name, campus_name, phone_number
             FROM rsm_users
             WHERE role = 'staff'
               AND is_active = 1
               AND NULLIF(campus_name, '') IS NOT NULL
             ORDER BY campus_name ASC, name ASC"
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable) {
        return [];
    }

    $lookup = [];
    foreach ($rows as $row) {
        $campus = rsm_campus_canonical_label((string) ($row['campus_name'] ?? ''));
        $key = rsm_campus_alias_key($campus);
        if ($key === '') {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $campus) ?? $campus);
        }
        if ($key === '' || isset($lookup[$key])) {
            continue;
        }
        $lookup[$key] = [
            'name' => (string) ($row['name'] ?? ''),
            'phone_number' => (string) ($row['phone_number'] ?? ''),
        ];
    }

    return $lookup;
}

function rsm_is_campus_alias_of_canonical(string $label, array $canonicalRows): bool
{
    $labelKey = rsm_campus_alias_key($label);
    if ($labelKey === '') {
        return false;
    }
    foreach ($canonicalRows as $canonical) {
        $canonicalLabel = (string) ($canonical['label'] ?? '');
        $canonicalCode = (string) ($canonical['kode_kampus'] ?? '');
        $aliases = array_filter([
            $canonicalLabel,
            $canonicalCode,
            rsm_campus_short_label($canonicalLabel),
        ]);
        foreach ($aliases as $alias) {
            $aliasKey = rsm_campus_alias_key((string) $alias);
            if ($aliasKey !== '' && ($labelKey === $aliasKey || str_contains($labelKey, $aliasKey) || str_contains($aliasKey, $labelKey))) {
                return true;
            }
        }
    }
    return false;
}

function rsm_campus_alias_key(string $label): string
{
    $label = strtolower(trim($label));
    $label = str_replace(['-', '_'], ' ', $label);
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;
    $aliasGroups = [
        'uinaz' => ['universitas islam nusantara al azhaar lubuklinggau', 'universitas islam nusantara al-azhaar lubuklinggau', 'uin al azhaar lubuklinggau', 'uin al-azhaar lubuklinggau', 'inaal', 'uaal', 'uinaz'],
        'ivet' => ['universitas ivet', 'ivet semarang', 'ivet'],
        'uwks' => ['universitas wijaya kusuma surabaya', 'uwk surabaya', 'uwks'],
        'uwika' => ['universitas widya kartika', 'uwika', 'uwk'],
        'uhamzah' => ['uhamzah', 'universitas hamzanwadi', 'universitas hamzah'],
        'stikombali' => ['institut teknologi dan bisnis stikom bali', 'itb stikom bali', 'kom'],
        'polnas' => ['politeknik nasional denpasar', 'polnas', 'pl'],
        'indocakti' => ['stie indocakti', 'sekolah tinggi ilmu ekonomi indocakti malang', 'imsi', 'ict'],
        'ubhinus' => ['universitas bhinneka nusantara', 'ubhinus', 'stk'],
        'ukk' => ['universitas kahuripan kediri', 'ukk', 'kkuk'],
        'umsj' => ['universitas mochammad sroedji jember', 'umsj', 'msj'],
        'ima' => ['ima', 'mhaakb', 'institut miftahul huda al azhar kota banjar'],
        'stbalia' => ['stba lia yogyakarta', 'balysl', 'sekolah tinggi bahasa asing lia yogyakarta'],
        'unaki' => ['universitas aki semarang', 'universitas aki', 'unaki'],
    ];
    foreach ($aliasGroups as $key => $aliases) {
        foreach ($aliases as $alias) {
            if (str_contains($label, $alias)) {
                return $key;
            }
        }
    }
    $label = preg_replace('/\[[^\]]+\]|\([^\)]+\)/', ' ', $label) ?? $label;
    $label = str_replace(['universitas', 'institut', 'sekolah tinggi ilmu ekonomi', 'sekolah tinggi', 'surabaya'], ' ', $label);
    return preg_replace('/[^a-z0-9]+/', '', $label) ?? '';
}

function rsm_campus_canonical_label(string $label): string
{
    $canonical = [
        'uinaz' => 'Universitas Islam Nusantara Al-Azhaar Lubuklinggau',
        'ivet' => 'Universitas IVET',
        'uwks' => 'Universitas Wijaya Kusuma Surabaya',
        'uwika' => 'Universitas Widya Kartika',
        'uhamzah' => 'UHAMZAH',
        'stikombali' => 'Institut Teknologi dan Bisnis STIKOM Bali',
        'polnas' => 'Politeknik Nasional Denpasar',
        'indocakti' => 'STIE INDOCAKTI',
        'ubhinus' => 'Universitas Bhinneka Nusantara',
        'ukk' => 'Universitas Kahuripan Kediri',
        'umsj' => 'Universitas Mochammad Sroedji Jember',
        'ima' => 'IMA',
        'stbalia' => 'STBA LIA Yogyakarta',
        'unaki' => 'Universitas AKI Semarang',
    ][rsm_campus_alias_key($label)] ?? $label;

    return rsm_campus_plain_label($canonical);
}

function rsm_campus_plain_label(string $label): string
{
    $label = preg_replace('/\s*[\(\[].*?[\)\]]\s*/', ' ', $label) ?? $label;
    $label = preg_replace('/\s+/', ' ', trim($label)) ?? trim($label);

    return $label !== '' ? $label : '-';
}

function rsm_campus_alias_variants(string $label): array
{
    $key = rsm_campus_alias_key($label);
    $variants = [
        'uinaz' => ['Universitas Islam Nusantara Al-Azhaar Lubuklinggau', 'UIN Al-Azhaar Lubuklinggau', 'UIN Al Azhaar Lubuklinggau', 'INAAL', 'UAAL', 'UINAZ'],
        'ivet' => ['Universitas IVET', 'IVET Semarang', 'IVET'],
        'uwks' => ['Universitas Wijaya Kusuma Surabaya', 'UWK Surabaya', 'UWKS'],
        'uwika' => ['Universitas Widya Kartika', 'Universitas Widya Kartika (UWIKA)', 'UWIKA', 'UWK'],
        'uhamzah' => ['UHAMZAH', 'Universitas Hamzanwadi', 'Universitas Hamzah'],
        'stikombali' => ['Institut Teknologi dan Bisnis STIKOM Bali', 'ITB STIKOM Bali', 'KOM'],
        'polnas' => ['Politeknik Nasional Denpasar', 'POLNAS', 'PL'],
        'indocakti' => ['STIE INDOCAKTI', 'Sekolah Tinggi Ilmu Ekonomi Indocakti Malang', 'IMSI', 'ICT'],
        'ubhinus' => ['Universitas Bhinneka Nusantara', 'UBHINUS', 'STK'],
        'ukk' => ['Universitas Kahuripan Kediri', 'UKK', 'KKUK'],
        'umsj' => ['Universitas Mochammad Sroedji Jember', 'UMSJ', 'MSJ'],
        'ima' => ['IMA', 'MHAAKB', 'Institut Miftahul Huda Al Azhar Kota Banjar'],
        'stbalia' => ['STBA LIA Yogyakarta', 'BALYSL', 'Sekolah Tinggi Bahasa Asing LIA Yogyakarta'],
        'unaki' => ['Universitas AKI Semarang', 'Universitas AKI', 'UNAKI'],
    ];
    if ($key === '' || !isset($variants[$key])) {
        return [$label];
    }

    return array_values(array_unique(array_filter(array_merge([$label, rsm_campus_canonical_label($label)], $variants[$key]), static fn (string $value): bool => trim($value) !== '')));
}

function rsm_add_unit_name_filter(array &$where, array &$params, string $column, string $value): void
{
    $variants = rsm_campus_alias_variants($value);
    $where[] = $column . ' IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
    foreach ($variants as $variant) {
        $params[] = $variant;
    }
}

function rsm_campus_short_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    $upper = mb_strtoupper($label);
    $codeMap = [
        'MSJ' => 'UMSJ',
        'IMSI' => 'INDOCAKTI',
        'KKUK' => 'UKK',
        'INAAL' => 'UINAZ',
        'UAAL' => 'UINAZ',
        'MHAAKB' => 'IMA',
        'BALYSL' => 'STBA LIA',
    ];
    if (isset($codeMap[$upper])) {
        return $codeMap[$upper];
    }
    if (preg_match('/\[\s*([A-Z0-9 .-]{2,18})\s*\]/u', $label, $match)) {
        $code = mb_strtoupper(trim($match[1]));
        return $codeMap[$code] ?? $code;
    }
    if (preg_match('/\(([^\)]+)\)/', $label, $matches)) {
        $code = mb_strtoupper(trim((string) $matches[1]));
        return $codeMap[$code] ?? $code;
    }
    $manual = [
        'Universitas Taman Siswa' => 'Taman Siswa',
        'Universitas Teknologi Sulawesi' => 'UTS',
        'Universitas Ubudiyah Indonesia' => 'UUI',
        'Universitas IVET' => 'IVET',
        'IVET Semarang' => 'IVET',
        'UM Surabaya' => 'UMS',
        'Universitas Muhammadiyah Surabaya' => 'UMS',
        'UWK Surabaya' => 'UWKS',
        'Universitas Wijaya Kusuma Surabaya' => 'UWKS',
        'Universitas Widya Kartika' => 'UWIKA',
        'UIN Al-Azhaar Lubuklinggau' => 'UINAZ',
        'UIN Al Azhaar Lubuklinggau' => 'UINAZ',
        'UHAMZAH' => 'UHAMZAH',
        'STIESIA Surabaya' => 'STIESIA',
        'Sekolah Tinggi Ilmu Ekonomi Indonesia' => 'STIESIA',
    ];
    foreach ($manual as $needle => $short) {
        if (mb_stripos($label, $needle) !== false) {
            return $short;
        }
    }
    return '';
}

function rsm_log(?int $reportId, string $area, string $role, string $action, ?string $oldStatus = null, ?string $newStatus = null, ?string $note = null): void
{
    $actor = rsm_current_actor($role);
    $authUser = rsm_auth_user();
    $impersonator = rsm_impersonation_source();
    $stmt = rsm_pdo()->prepare(
        "INSERT INTO rsm_activity_logs
            (report_id, area, actor_user_id, actor_role, actor_actual_role, actor_view_role, actor_name, impersonator_user_id, impersonator_name, action_name, old_status, new_status, note, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $reportId,
        $area,
        isset($authUser['id']) ? (int) $authUser['id'] : null,
        $actor['role'],
        isset($authUser['role']) ? (string) $authUser['role'] : null,
        $role,
        $actor['name'],
        isset($impersonator['id']) ? (int) $impersonator['id'] : null,
        isset($impersonator['name']) ? (string) $impersonator['name'] : null,
        $action,
        $oldStatus,
        $newStatus,
        $note,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function rsm_create_report(string $area, string $role, string $type): int
{
    $actor = rsm_current_actor($role);
    $authUser = rsm_auth_user();
    $date = rsm_input('report_date', date('Y-m-d'));
    $wilayah = rsm_input('wilayah', 'Belum diisi');
    $unit = rsm_input('unit_name', 'Belum diisi');
    $staff = rsm_input('staff_name', $actor['name']);
    $status = rsm_input('status', 'Draft');
    if (($authUser['role'] ?? '') === 'staff') {
        $wilayah = (string) ($authUser['regional'] ?: $wilayah);
        $unit = (string) ($authUser['campus_name'] ?: $unit);
        $staff = (string) ($authUser['name'] ?: $staff);
    }
    $staffRow = rsm_find_staff($staff, $wilayah);
    $campusRow = rsm_find_campus($unit);

    $title = rsm_input('title', rsm_input('campaign_name', rsm_input('category', 'Laporan RSM')));
    $campaignName = rsm_input('campaign_name');
    if ($type === 'ads' && $campaignName === '') {
        $title = '-';
    }

    $data = [
        'area' => $area,
        'report_type' => $type,
        'report_date' => $date !== '' ? $date : date('Y-m-d'),
        'user_id' => $staffRow['id'] ?? null,
        'partner_campus_id' => $campusRow['id'] ?? ($staffRow['partner_campus_id'] ?? null),
        'wilayah' => $wilayah !== '' ? $wilayah : 'Belum diisi',
        'unit_name' => $unit !== '' ? $unit : 'Belum diisi',
        'staff_name' => $staff !== '' ? $staff : $actor['name'],
        'created_by_name' => $actor['name'],
        'created_by_role' => $actor['role'],
        'status' => $status !== '' ? $status : 'Draft',
        'title' => $title,
        'activity_kind' => rsm_input('activity_kind'),
        'location_name' => rsm_input('location_name'),
        'target_text' => rsm_input('target_text'),
        'result_text' => rsm_input('result_text'),
        'leads_count' => (int) rsm_number_input('leads_count'),
        'notes' => rsm_input('notes'),
        'platform' => rsm_input('platform'),
        'ad_period' => rsm_input('ad_period', rsm_default_ad_period($date !== '' ? $date : date('Y-m-d'))),
        'campaign_name' => $campaignName,
        'ad_goal' => rsm_input('ad_goal'),
        'budget_requested' => rsm_number_input('budget_requested'),
        'budget_approved' => rsm_number_input('budget_approved'),
        'realization_amount' => rsm_number_input('realization_amount'),
        'cpl' => rsm_number_input('cpl'),
        'campaign_link' => rsm_input('campaign_link'),
        'category' => rsm_input('category'),
        'obstacle_text' => rsm_input('obstacle_text'),
        'follow_up_text' => rsm_input('follow_up_text'),
    ];
    if ($type === 'ads') {
        if ($role !== 'koordinator') {
            throw new RuntimeException('Laporan iklan diajukan oleh Koordinator Wilayah.');
        }
        $data['status'] = 'Pengajuan';
        if (trim((string) $data['ad_period']) === '') {
            $data['ad_period'] = rsm_default_ad_period((string) $data['report_date']);
        }
        $data['budget_approved'] = 0.0;
        rsm_validate_ad_budget_request($area, $data);
    }

    $columns = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_reports (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute(array_values($data));
    $id = (int) rsm_pdo()->lastInsertId();
    $attachmentPath = rsm_report_attachment_upload($id);
    if ($attachmentPath !== null) {
        rsm_pdo()->prepare('UPDATE rsm_reports SET attachment_path = ?, updated_at = NOW() WHERE id = ? AND area = ?')
            ->execute([$attachmentPath, $id, $area]);
    }
    rsm_log($id, $area, $role, 'create_' . $type, null, $data['status']);

    return $id;
}

function rsm_find_staff(string $name, string $regional): ?array
{
    if ($name === '') {
        return null;
    }
    $stmt = rsm_pdo()->prepare(
        "SELECT id, name, regional, partner_campus_id
         FROM users
         WHERE role = 'staff'
           AND name = ?
           AND (? = '' OR regional = ?)
         ORDER BY is_active DESC, id ASC
         LIMIT 1"
    );
    $stmt->execute([$name, $regional, $regional]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rsm_save_ad_budget_limit(string $area, array $actor): void
{
    if (!rsm_can_manage_ad_budget($actor)) {
        throw new RuntimeException('Hanya Senior Manager yang bisa menetapkan plafon anggaran iklan.');
    }

    $period = trim(rsm_input('ad_period', rsm_default_ad_period(date('Y-m-d'))));
    $wilayah = trim(rsm_input('wilayah'));
    $budget = rsm_number_input('budget_limit');
    $notes = rsm_input('notes');

    if ($period === '' || $wilayah === '') {
        throw new RuntimeException('Periode dan regional wajib diisi.');
    }
    if ($budget <= 0) {
        throw new RuntimeException('Besaran anggaran regional harus lebih dari 0.');
    }

    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_ad_budget_limits
            (area, ad_period, wilayah, budget_limit, notes, created_by_user_id, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            budget_limit = VALUES(budget_limit),
            notes = VALUES(notes),
            created_by_user_id = VALUES(created_by_user_id),
            created_by_name = VALUES(created_by_name),
            updated_at = NOW()'
    );
    $stmt->execute([
        $area,
        $period,
        $wilayah,
        $budget,
        $notes,
        $actor['id'] ?? null,
        $actor['name'] ?? '',
    ]);
}

function rsm_ad_budget_limit(string $area, string $period, string $wilayah): ?array
{
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_ad_budget_limits WHERE area = ? AND ad_period = ? AND wilayah = ? LIMIT 1');
    $stmt->execute([$area, $period, $wilayah]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rsm_ad_budget_used(string $area, string $period, string $wilayah): float
{
    $stmt = rsm_pdo()->prepare(
        "SELECT COALESCE(SUM(budget_requested), 0) AS used_total
         FROM rsm_reports
         WHERE area = ?
           AND report_type = 'ads'
           AND ad_period = ?
           AND wilayah = ?
           AND LOWER(status) <> 'ditolak'"
    );
    $stmt->execute([$area, $period, $wilayah]);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function rsm_validate_ad_budget_request(string $area, array $data): void
{
    $period = trim((string) ($data['ad_period'] ?? ''));
    $wilayah = trim((string) ($data['wilayah'] ?? ''));
    $requested = (float) ($data['budget_requested'] ?? 0);
    if ($requested <= 0) {
        throw new RuntimeException('Anggaran diajukan wajib diisi.');
    }

    $limit = rsm_ad_budget_limit($area, $period, $wilayah);
    if (!$limit || (float) ($limit['budget_limit'] ?? 0) <= 0) {
        throw new RuntimeException('Plafon anggaran untuk ' . $wilayah . ' periode ' . $period . ' belum ditetapkan Senior Manager.');
    }

    $used = rsm_ad_budget_used($area, $period, $wilayah);
    $remaining = (float) $limit['budget_limit'] - $used;
    if ($requested > $remaining + 0.0001) {
        throw new RuntimeException('Pengajuan melebihi sisa plafon ' . $wilayah . ' periode ' . $period . '. Sisa saat ini Rp ' . number_format($remaining, 0, ',', '.') . '.');
    }
}

function rsm_ad_budget_summaries(string $area, string $period, ?array $user = null): array
{
    $where = ['l.area = ?', 'l.ad_period = ?'];
    $params = [$area, $period];
    if ($user && in_array((string) ($user['role'] ?? ''), ['koordinator', 'staff'], true) && (string) ($user['regional'] ?? '') !== '') {
        $where[] = 'l.wilayah = ?';
        $params[] = (string) $user['regional'];
    }

    $stmt = rsm_pdo()->prepare(
        'SELECT l.*
         FROM rsm_ad_budget_limits l
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY FIELD(l.wilayah, "Regional 4", "Regional 5", "Regional 6", "Regional 7"), l.wilayah'
    );
    $stmt->execute($params);
    $limits = $stmt->fetchAll();

    $result = [];
    foreach ($limits as $limit) {
        $wilayah = (string) ($limit['wilayah'] ?? '');
        $usedStmt = rsm_pdo()->prepare(
            "SELECT
                COALESCE(SUM(budget_requested), 0) AS requested,
                COALESCE(SUM(budget_approved), 0) AS approved,
                COALESCE(SUM(realization_amount), 0) AS realization,
                COUNT(*) AS reports_count
             FROM rsm_reports
             WHERE area = ?
               AND report_type = 'ads'
               AND ad_period = ?
               AND wilayah = ?
               AND LOWER(status) <> 'ditolak'"
        );
        $usedStmt->execute([$area, $period, $wilayah]);
        $used = $usedStmt->fetch() ?: [];
        $budgetLimit = (float) ($limit['budget_limit'] ?? 0);
        $requested = (float) ($used['requested'] ?? 0);
        $result[] = [
            'wilayah' => $wilayah,
            'ad_period' => (string) ($limit['ad_period'] ?? $period),
            'budget_limit' => $budgetLimit,
            'requested' => $requested,
            'approved' => (float) ($used['approved'] ?? 0),
            'realization' => (float) ($used['realization'] ?? 0),
            'remaining' => $budgetLimit - $requested,
            'reports_count' => (int) ($used['reports_count'] ?? 0),
            'notes' => (string) ($limit['notes'] ?? ''),
        ];
    }

    return $result;
}

function rsm_social_scope_sql(?array $user, string $alias = 'a'): array
{
    if (!$user) {
        return ['', []];
    }
    $prefix = $alias !== '' ? $alias . '.' : '';
    $role = (string) ($user['role'] ?? '');
    if (in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
        return ['', []];
    }
    if ($role === 'koordinator' && (string) ($user['regional'] ?? '') !== '') {
        return [" AND {$prefix}wilayah = ?", [(string) $user['regional']]];
    }
    if ($role === 'staff') {
        $sql = " AND {$prefix}wilayah = ?";
        $params = [(string) ($user['regional'] ?? '')];
        if ((string) ($user['campus_name'] ?? '') !== '') {
            $sql .= " AND {$prefix}unit_name = ?";
            $params[] = rsm_campus_canonical_label((string) $user['campus_name']);
        }
        return [$sql, $params];
    }

    return [' AND 1 = 0', []];
}

function rsm_social_content_score(string $mediaType, string $caption): array
{
    $base = ['feed' => 10, 'reels' => 15, 'story' => 5][$mediaType] ?? 0;
    $keywords = ['pmb', 'kelas karyawan', 'rpl', 'kuliah', 'pendaftaran', 'mahasiswa baru'];
    $captionLower = mb_strtolower($caption);
    $matched = false;
    foreach ($keywords as $keyword) {
        if ($keyword !== '' && str_contains($captionLower, $keyword)) {
            $matched = true;
            break;
        }
    }

    return ['score' => $base + ($matched ? 5 : 0), 'keyword_match' => $matched ? 1 : 0];
}

function rsm_save_social_account(string $area, array $actor): void
{
    if (!in_array((string) ($actor['role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator', 'staff'], true)) {
        throw new RuntimeException('Akses monitoring konten tidak tersedia.');
    }

    $wilayah = trim(rsm_input('wilayah', (string) ($actor['regional'] ?? '')));
    $unitName = rsm_campus_canonical_label(trim(rsm_input('unit_name', (string) ($actor['campus_name'] ?? ''))));
    $username = trim((string) preg_replace('/^@+/', '', rsm_input('instagram_username')));
    if ($wilayah === '' || $unitName === '' || $username === '') {
        throw new RuntimeException('Regional, kampus, dan username Instagram wajib diisi.');
    }
    if ((string) ($actor['role'] ?? '') === 'koordinator' && (string) ($actor['regional'] ?? '') !== '' && $wilayah !== (string) $actor['regional']) {
        throw new RuntimeException('Koordinator hanya bisa mengelola akun konten regionalnya.');
    }
    if ((string) ($actor['role'] ?? '') === 'staff' && (string) ($actor['campus_name'] ?? '') !== '' && strcasecmp($unitName, rsm_campus_canonical_label((string) $actor['campus_name'])) !== 0) {
        throw new RuntimeException('Staff hanya bisa mengelola akun konten kampusnya.');
    }

    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_social_accounts
            (area, wilayah, unit_name, instagram_username, instagram_business_id, pic_name, pic_phone, connection_status, notes, created_by_user_id, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            wilayah = VALUES(wilayah),
            instagram_business_id = VALUES(instagram_business_id),
            pic_name = VALUES(pic_name),
            pic_phone = VALUES(pic_phone),
            connection_status = VALUES(connection_status),
            notes = VALUES(notes),
            is_active = 1,
            updated_at = NOW()'
    );
    $stmt->execute([
        $area,
        $wilayah,
        $unitName,
        $username,
        rsm_input('instagram_business_id'),
        rsm_input('pic_name', (string) ($actor['name'] ?? '')),
        rsm_input('pic_phone'),
        rsm_input('connection_status', 'Manual'),
        rsm_input('notes'),
        $actor['id'] ?? null,
        $actor['name'] ?? '',
    ]);
}

function rsm_social_accounts(string $area, ?array $user = null): array
{
    $sql = 'SELECT * FROM rsm_social_accounts a WHERE a.area = ? AND a.is_active = 1';
    $params = [$area];
    [$scopeSql, $scopeParams] = rsm_social_scope_sql($user, 'a');
    $sql .= $scopeSql . ' ORDER BY a.wilayah ASC, a.unit_name ASC, a.instagram_username ASC';
    $params = array_merge($params, $scopeParams);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_meta_config(): array
{
    $config = [];
    $path = getenv('RSM_META_CONFIG_PATH') ?: (__DIR__ . '/../source_activity/meta.php');
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    $appId = (string) (($config['app_id'] ?? '') ?: getenv('RSM_META_APP_ID'));
    $appSecret = (string) (($config['app_secret'] ?? '') ?: getenv('RSM_META_APP_SECRET'));
    $redirectUri = (string) (($config['redirect_uri'] ?? '') ?: getenv('RSM_META_REDIRECT_URI'));
    if ($redirectUri === '') {
        $redirectUri = 'https://regionalb.online/instagram-callback.php';
    }

    return [
        'app_id' => trim($appId),
        'app_secret' => trim($appSecret),
        'redirect_uri' => trim($redirectUri),
        'graph_version' => (string) (($config['graph_version'] ?? '') ?: 'v20.0'),
    ];
}

function rsm_meta_require_config(): array
{
    $config = rsm_meta_config();
    if ($config['app_id'] === '' || $config['app_secret'] === '') {
        throw new RuntimeException('Config Meta belum diisi. Buat file private source_activity/meta.php berisi app_id dan app_secret.');
    }
    return $config;
}

function rsm_meta_graph_request(string $path, array $params = []): array
{
    $config = rsm_meta_config();
    $version = trim((string) ($config['graph_version'] ?? 'v20.0'), '/');
    $url = 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/');
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('cURL Meta tidak tersedia.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Meta API tidak memberi respons: ' . $error);
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Respons Meta API tidak valid.');
    }
    if ($httpCode >= 400 || isset($json['error'])) {
        $message = is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? 'Meta API error') : 'Meta API error';
        throw new RuntimeException($message);
    }
    return $json;
}

function rsm_social_account_for_actor(string $area, int $accountId, array $actor): array
{
    foreach (rsm_social_accounts($area, $actor) as $account) {
        if ((int) ($account['id'] ?? 0) === $accountId) {
            return $account;
        }
    }
    throw new RuntimeException('Akun kampus tidak ditemukan dalam scope Anda.');
}

function rsm_update_social_meta_connection(int $accountId, array $page, array $igAccount, string $pageToken, ?int $expiresIn = null): void
{
    $expiresAt = null;
    if ($expiresIn !== null && $expiresIn > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    }
    $stmt = rsm_pdo()->prepare(
        'UPDATE rsm_social_accounts
         SET facebook_page_id = ?, facebook_page_token = ?, token_expires_at = ?, instagram_business_id = ?,
             instagram_username = ?, connection_status = ?, connected_at = NOW(), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        (string) ($page['id'] ?? ''),
        $pageToken,
        $expiresAt,
        (string) ($igAccount['id'] ?? ''),
        trim((string) preg_replace('/^@+/', '', (string) ($igAccount['username'] ?? ''))),
        'Siap API',
        $accountId,
    ]);
}

function rsm_create_social_post(string $area, array $actor): void
{
    $accountId = (int) rsm_input('account_id');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', rsm_input('post_date')) ? rsm_input('post_date') : date('Y-m-d');
    $mediaType = strtolower(rsm_input('media_type'));
    if (!in_array($mediaType, ['no_post', 'feed', 'reels', 'story'], true)) {
        throw new RuntimeException('Jenis konten tidak valid.');
    }

    $account = null;
    foreach (rsm_social_accounts($area, $actor) as $row) {
        if ((int) ($row['id'] ?? 0) === $accountId) {
            $account = $row;
            break;
        }
    }
    if (!$account) {
        throw new RuntimeException('Akun Instagram kampus tidak ditemukan dalam scope Anda.');
    }

    $caption = $mediaType === 'no_post' ? rsm_input('no_post_notes') : rsm_input('caption');
    $score = rsm_social_content_score($mediaType, $caption);
    $postTime = $mediaType === 'no_post' ? null : rsm_input('post_time');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $postTime)) {
        $postTime = null;
    } elseif (strlen($postTime) === 5) {
        $postTime .= ':00';
    }

    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_social_posts
            (account_id, area, post_date, media_type, post_time, caption, post_url, keyword_match, score, reach_count, like_count, comment_count, created_by_user_id, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $accountId,
        $area,
        $date,
        $mediaType,
        $postTime,
        $caption,
        $mediaType === 'no_post' ? '' : rsm_input('post_url'),
        $score['keyword_match'],
        $score['score'],
        $mediaType === 'no_post' ? 0 : (int) rsm_number_input('reach_count'),
        $mediaType === 'no_post' ? 0 : (int) rsm_number_input('like_count'),
        $mediaType === 'no_post' ? 0 : (int) rsm_number_input('comment_count'),
        $actor['id'] ?? null,
        $actor['name'] ?? '',
    ]);
}

function rsm_social_posts(string $area, array $filters, ?array $user = null, int $limit = 120): array
{
    $sql = 'SELECT p.*, a.wilayah, a.unit_name, a.instagram_username
            FROM rsm_social_posts p
            JOIN rsm_social_accounts a ON a.id = p.account_id
            WHERE p.area = ?';
    $params = [$area];
    if (($filters['date_from'] ?? '') !== '') {
        $sql .= ' AND p.post_date >= ?';
        $params[] = (string) $filters['date_from'];
    }
    if (($filters['date_to'] ?? '') !== '') {
        $sql .= ' AND p.post_date <= ?';
        $params[] = (string) $filters['date_to'];
    }
    if (($filters['wilayah'] ?? '') !== '') {
        $sql .= ' AND a.wilayah = ?';
        $params[] = (string) $filters['wilayah'];
    }
    if (($filters['unit_name'] ?? '') !== '') {
        $variants = rsm_campus_alias_variants((string) $filters['unit_name']);
        $sql .= ' AND a.unit_name IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
        $params = array_merge($params, $variants);
    }
    [$scopeSql, $scopeParams] = rsm_social_scope_sql($user, 'a');
    $sql .= $scopeSql . ' ORDER BY p.post_date DESC, p.post_time DESC, p.id DESC LIMIT ' . max(1, min(500, $limit));
    $params = array_merge($params, $scopeParams);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_social_summary(string $area, array $filters, ?array $user = null): array
{
    $accounts = rsm_social_accounts($area, $user);
    $posts = rsm_social_posts($area, $filters, $user, 500);
    $totals = ['accounts' => count($accounts), 'feed' => 0, 'reels' => 0, 'story' => 0, 'score' => 0, 'posted_units' => 0];
    $units = [];
    $regionals = [];
    foreach ($posts as $post) {
        $type = (string) ($post['media_type'] ?? '');
        if (isset($totals[$type])) {
            $totals[$type]++;
        }
        $score = (int) ($post['score'] ?? 0);
        $totals['score'] += $score;
        $unitKey = mb_strtolower((string) ($post['wilayah'] ?? '') . '|' . (string) ($post['unit_name'] ?? ''));
        $units[$unitKey] = true;
        $regional = (string) (($post['wilayah'] ?? '') ?: 'Tanpa Regional');
        if (!isset($regionals[$regional])) {
            $regionals[$regional] = ['wilayah' => $regional, 'feed' => 0, 'reels' => 0, 'story' => 0, 'score' => 0, 'posts' => 0];
        }
        if (isset($regionals[$regional][$type])) {
            $regionals[$regional][$type]++;
        }
        $regionals[$regional]['score'] += $score;
        $regionals[$regional]['posts']++;
    }
    $totals['posted_units'] = count($units);
    uasort($regionals, static fn (array $a, array $b): int => strnatcasecmp((string) $a['wilayah'], (string) $b['wilayah']));

    return ['totals' => $totals, 'regionals' => array_values($regionals), 'posts' => $posts, 'accounts' => $accounts];
}

function rsm_find_campus(string $label): ?array
{
    if ($label === '') {
        return null;
    }
    $stmt = rsm_pdo()->prepare(
        "SELECT id, display_name, name, kode_kampus
         FROM partner_campuses
         WHERE display_name = ?
            OR name = ?
            OR display_name LIKE ?
            OR name LIKE ?
            OR ? LIKE CONCAT('%', display_name, '%')
            OR ? LIKE CONCAT('%', name, '%')
         ORDER BY
            CASE
                WHEN display_name = ? THEN 1
                WHEN name = ? THEN 2
                ELSE 3
            END,
            id ASC
         LIMIT 1"
    );
    $like = '%' . $label . '%';
    $stmt->execute([$label, $label, $like, $like, $label, $label, $label, $label]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rsm_update_status(string $area, string $role): void
{
    $id = (int) rsm_input('report_id');
    $newStatus = rsm_input('new_status');
    $note = rsm_input('revision_note');

    if ($id <= 0 || $newStatus === '') {
        throw new InvalidArgumentException('Data status tidak lengkap.');
    }
    if (str_contains(strtolower($newStatus), 'revisi') && $note === '') {
        throw new InvalidArgumentException('Catatan revisi wajib diisi.');
    }

    [$scopeSql, $scopeParams] = rsm_report_scope_sql(rsm_auth_user());
    $stmt = rsm_pdo()->prepare('SELECT id, report_type, status, wilayah, staff_name, created_by_name, budget_requested, budget_approved FROM rsm_reports WHERE id = ? AND area = ?' . $scopeSql . ' LIMIT 1');
    $stmt->execute(array_merge([$id, $area], $scopeParams));
    $report = $stmt->fetch();
    if (!$report) {
        throw new RuntimeException('Laporan tidak ditemukan.');
    }

    $reportType = (string) ($report['report_type'] ?? '');
    if ($role === 'staff') {
        throw new RuntimeException('Staff tidak dapat mengubah status persetujuan.');
    }
    if ($reportType === 'ads' && $role === 'koordinator') {
        if (strtolower((string) $report['status']) !== 'dilaporkan unit' || $newStatus !== 'Selesai') {
            throw new RuntimeException('Koordinator hanya dapat menyetujui laporan iklan unit yang berstatus Dilaporkan Unit.');
        }
    } elseif ($reportType === 'ads' && !in_array($role, ['super_user', 'executive_director', 'director', 'senior'], true)) {
        throw new RuntimeException('Laporan iklan hanya dapat disetujui, ditolak, atau direvisi oleh Senior Manager.');
    } elseif ($reportType === 'ads' && !in_array($newStatus, ['Disetujui', 'Ditolak', 'Revisi'], true)) {
        throw new RuntimeException('Status laporan iklan hanya bisa Disetujui, Ditolak, atau Revisi dari Senior Manager.');
    }
    if ($role === 'koordinator' && $reportType !== 'ads' && !in_array($newStatus, ['Diverifikasi', 'Revisi'], true)) {
        throw new RuntimeException('Koordinator hanya dapat memverifikasi atau mengembalikan revisi.');
    }

    $oldStatus = (string) $report['status'];
    if ($reportType === 'ads' && $newStatus === 'Disetujui') {
        $approvedAmount = rsm_number_input('budget_approved');
        if ($approvedAmount <= 0) {
            $approvedAmount = (float) (($report['budget_approved'] ?? 0) ?: ($report['budget_requested'] ?? 0));
        }
        $stmt = rsm_pdo()->prepare('UPDATE rsm_reports SET status = ?, budget_approved = ?, revision_note = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $approvedAmount, $note !== '' ? $note : null, $id]);
    } else {
        $stmt = rsm_pdo()->prepare('UPDATE rsm_reports SET status = ?, revision_note = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $note !== '' ? $note : null, $id]);
    }
    rsm_log($id, $area, $role, 'status_update', $oldStatus, $newStatus, $note !== '' ? $note : null);
}

function rsm_update_report(string $area, string $role): void
{
    $id = (int) rsm_input('report_id');
    if ($id <= 0) {
        throw new InvalidArgumentException('ID laporan tidak valid.');
    }

    $authUser = rsm_auth_user();
    $report = rsm_report($area, $id, $authUser);
    if (!$report) {
        throw new RuntimeException('Laporan tidak ditemukan.');
    }
    rsm_assert_report_can_edit($report, $role);

    $date = rsm_input('report_date', (string) $report['report_date']);
    $wilayah = rsm_input('wilayah', (string) $report['wilayah']);
    $unit = rsm_input('unit_name', (string) $report['unit_name']);
    $staff = rsm_input('staff_name', (string) $report['staff_name']);
    if (($authUser['role'] ?? '') === 'staff') {
        $wilayah = (string) ($authUser['regional'] ?: $wilayah);
        $unit = (string) ($authUser['campus_name'] ?: $unit);
        $staff = (string) ($authUser['name'] ?: $staff);
    }
    $staffRow = rsm_find_staff($staff, $wilayah);
    $campusRow = rsm_find_campus($unit);
    $postedValue = static fn (string $key, mixed $fallback = ''): string => array_key_exists($key, $_POST) ? rsm_input($key) : (string) $fallback;
    $postedNumber = static fn (string $key, mixed $fallback = 0): float => array_key_exists($key, $_POST) ? rsm_number_input($key) : (float) $fallback;

    $data = [
        'report_date' => $date !== '' ? $date : date('Y-m-d'),
        'user_id' => $staffRow['id'] ?? $report['user_id'],
        'partner_campus_id' => $campusRow['id'] ?? ($staffRow['partner_campus_id'] ?? $report['partner_campus_id']),
        'wilayah' => $wilayah !== '' ? $wilayah : 'Belum diisi',
        'unit_name' => $unit !== '' ? $unit : 'Belum diisi',
        'staff_name' => $staff !== '' ? $staff : (string) $report['staff_name'],
        'status' => $postedValue('status', (string) $report['status']),
        'title' => $postedValue('title', $postedValue('campaign_name', $postedValue('category', (string) $report['title']))),
        'activity_kind' => $postedValue('activity_kind', (string) ($report['activity_kind'] ?? '')),
        'location_name' => $postedValue('location_name', (string) ($report['location_name'] ?? '')),
        'target_text' => $postedValue('target_text', (string) ($report['target_text'] ?? '')),
        'result_text' => $postedValue('result_text', (string) ($report['result_text'] ?? '')),
        'leads_count' => (int) $postedNumber('leads_count', $report['leads_count'] ?? 0),
        'notes' => $postedValue('notes', (string) ($report['notes'] ?? '')),
        'platform' => $postedValue('platform', (string) ($report['platform'] ?? '')),
        'ad_period' => $postedValue('ad_period', (string) (($report['ad_period'] ?? '') ?: rsm_default_ad_period((string) ($report['report_date'] ?? '')))),
        'campaign_name' => $postedValue('campaign_name', (string) ($report['campaign_name'] ?? '')),
        'ad_goal' => $postedValue('ad_goal', (string) ($report['ad_goal'] ?? '')),
        'budget_requested' => $postedNumber('budget_requested', $report['budget_requested'] ?? 0),
        'budget_approved' => $postedNumber('budget_approved', $report['budget_approved'] ?? 0),
        'realization_amount' => $postedNumber('realization_amount', $report['realization_amount'] ?? 0),
        'cpl' => $postedNumber('cpl', $report['cpl'] ?? 0),
        'campaign_link' => $postedValue('campaign_link', (string) ($report['campaign_link'] ?? '')),
        'category' => $postedValue('category', (string) ($report['category'] ?? '')),
        'obstacle_text' => $postedValue('obstacle_text', (string) ($report['obstacle_text'] ?? '')),
        'follow_up_text' => $postedValue('follow_up_text', (string) ($report['follow_up_text'] ?? '')),
    ];
    if (($report['report_type'] ?? '') === 'ads' && $role === 'staff') {
        $data['report_date'] = (string) $report['report_date'];
        $data['partner_campus_id'] = $report['partner_campus_id'];
        $data['wilayah'] = (string) $report['wilayah'];
        $data['unit_name'] = (string) $report['unit_name'];
        $data['staff_name'] = (string) (($authUser['name'] ?? '') ?: $report['staff_name']);
        $data['status'] = 'Dilaporkan Unit';
        $data['platform'] = (string) ($report['platform'] ?? '');
        $data['ad_period'] = (string) (($report['ad_period'] ?? '') ?: rsm_default_ad_period((string) ($report['report_date'] ?? '')));
        $data['budget_requested'] = (float) ($report['budget_requested'] ?? 0);
        $data['budget_approved'] = (float) ($report['budget_approved'] ?? 0);
    } elseif (($report['report_type'] ?? '') === 'ads' && $role === 'koordinator') {
        $data['status'] = (string) $report['status'];
        $data['budget_approved'] = (float) ($report['budget_approved'] ?? 0);
        $data['realization_amount'] = (float) ($report['realization_amount'] ?? 0);
        $data['leads_count'] = (int) ($report['leads_count'] ?? 0);
        $data['closing_count'] = (int) ($report['closing_count'] ?? 0);
        $data['cpl'] = (float) ($report['cpl'] ?? 0);
        if (!empty($_FILES['attachment_path']) && (int) ($_FILES['attachment_path']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && strtolower((string) $report['status']) === 'disetujui') {
            $data['status'] = 'Transfer / Invoice';
        }
    }
    if (($report['report_type'] ?? '') === 'ads' && trim((string) ($data['ad_period'] ?? '')) === '') {
        $data['ad_period'] = rsm_default_ad_period((string) ($data['report_date'] ?? ''));
    }
    $attachmentPath = rsm_report_attachment_upload($id);
    if ($attachmentPath !== null) {
        $data['attachment_path'] = $attachmentPath;
        if (($report['report_type'] ?? '') === 'ads' && in_array($role, ['super_user', 'executive_director', 'director', 'senior'], true) && strtolower((string) $report['status']) === 'disetujui') {
            $data['status'] = 'Transfer / Invoice';
        }
    }

    $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($data)));
    $stmt = rsm_pdo()->prepare("UPDATE rsm_reports SET {$assignments}, updated_at = NOW() WHERE id = ? AND area = ?");
    $values = array_values($data);
    $values[] = $id;
    $values[] = $area;
    $stmt->execute($values);

    if (($report['report_type'] ?? '') === 'ads') {
        rsm_import_ad_leads($id, $area, $role);
    }

    rsm_log($id, $area, $role, 'update_report', (string) $report['status'], $data['status']);
}

function rsm_delete_report(string $area, string $role): void
{
    $id = (int) rsm_input('report_id');
    if ($id <= 0) {
        throw new InvalidArgumentException('ID laporan tidak valid.');
    }

    $report = rsm_report($area, $id, rsm_auth_user());
    if (!$report) {
        throw new RuntimeException('Laporan tidak ditemukan.');
    }
    rsm_assert_report_can_delete($report, $role);

    rsm_log($id, $area, $role, 'delete_report', (string) $report['status'], null, (string) $report['title']);
    $stmt = rsm_pdo()->prepare('DELETE FROM rsm_reports WHERE id = ? AND area = ?');
    $stmt->execute([$id, $area]);
}

function rsm_assert_report_can_edit(array $report, string $role): void
{
    $status = strtolower((string) ($report['status'] ?? ''));
    if ((string) ($report['report_type'] ?? '') === 'ads') {
        if (in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'koordinator'], true)) {
            return;
        }
        if ($role === 'staff' && in_array($status, ['disetujui', 'transfer / invoice', 'berjalan', 'dilaporkan unit', 'revisi'], true)) {
            return;
        }
    }
    if ($role === 'koordinator' && (string) ($report['report_type'] ?? '') === 'ads') {
        return;
    }
    if ($role !== 'staff') {
        throw new RuntimeException('Edit dan hapus saat ini hanya untuk role Staff Unit.');
    }
    if (!in_array($status, ['draft', 'revisi'], true)) {
        throw new RuntimeException('Laporan hanya bisa diedit atau dihapus saat status Draft atau Revisi.');
    }
}

function rsm_assert_report_can_delete(array $report, string $role): void
{
    if (in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
        return;
    }
    if ($role === 'koordinator' && (string) ($report['report_type'] ?? '') === 'ads') {
        return;
    }
    rsm_assert_report_can_edit($report, $role);
}

function rsm_target_month_from_filters(array $filters): string
{
    $date = (string) (($filters['date_from'] ?? '') ?: date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}/', $date)) {
        return date('Y-m');
    }

    return substr($date, 0, 7);
}

function rsm_monthly_target_scope_key(string $scopeType, string $wilayah, string $unitName, string $staffName): string
{
    $scopeType = in_array($scopeType, ['regional', 'wilayah', 'unit', 'staff'], true) ? $scopeType : 'regional';
    if ($scopeType === 'staff') {
        return 'staff:' . mb_strtolower(trim($staffName));
    }
    if ($scopeType === 'unit') {
        return 'unit:' . mb_strtolower(trim($unitName));
    }
    if ($scopeType === 'wilayah') {
        return 'wilayah:' . mb_strtolower(trim($wilayah));
    }

    return 'regional';
}

function rsm_save_monthly_target(string $area, array $actor): void
{
    if (!rsm_can_manage_targets($actor)) {
        throw new RuntimeException('Hanya Senior Manager atau Mentor yang bisa mengatur target pencapaian.');
    }

    $month = trim(rsm_input('target_month'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new RuntimeException('Bulan target tidak valid.');
    }

    $scopeType = rsm_input('scope_type') ?: 'regional';
    if (!in_array($scopeType, ['regional', 'wilayah', 'unit', 'staff'], true)) {
        $scopeType = 'regional';
    }

    $applyAll = isset($_POST['apply_all_scope']) && (string) $_POST['apply_all_scope'] === '1';
    $wilayah = $scopeType === 'regional' ? '' : trim(rsm_input('wilayah'));
    $unitName = in_array($scopeType, ['unit', 'staff'], true) ? trim(rsm_input('unit_name')) : '';
    $staffName = $scopeType === 'staff' ? trim(rsm_input('staff_name')) : '';
    if (!$applyAll && $scopeType === 'staff' && $staffName !== '') {
        foreach ((rsm_reference_options($area, $actor, 'senior')['staff'] ?? []) as $staffOption) {
            if (mb_strtolower((string) ($staffOption['name'] ?? '')) === mb_strtolower($staffName)) {
                $wilayah = (string) ($staffOption['regional'] ?? $wilayah);
                $unitName = (string) ($staffOption['campus_name'] ?? $unitName);
                break;
            }
        }
    }
    if (!$applyAll && $scopeType === 'wilayah' && $wilayah === '') {
        throw new RuntimeException('Wilayah wajib dipilih untuk target wilayah.');
    }
    if (!$applyAll && $scopeType === 'unit' && $unitName === '') {
        throw new RuntimeException('Unit/Kampus wajib dipilih untuk target unit.');
    }
    if (!$applyAll && $scopeType === 'staff' && $staffName === '') {
        throw new RuntimeException('Staff wajib dipilih untuk target staff.');
    }

    $targetAnggaran = (float) str_replace(',', '.', rsm_input('target_anggaran'));
    $targets = [[
        'wilayah' => $wilayah,
        'unit_name' => $unitName,
        'staff_name' => $staffName,
    ]];
    if ($applyAll && $scopeType !== 'regional') {
        $targets = rsm_monthly_target_bulk_items($area, $actor, $scopeType);
        if (!$targets) {
            throw new RuntimeException('Tidak ada data yang bisa dipilih semua untuk scope ini.');
        }
    }

    $sql = "INSERT INTO rsm_monthly_targets
        (area, target_month, scope_type, scope_key, wilayah, unit_name, staff_name, target_leads, target_follow_up, target_registrasi, target_herregistrasi, target_anggaran, notes, created_by_user_id, created_by_name)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?)
        ON DUPLICATE KEY UPDATE
            scope_type = VALUES(scope_type),
            wilayah = VALUES(wilayah),
            unit_name = VALUES(unit_name),
            staff_name = VALUES(staff_name),
            target_leads = VALUES(target_leads),
            target_follow_up = VALUES(target_follow_up),
            target_registrasi = VALUES(target_registrasi),
            target_herregistrasi = VALUES(target_herregistrasi),
            target_anggaran = VALUES(target_anggaran),
            notes = VALUES(notes),
            created_by_user_id = VALUES(created_by_user_id),
            created_by_name = VALUES(created_by_name)";
    $stmt = rsm_pdo()->prepare($sql);
    foreach ($targets as $target) {
        $targetWilayah = (string) ($target['wilayah'] ?? '');
        $targetUnit = (string) ($target['unit_name'] ?? '');
        $targetStaff = (string) ($target['staff_name'] ?? '');
        $stmt->execute([
            $area,
            $month,
            $scopeType,
            rsm_monthly_target_scope_key($scopeType, $targetWilayah, $targetUnit, $targetStaff),
            $targetWilayah,
            $targetUnit,
            $targetStaff,
            max(0, (int) rsm_input('target_leads')),
            max(0, (int) rsm_input('target_follow_up')),
            max(0, (int) rsm_input('target_registrasi')),
            max(0, (int) rsm_input('target_herregistrasi')),
            max(0.0, $targetAnggaran),
            trim(rsm_input('notes')),
            (int) ($actor['id'] ?? 0),
            (string) ($actor['name'] ?? ''),
        ]);
    }
}

function rsm_monthly_target_bulk_items(string $area, array $actor, string $scopeType): array
{
    $refs = rsm_reference_options($area, $actor, 'senior');
    if ($scopeType === 'wilayah') {
        return array_map(
            static fn (string $regional): array => ['wilayah' => $regional, 'unit_name' => '', 'staff_name' => ''],
            array_values(array_filter(array_map('strval', $refs['regionals'] ?? [])))
        );
    }
    if ($scopeType === 'unit') {
        $items = [];
        foreach (($refs['campuses'] ?? []) as $campus) {
            $label = trim((string) ($campus['label'] ?? ''));
            if ($label !== '') {
                $items[strtolower($label)] = [
                    'wilayah' => (string) ($campus['regional'] ?? ''),
                    'unit_name' => $label,
                    'staff_name' => '',
                ];
            }
        }

        return array_values($items);
    }
    if ($scopeType === 'staff') {
        $items = [];
        foreach (($refs['staff'] ?? []) as $staff) {
            $name = trim((string) ($staff['name'] ?? ''));
            if ($name !== '') {
                $items[strtolower($name)] = [
                    'wilayah' => (string) ($staff['regional'] ?? ''),
                    'unit_name' => (string) ($staff['campus_name'] ?? ''),
                    'staff_name' => $name,
                ];
            }
        }

        return array_values($items);
    }

    return [];
}

function rsm_monthly_targets(string $area, int $limit = 80): array
{
    $stmt = rsm_pdo()->prepare(
        'SELECT * FROM rsm_monthly_targets WHERE area = ? AND scope_type = "staff" ORDER BY target_month DESC, wilayah, unit_name, staff_name LIMIT ' . (int) $limit
    );
    $stmt->execute([$area]);

    return $stmt->fetchAll();
}

function rsm_dashboard_target(string $area, array $filters, ?array $user = null): ?array
{
    $month = rsm_target_month_from_filters($filters);
    $where = ['area = ?', 'target_month = ?', 'scope_type = "staff"'];
    $params = [$area, $month];

    if ($user && (string) ($user['role'] ?? '') === 'staff') {
        $where[] = 'staff_name = ?';
        $params[] = (string) ($user['name'] ?? '');
    } elseif ($user && (string) ($user['role'] ?? '') === 'koordinator' && (string) ($user['regional'] ?? '') !== '') {
        $where[] = 'wilayah = ?';
        $params[] = (string) $user['regional'];
    }

    if (trim((string) ($filters['wilayah'] ?? '')) !== '') {
        $where[] = 'wilayah = ?';
        $params[] = trim((string) $filters['wilayah']);
    }
    if (trim((string) ($filters['unit_name'] ?? '')) !== '') {
        rsm_add_unit_name_filter($where, $params, 'unit_name', trim((string) $filters['unit_name']));
    }
    if (trim((string) ($filters['staff_name'] ?? '')) !== '') {
        $where[] = 'staff_name = ?';
        $params[] = trim((string) $filters['staff_name']);
    }

    $sql = 'SELECT
            COUNT(*) AS staff_target_count,
            SUM(target_leads) AS target_leads,
            SUM(target_follow_up) AS target_follow_up,
            SUM(target_registrasi) AS target_registrasi,
            SUM(target_herregistrasi) AS target_herregistrasi,
            SUM(target_anggaran) AS target_anggaran
        FROM rsm_monthly_targets
        WHERE ' . implode(' AND ', $where);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row || (int) ($row['staff_target_count'] ?? 0) === 0) {
        return null;
    }

    $row['target_month'] = $month;
    $row['scope_type'] = 'staff_aggregate';

    return $row;
}

function rsm_monthly_target_regional_summary(string $area, array $filters, ?array $user = null): array
{
    $month = rsm_target_month_from_filters($filters);
    $where = ['area = ?', 'target_month = ?', 'scope_type = "staff"'];
    $params = [$area, $month];

    if ($user && (string) ($user['role'] ?? '') === 'staff') {
        $where[] = 'staff_name = ?';
        $params[] = (string) ($user['name'] ?? '');
    } elseif ($user && (string) ($user['role'] ?? '') === 'koordinator' && (string) ($user['regional'] ?? '') !== '') {
        $where[] = 'wilayah = ?';
        $params[] = (string) $user['regional'];
    }

    if (trim((string) ($filters['wilayah'] ?? '')) !== '') {
        $where[] = 'wilayah = ?';
        $params[] = trim((string) $filters['wilayah']);
    }
    if (trim((string) ($filters['unit_name'] ?? '')) !== '') {
        rsm_add_unit_name_filter($where, $params, 'unit_name', trim((string) $filters['unit_name']));
    }
    if (trim((string) ($filters['staff_name'] ?? '')) !== '') {
        $where[] = 'staff_name = ?';
        $params[] = trim((string) $filters['staff_name']);
    }

    $stmt = rsm_pdo()->prepare(
        'SELECT wilayah AS regional,
                COUNT(*) AS staff_target_count,
                SUM(target_registrasi) AS target_registrasi,
                SUM(target_herregistrasi) AS target_herregistrasi
         FROM rsm_monthly_targets
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY wilayah
         ORDER BY wilayah'
    );
    $stmt->execute($params);

    $summary = [];
    foreach ($stmt->fetchAll() as $row) {
        $regional = (string) (($row['regional'] ?? '') ?: 'Regional belum diatur');
        $summary[$regional] = [
            'regional' => $regional,
            'staff_target_count' => (int) ($row['staff_target_count'] ?? 0),
            'target_registrasi' => (float) ($row['target_registrasi'] ?? 0),
            'target_herregistrasi' => (float) ($row['target_herregistrasi'] ?? 0),
        ];
    }

    return $summary;
}

function rsm_coordinator_profiles(): array
{
    return [
        'Regional 4' => ['name' => 'Hamaruddin', 'home' => 'Makassar'],
        'Regional 5' => ['name' => 'Kundi Harto', 'home' => 'Semarang'],
        'Regional 6' => ['name' => 'M. Nor Abidin', 'home' => 'Surabaya'],
        'Regional 7' => ['name' => 'Nugroho Budi Santoso', 'home' => 'Banyuwangi'],
    ];
}

function rsm_can_manage_coordinator_schedule(?array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator'], true);
}

function rsm_find_rsm_user_by_name(string $name, string $regional = ''): ?array
{
    if (trim($name) === '') {
        return null;
    }
    $stmt = rsm_pdo()->prepare(
        "SELECT id, name, role, regional
         FROM rsm_users
         WHERE name = ?
           AND (? = '' OR regional = ?)
         ORDER BY is_active DESC, FIELD(role, 'koordinator', 'senior', 'mentor', 'staff') ASC, id ASC
         LIMIT 1"
    );
    $stmt->execute([$name, $regional, $regional]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rsm_coordinator_schedule_filters(): array
{
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
    return [
        'month' => $month,
        'wilayah' => rsm_input('wilayah'),
        'koordinator' => rsm_input('koordinator'),
        'status' => rsm_input('status'),
    ];
}

function rsm_schedule_scope_sql(?array $user, string $alias = 's'): array
{
    if (!$user) {
        return ['', []];
    }
    $role = (string) ($user['role'] ?? '');
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($role === 'koordinator' && !empty($user['regional'])) {
        return [" AND {$prefix}wilayah = ?", [(string) $user['regional']]];
    }
    if ($role === 'staff' && !empty($user['regional'])) {
        return [" AND {$prefix}wilayah = ?", [(string) $user['regional']]];
    }
    return ['', []];
}

function rsm_schedule_visit_type(string $regional, string $unitName): string
{
    $name = strtolower($unitName);
    if ($regional === 'Regional 5' && preg_match('/semarang|yogyakarta|ivet|lia|unaki|undaris|uby|ima/', $name)) {
        return 'Fisik';
    }
    if ($regional === 'Regional 6' && preg_match('/surabaya|gresik|stiesia|um |uwk|uwika|unigres|ikip/', $name)) {
        return 'Fisik';
    }
    if ($regional === 'Regional 7' && preg_match('/banyuwangi|jember|bali|stikom|unibabwi|ubhinus|polnas/', $name)) {
        return 'Fisik';
    }
    if ($regional === 'Regional 4' && preg_match('/makassar|sulawesi|uts|patria/', $name)) {
        return 'Fisik';
    }
    return 'Zoom';
}

function rsm_save_coordinator_schedule(string $area, array $actor): void
{
    if (!rsm_can_manage_coordinator_schedule($actor)) {
        throw new RuntimeException('Anda tidak memiliki akses mengatur jadwal koordinator.');
    }
    $date = rsm_input('schedule_date');
    if ((string) ($actor['role'] ?? '') === 'koordinator') {
        $date = date('Y-m-d');
    }
    $wilayah = rsm_input('wilayah');
    $unitName = rsm_input('unit_name');
    $visitType = rsm_input('visit_type', 'Zoom');
    $agenda = rsm_input('agenda', 'Kunjungan monitoring PMB');
    $status = rsm_input('status', 'Rencana');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $wilayah === '' || $unitName === '') {
        throw new InvalidArgumentException('Tanggal, wilayah, dan unit/kampus wajib diisi.');
    }
    if (!in_array($visitType, ['Fisik', 'Zoom', 'Telepon'], true)) {
        $visitType = 'Zoom';
    }
    if (!in_array($status, ['Rencana', 'Dijadwalkan', 'Selesai', 'Reschedule'], true)) {
        $status = 'Rencana';
    }
    if ((string) ($actor['role'] ?? '') === 'koordinator' && (string) ($actor['regional'] ?? '') !== '' && $wilayah !== (string) $actor['regional']) {
        throw new RuntimeException('Koordinator hanya bisa mengatur jadwal regionalnya sendiri.');
    }
    $profiles = rsm_coordinator_profiles();
    $profile = $profiles[$wilayah] ?? ['name' => rsm_input('koordinator_name', (string) ($actor['name'] ?? 'Koordinator')), 'home' => ''];
    $koordinator = rsm_input('koordinator_name', (string) $profile['name']);
    $home = (string) ($profile['home'] ?? '');
    $user = rsm_find_rsm_user_by_name($koordinator, $wilayah);
    $pdo = rsm_pdo();
    $stmt = $pdo->prepare(
        "INSERT INTO rsm_coordinator_schedules
            (area, schedule_date, koordinator_user_id, koordinator_name, koordinator_home, wilayah, unit_name, visit_type, agenda, status, result_text, next_action, created_by_user_id, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            visit_type = VALUES(visit_type),
            agenda = VALUES(agenda),
            status = VALUES(status),
            result_text = VALUES(result_text),
            next_action = VALUES(next_action),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        $area,
        $date,
        $user['id'] ?? null,
        $koordinator,
        $home,
        $wilayah,
        $unitName,
        $visitType,
        $agenda,
        $status,
        rsm_input('result_text'),
        rsm_input('next_action'),
        $actor['id'] ?? null,
        $actor['name'] ?? null,
    ]);

    $scheduleId = (int) $pdo->lastInsertId();
    if ($scheduleId <= 0) {
        return;
    }
    $hasChecklistInput = false;
    $checklist = [];
    foreach (array_keys(rsm_coordinator_visit_jobdesk_items()) as $itemKey) {
        if (array_key_exists('checklist_' . $itemKey, $_POST) || array_key_exists('checklist_note_' . $itemKey, $_POST)) {
            $hasChecklistInput = true;
        }
        $note = rsm_input('checklist_note_' . $itemKey);
        if (mb_strlen($note) > 200) {
            $note = mb_substr($note, 0, 200);
        }
        $checklist[$itemKey] = [
            'checked' => rsm_input('checklist_' . $itemKey) === '1',
            'note' => $note,
        ];
    }
    $attachmentPath = rsm_schedule_attachment_upload($scheduleId);
    if ($hasChecklistInput || $attachmentPath !== null) {
        $updates = ['checklist_json = ?'];
        $updateValues = [json_encode($checklist, JSON_UNESCAPED_UNICODE)];
        if ($attachmentPath !== null) {
            $updates[] = 'attachment_path = ?';
            $updateValues[] = $attachmentPath;
        }
        $updateValues[] = $scheduleId;
        $updateValues[] = $area;
        $pdo->prepare('UPDATE rsm_coordinator_schedules SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ? AND area = ?')
            ->execute($updateValues);
    }
}

function rsm_update_coordinator_schedule(string $area, array $actor): void
{
    if (!rsm_can_manage_coordinator_schedule($actor)) {
        throw new RuntimeException('Anda tidak memiliki akses memperbarui jadwal koordinator.');
    }
    $id = (int) rsm_input('schedule_id');
    if ($id <= 0) {
        throw new InvalidArgumentException('Jadwal tidak ditemukan.');
    }
    $unitName = rsm_input('unit_name');
    $visitType = rsm_input('visit_type', 'Zoom');
    $agenda = rsm_input('agenda');
    if ($unitName === '' || $agenda === '') {
        throw new InvalidArgumentException('Unit/kampus dan agenda wajib diisi.');
    }
    if (!in_array($visitType, ['Fisik', 'Zoom', 'Telepon'], true)) {
        $visitType = 'Zoom';
    }
    [$scopeSql, $scopeParams] = rsm_schedule_scope_sql($actor, 's');
    $check = rsm_pdo()->prepare("SELECT s.id FROM rsm_coordinator_schedules s WHERE s.id = ? AND s.area = ? {$scopeSql} LIMIT 1");
    $check->execute(array_merge([$id, $area], $scopeParams));
    if (!$check->fetchColumn()) {
        throw new RuntimeException('Jadwal tidak ditemukan atau berada di luar scope Anda.');
    }
    $stmt = rsm_pdo()->prepare("UPDATE rsm_coordinator_schedules SET unit_name = ?, visit_type = ?, agenda = ?, updated_at = NOW() WHERE id = ? AND area = ?");
    $stmt->execute([$unitName, $visitType, $agenda, $id, $area]);
}

function rsm_coordinator_visit_jobdesk_items(): array
{
    return [
        1 => 'Cek tools marketing area kampus',
        2 => 'Cek stok tools marketing kampus',
        3 => 'Cek kondisi ruangan sekretariat',
        4 => 'Cek cara followup dan progres marketing lainnya',
        5 => 'Korwil buat konten medsos dan kolaborasikan dengan medsos kampus',
        6 => 'Cek report kasbonan',
        7 => 'Cek BDC unit',
        8 => 'Koordinasi dengan pejabat kampus',
        9 => 'Cek pencapaian dan herregistrasi per prodi',
    ];
}

function rsm_coordinator_schedule_checklist_state(array $row): array
{
    $saved = json_decode((string) ($row['checklist_json'] ?? ''), true);
    $saved = is_array($saved) ? $saved : [];
    $state = [];
    foreach (array_keys(rsm_coordinator_visit_jobdesk_items()) as $itemKey) {
        $entry = $saved[$itemKey] ?? null;
        if (is_array($entry)) {
            $state[$itemKey] = [
                'checked' => !empty($entry['checked']),
                'note' => trim((string) ($entry['note'] ?? '')),
            ];
        } else {
            $state[$itemKey] = [
                'checked' => !empty($entry),
                'note' => '',
            ];
        }
    }

    return $state;
}

function rsm_coordinator_checklist_summary(array $rows): array
{
    $items = rsm_coordinator_visit_jobdesk_items();
    $totals = array_fill_keys(array_keys($items), 0);
    $checked = array_fill_keys(array_keys($items), 0);
    $considered = 0;
    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') !== 'Selesai' || empty($row['checklist_json'])) {
            continue;
        }
        $considered++;
        $state = rsm_coordinator_schedule_checklist_state($row);
        foreach ($state as $itemKey => $entry) {
            $totals[$itemKey]++;
            if (!empty($entry['checked'])) {
                $checked[$itemKey]++;
            }
        }
    }
    $summary = [];
    foreach ($items as $itemKey => $label) {
        $total = $totals[$itemKey];
        $done = $checked[$itemKey];
        $summary[] = [
            'key' => $itemKey,
            'label' => $label,
            'checked' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }
    usort($summary, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

    return ['considered' => $considered, 'items' => $summary];
}

function rsm_coordinator_schedule_whatsapp_text(string $area, string $month): string
{
    $today = date('Y-m-d');
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $todayLabel = $days[(int) date('w')] . ', ' . date('d/m/Y');
    $data = rsm_coordinator_schedules($area, ['month' => date('Y-m')], null);
    $profiles = rsm_coordinator_profiles();
    $grouped = (array) ($data['grouped'] ?? []);

    $lines = [];
    $lines[] = '*LAPORAN KUNJUNGAN KOORDINATOR WILAYAH*';
    $lines[] = $area . ' - ' . $todayLabel;
    $lines[] = '';

    foreach (rsm_area_regionals($area) as $regional) {
        $rows = (array) ($grouped[$regional] ?? []);
        $todayRows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['schedule_date'] ?? '') === $today));
        $profile = $profiles[$regional] ?? ['name' => '-', 'home' => '-'];
        $lines[] = '*' . $regional . ' - ' . (string) $profile['name'] . '*';
        $doneRows = array_values(array_filter($todayRows, static fn (array $r): bool => (string) ($r['status'] ?? '') === 'Selesai'));
        if ($doneRows === []) {
            $lines[] = '- Belum ada laporan kunjungan hari ini.';
        } else {
            foreach ($doneRows as $row) {
                $result = trim((string) ($row['result_text'] ?? ''));
                $resultLabel = $result !== '' ? $result : 'Belum ada catatan hasil.';
                if (mb_strlen($resultLabel) > 140) {
                    $resultLabel = mb_substr($resultLabel, 0, 140) . '...';
                }
                $lines[] = '- ' . (string) $row['unit_name'] . ': ' . $resultLabel;
                if (empty($row['checklist_json'])) {
                    $lines[] = '  Checklist: belum diisi';
                } else {
                    $checklistState = rsm_coordinator_schedule_checklist_state($row);
                    $lines[] = '  Checklist:';
                    foreach (rsm_coordinator_visit_jobdesk_items() as $itemKey => $itemLabel) {
                        $entry = $checklistState[$itemKey] ?? ['checked' => false, 'note' => ''];
                        $itemStatus = !empty($entry['checked']) ? 'Lengkap' : 'Belum Lengkap';
                        $itemNote = trim((string) ($entry['note'] ?? ''));
                        $itemLine = '  ' . $itemKey . '. ' . $itemLabel . ' - ' . $itemStatus;
                        if ($itemNote !== '') {
                            $itemLine .= ' (catatan: ' . $itemNote . ')';
                        }
                        $lines[] = $itemLine;
                    }
                }
            }
        }
        $lines[] = '';
    }

    $lines[] = '_Digenerate otomatis dari RSM Dashboard, ' . rsm_wib_datetime_label() . '_';

    return implode("\n", $lines);
}

function rsm_coordinator_whatsapp_cache_path(): string
{
    return __DIR__ . '/runtime/cache/coordinator_whatsapp_latest.json';
}

function rsm_save_coordinator_whatsapp_artifact(string $text, string $month): array
{
    $artifact = ['text' => $text, 'month' => $month, 'generated_at' => rsm_wib_timestamp()];
    $path = rsm_coordinator_whatsapp_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($artifact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    return $artifact;
}

function rsm_latest_coordinator_whatsapp_artifact(): ?array
{
    $path = rsm_coordinator_whatsapp_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function rsm_report_coordinator_schedule(string $area, array $actor): void
{
    if (!rsm_can_manage_coordinator_schedule($actor)) {
        throw new RuntimeException('Anda tidak memiliki akses melaporkan hasil kunjungan.');
    }
    $id = (int) rsm_input('schedule_id');
    if ($id <= 0) {
        throw new InvalidArgumentException('Jadwal tidak ditemukan.');
    }
    $status = rsm_input('status', 'Selesai');
    if (!in_array($status, ['Rencana', 'Dijadwalkan', 'Selesai', 'Reschedule'], true)) {
        $status = 'Selesai';
    }
    [$scopeSql, $scopeParams] = rsm_schedule_scope_sql($actor, 's');
    $check = rsm_pdo()->prepare("SELECT s.id FROM rsm_coordinator_schedules s WHERE s.id = ? AND s.area = ? {$scopeSql} LIMIT 1");
    $check->execute(array_merge([$id, $area], $scopeParams));
    if (!$check->fetchColumn()) {
        throw new RuntimeException('Jadwal tidak ditemukan atau berada di luar scope Anda.');
    }
    $attachmentPath = rsm_schedule_attachment_upload($id);
    $checklist = [];
    foreach (array_keys(rsm_coordinator_visit_jobdesk_items()) as $itemKey) {
        $note = rsm_input('checklist_note_' . $itemKey);
        if (mb_strlen($note) > 200) {
            $note = mb_substr($note, 0, 200);
        }
        $checklist[$itemKey] = [
            'checked' => rsm_input('checklist_' . $itemKey) === '1',
            'note' => $note,
        ];
    }
    $data = [
        'status' => $status,
        'result_text' => rsm_input('result_text'),
        'next_action' => rsm_input('next_action'),
        'checklist_json' => json_encode($checklist, JSON_UNESCAPED_UNICODE),
    ];
    if ($attachmentPath !== null) {
        $data['attachment_path'] = $attachmentPath;
    }
    $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($data)));
    $stmt = rsm_pdo()->prepare("UPDATE rsm_coordinator_schedules SET {$assignments}, updated_at = NOW() WHERE id = ? AND area = ?");
    $values = array_values($data);
    $values[] = $id;
    $values[] = $area;
    $stmt->execute($values);
}

function rsm_delete_coordinator_schedule(string $area, array $actor): void
{
    if (!rsm_can_manage_coordinator_schedule($actor)) {
        throw new RuntimeException('Anda tidak memiliki akses menghapus jadwal koordinator.');
    }
    if ((string) ($actor['role'] ?? '') === 'koordinator') {
        throw new RuntimeException('Koordinator tidak memiliki akses menghapus jadwal.');
    }
    $id = (int) rsm_input('schedule_id');
    [$scopeSql, $scopeParams] = rsm_schedule_scope_sql($actor, 's');
    $stmt = rsm_pdo()->prepare("DELETE FROM rsm_coordinator_schedules s WHERE s.id = ? AND s.area = ? {$scopeSql}");
    $stmt->execute(array_merge([$id, $area], $scopeParams));
}

function rsm_generate_coordinator_schedule_month(string $area, array $actor): int
{
    if (!rsm_can_manage_coordinator_schedule($actor)) {
        throw new RuntimeException('Anda tidak memiliki akses generate jadwal koordinator.');
    }
    $month = rsm_input('schedule_month', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $references = rsm_reference_options($area, $actor, (string) ($actor['role'] ?? 'senior'));
    $campusesByRegional = [];
    foreach ((array) ($references['campuses'] ?? []) as $campus) {
        $regional = (string) ($campus['regional'] ?? '');
        $label = (string) ($campus['label'] ?? '');
        if ($regional === '' || $label === '') {
            continue;
        }
        $campusesByRegional[$regional][] = $label;
    }
    $profiles = rsm_coordinator_profiles();
    $start = new DateTimeImmutable($month . '-01');
    $end = $start->modify('last day of this month');
    $inserted = 0;
    $weekdayIndexByRegional = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $weekday = (int) $day->format('N');
        foreach ($profiles as $regional => $profile) {
            if ((string) ($actor['role'] ?? '') === 'koordinator' && (string) ($actor['regional'] ?? '') !== '' && $regional !== (string) $actor['regional']) {
                continue;
            }
            $campuses = array_values(array_unique($campusesByRegional[$regional] ?? []));
            $index = $weekdayIndexByRegional[$regional] ?? 0;
            $isWeeklyReview = $weekday === 5 || $weekday >= 6;
            $unitName = $isWeeklyReview
                ? 'Rekap dan laporan mingguan ' . $regional
                : ($campuses[$index % max(1, count($campuses))] ?? 'Kampus prioritas ' . $regional);
            if (!$isWeeklyReview) {
                $weekdayIndexByRegional[$regional] = $index + 1;
            }
            $visitType = $isWeeklyReview ? 'Zoom' : rsm_schedule_visit_type($regional, $unitName);
            $agenda = $weekday >= 6
                ? 'Follow up weekend, evaluasi leads, dan persiapan kunjungan pekan berikutnya'
                : ($weekday === 5 ? 'Rekap mingguan dan follow up kendala kampus' : 'Monitoring PMB, leads, iklan, dan action plan kampus');
            $_POST['schedule_date'] = $day->format('Y-m-d');
            $_POST['wilayah'] = $regional;
            $_POST['unit_name'] = $unitName;
            $_POST['visit_type'] = $visitType;
            $_POST['agenda'] = $agenda;
            $_POST['status'] = 'Rencana';
            $_POST['koordinator_name'] = (string) $profile['name'];
            rsm_save_coordinator_schedule($area, $actor);
            $inserted++;
        }
    }
    return $inserted;
}

function rsm_coordinator_schedules(string $area, array $filters, ?array $user = null): array
{
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filters['month'] ?? '')) ? (string) $filters['month'] : date('Y-m');
    $where = ['s.area = ?', 's.schedule_date BETWEEN ? AND ?'];
    $params = [$area, $month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    if (!empty($filters['wilayah'])) {
        $where[] = 's.wilayah = ?';
        $params[] = (string) $filters['wilayah'];
    }
    if (!empty($filters['koordinator'])) {
        $where[] = 's.koordinator_name = ?';
        $params[] = (string) $filters['koordinator'];
    }
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = (string) $filters['status'];
    }
    [$scopeSql, $scopeParams] = rsm_schedule_scope_sql($user, 's');
    $stmt = rsm_pdo()->prepare(
        'SELECT s.* FROM rsm_coordinator_schedules s WHERE ' . implode(' AND ', $where) . $scopeSql . ' ORDER BY s.schedule_date ASC, s.wilayah ASC, s.koordinator_name ASC, s.unit_name ASC'
    );
    $stmt->execute(array_merge($params, $scopeParams));
    $rows = $stmt->fetchAll();
    $liburMap = rsm_jadwal_koordinator_libur_map();
    $liburMonth = (string) ($liburMap['month'] ?? '');
    $liburCoordinators = (array) ($liburMap['coordinators'] ?? []);
    $grouped = [];
    $summary = [];
    foreach ($rows as &$row) {
        $row['libur_code'] = null;
        if ($liburMonth === '' || $liburMonth !== $month) {
            continue;
        }
        $scheduleDay = (int) date('j', strtotime((string) $row['schedule_date']));
        $koordinatorName = (string) ($row['koordinator_name'] ?? '');
        $code = $liburCoordinators[$koordinatorName][$scheduleDay] ?? null;
        if ($code === null) {
            continue;
        }
        $row['libur_code'] = $code;
        $row['unit_name'] = $code;
        $row['agenda'] = '';
        $row['visit_type'] = '';
    }
    unset($row);
    foreach ($rows as $row) {
        $regional = (string) ($row['wilayah'] ?? 'Tanpa Regional');
        $grouped[$regional][] = $row;
        $summary[$regional] ??= ['total' => 0, 'Fisik' => 0, 'Zoom' => 0, 'Telepon' => 0, 'Selesai' => 0, 'Libur' => 0];
        $summary[$regional]['total']++;
        if (!empty($row['libur_code'])) {
            $summary[$regional]['Libur']++;
        } else {
            $summary[$regional][(string) ($row['visit_type'] ?? 'Zoom')] = ($summary[$regional][(string) ($row['visit_type'] ?? 'Zoom')] ?? 0) + 1;
        }
        if (($row['status'] ?? '') === 'Selesai') {
            $summary[$regional]['Selesai']++;
        }
    }
    return ['rows' => $rows, 'grouped' => $grouped, 'summary' => $summary, 'month' => $month];
}

function rsm_handle_post(string $area, string $role): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    rsm_verify_csrf();
    $action = rsm_input('action');
    $actor = rsm_auth_user() ?: ['role' => $role];
    if ($action === 'impersonate_user') {
        rsm_impersonate_user($area, (int) rsm_input('target_user_id'));
        return 'Tampilan user aktif berhasil diganti.';
    }
    if ($action === 'stop_impersonation') {
        rsm_stop_impersonation();
        return 'Kembali ke akun asli.';
    }
    if ($action === 'sync_collab_cache') {
        if (!rsm_can_sync_collab(rsm_admin_actor())) {
            throw new RuntimeException('Hanya Senior Manager atau Mentor yang bisa sinkronisasi data pencapaian.');
        }
        $sync = rsm_collab_sync_cache();
        $count = count($sync['reports'] ?? []);
        return $count > 0 ? 'Data pencapaian berhasil disinkronkan.' : 'Sinkronisasi gagal, cache lama tetap digunakan.';
    }
    if ($action === 'sync_jadwal_cache') {
        if (!rsm_can_sync_collab(rsm_admin_actor())) {
            throw new RuntimeException('Hanya Senior Manager atau Mentor yang bisa sinkronisasi jadwal.');
        }
        $sync = rsm_jadwal_sync_cache();
        $count = count($sync['zonas'] ?? []);

        return $count > 0 ? 'Jadwal berhasil disinkronkan.' : 'Sinkronisasi jadwal gagal, cache lama tetap digunakan.';
    }
    if ($action === 'generate_coordinator_whatsapp_report') {
        if ((string) ($actor['role'] ?? '') !== 'super_user') {
            throw new RuntimeException('Hanya Super User yang bisa generate laporan WhatsApp kunjungan.');
        }
        $month = rsm_input('schedule_month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $text = rsm_coordinator_schedule_whatsapp_text($area, $month);
        $GLOBALS['rsm_coordinator_whatsapp_artifact'] = rsm_save_coordinator_whatsapp_artifact($text, $month);

        return 'Laporan WhatsApp kunjungan koordinator berhasil dibuat.';
    }
    if ($action === 'generate_whatsapp_achievement') {
        if ((string) (rsm_admin_actor()['role'] ?? '') !== 'super_user') {
            throw new RuntimeException('Hanya Super User yang bisa generate bahan WhatsApp.');
        }
        try {
            rsm_collab_sync_cache();
        } catch (Throwable) {
            // Tetap generate dari cache terakhir jika sumber Collab sedang lambat/tidak merespons.
        }
        $GLOBALS['rsm_last_whatsapp_artifact'] = rsm_generate_achievement_whatsapp_artifact($area, null, rsm_admin_actor());
        return 'Bahan WhatsApp pencapaian terbaru berhasil dibuat.';
    }
    if ($action === 'save_monthly_target') {
        rsm_save_monthly_target($area, $actor);
        return 'Target pencapaian bulanan berhasil disimpan.';
    }
    if ($action === 'save_ad_budget_limit') {
        rsm_save_ad_budget_limit($area, $actor);
        return 'Plafon anggaran iklan regional berhasil disimpan.';
    }
    if ($action === 'generate_coordinator_schedule') {
        $count = rsm_generate_coordinator_schedule_month($area, $actor);
        return 'Jadwal koordinator berhasil digenerate untuk bulan aktif (' . number_format($count, 0, ',', '.') . ' agenda).';
    }
    if ($action === 'save_coordinator_schedule') {
        rsm_save_coordinator_schedule($area, $actor);
        return 'Jadwal koordinator berhasil disimpan.';
    }
    if ($action === 'update_coordinator_schedule') {
        rsm_update_coordinator_schedule($area, $actor);
        return 'Jadwal koordinator berhasil diperbarui.';
    }
    if ($action === 'report_coordinator_schedule') {
        rsm_report_coordinator_schedule($area, $actor);
        return 'Laporan hasil kunjungan berhasil disimpan.';
    }
    if ($action === 'delete_coordinator_schedule') {
        rsm_delete_coordinator_schedule($area, $actor);
        return 'Jadwal koordinator berhasil dihapus.';
    }
    if ($action === 'save_social_account') {
        rsm_save_social_account($area, $actor);
        return 'Akun Instagram kampus berhasil disimpan.';
    }
    if ($action === 'create_social_post') {
        rsm_create_social_post($area, $actor);
        return 'Posting konten kampus berhasil dicatat.';
    }
    if ($action === 'admin_create_user') {
        rsm_admin_save_user($area, $actor);
        return 'User berhasil ditambahkan.';
    }
    if ($action === 'admin_update_user') {
        rsm_admin_update_user($actor);
        return 'User berhasil diperbarui.';
    }
    if ($action === 'admin_reset_user_password') {
        rsm_admin_reset_user_password($actor);
        return 'Password user berhasil direset.';
    }
    if ($action === 'admin_delete_user') {
        rsm_admin_delete_user($actor);
        return 'User berhasil dihapus.';
    }
    if ($action === 'admin_set_user_active') {
        rsm_admin_set_user_active($actor);
        return 'Status user berhasil diperbarui.';
    }
    if ($action === 'update_profile') {
        rsm_update_profile($actor);
        return 'Profil berhasil diperbarui.';
    }
    if ($action === 'create_marketing') {
        rsm_create_report($area, $role, 'marketing');
        return 'Kegiatan marketing berhasil disimpan ke database.';
    }
    if ($action === 'create_ads') {
        $reportId = rsm_create_report($area, $role, 'ads');
        $importedRows = rsm_import_ad_leads($reportId, $area, $role);
        return 'Laporan anggaran iklan berhasil dikirim. Hubungi Senior Manager untuk approval.' . ($importedRows > 0 ? " Data hasil iklan terimpor {$importedRows} baris." : '');
    }
    if ($action === 'create_other') {
        rsm_create_report($area, $role, 'other');
        return 'Aktivitas lain berhasil disimpan ke database.';
    }
    if ($action === 'status_update') {
        rsm_update_status($area, $role);
        return 'Status laporan berhasil diperbarui.';
    }
    if ($action === 'upload_ad_leads') {
        $reportId = (int) rsm_input('report_id');
        $report = rsm_report($area, $reportId);
        if (!$report || ($report['report_type'] ?? '') !== 'ads') {
            throw new RuntimeException('Laporan iklan tidak ditemukan.');
        }
        $importedRows = rsm_import_ad_leads($reportId, $area, $role, true);
        return $importedRows > 0 ? "Data hasil iklan berhasil diupload ulang ({$importedRows} baris)." : 'File berhasil diterima, tetapi tidak ada baris data yang terbaca.';
    }
    if ($action === 'update_lead_closing_status') {
        rsm_update_lead_closing_status($area, $role);
        return 'Status closing lead berhasil diperbarui.';
    }
    if ($action === 'update_ad_leads') {
        rsm_update_ad_leads($area, $role);
        return 'Data hasil iklan berhasil diperbarui.';
    }
    if ($action === 'update_report') {
        rsm_update_report($area, $role);
        return 'Laporan berhasil diperbarui.';
    }
    if ($action === 'delete_report') {
        rsm_delete_report($area, $role);
        return 'Laporan berhasil dihapus.';
    }

    throw new InvalidArgumentException('Aksi tidak dikenali.');
}

function rsm_update_lead_closing_status(string $area, string $role): void
{
    $leadId = (int) rsm_input('lead_id');
    $status = rsm_input('closing_status');
    if ($leadId <= 0 || $status === '') {
        throw new InvalidArgumentException('Status closing tidak lengkap.');
    }

    [$scopeSql, $scopeParams] = rsm_report_scope_sql(rsm_auth_user(), 'r');
    $stmt = rsm_pdo()->prepare(
        "SELECT l.id, l.report_id, l.closing_status, r.area
         FROM rsm_ad_leads l
         JOIN rsm_reports r ON r.id = l.report_id
         WHERE l.id = ? AND r.area = ? {$scopeSql}
         LIMIT 1"
    );
    $stmt->execute(array_merge([$leadId, $area], $scopeParams));
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Data lead tidak ditemukan.');
    }

    $newStatus = rsm_normalize_closing_status($status);
    rsm_pdo()->prepare('UPDATE rsm_ad_leads SET closing_status = ? WHERE id = ?')->execute([$newStatus, $leadId]);
    rsm_refresh_report_ad_counts((int) $row['report_id']);
    rsm_log((int) $row['report_id'], $area, $role, 'update_lead_closing_status', (string) ($row['closing_status'] ?? ''), $newStatus);
}

function rsm_update_ad_leads(string $area, string $role): void
{
    $reportId = (int) rsm_input('report_id');
    $statuses = $_POST['lead_status'] ?? [];
    $notes = $_POST['lead_notes'] ?? [];
    if ($reportId <= 0 || !is_array($statuses)) {
        throw new InvalidArgumentException('Data hasil iklan tidak lengkap.');
    }

    [$scopeSql, $scopeParams] = rsm_report_scope_sql(rsm_auth_user(), 'r');
    $stmt = rsm_pdo()->prepare(
        "SELECT r.id, r.report_type
         FROM rsm_reports r
         WHERE r.id = ? AND r.area = ? {$scopeSql}
         LIMIT 1"
    );
    $stmt->execute(array_merge([$reportId, $area], $scopeParams));
    $report = $stmt->fetch();
    if (!$report || (string) ($report['report_type'] ?? '') !== 'ads') {
        throw new RuntimeException('Laporan iklan tidak ditemukan.');
    }

    $leadIds = array_values(array_filter(array_map('intval', array_keys($statuses)), static fn (int $id): bool => $id > 0));
    if ($leadIds === []) {
        return;
    }

    $pdo = rsm_pdo();
    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
    $existingStmt = $pdo->prepare(
        "SELECT id, closing_status, notes
         FROM rsm_ad_leads
         WHERE report_id = ? AND id IN ({$placeholders})"
    );
    $existingStmt->execute(array_merge([$reportId], $leadIds));
    $existingRows = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existingRows[(int) $row['id']] = $row;
    }

    $updateStmt = $pdo->prepare('UPDATE rsm_ad_leads SET closing_status = ?, notes = ? WHERE id = ? AND report_id = ?');
    $changes = 0;
    $firstOldStatus = null;
    $firstNewStatus = null;

    $pdo->beginTransaction();
    try {
        foreach ($leadIds as $leadId) {
            if (!isset($existingRows[$leadId])) {
                continue;
            }
            $newStatus = rsm_normalize_closing_status((string) ($statuses[$leadId] ?? ''));
            $newNotes = trim((string) (($notes[$leadId] ?? '')));
            $oldStatus = (string) ($existingRows[$leadId]['closing_status'] ?? '');
            $oldNotes = (string) ($existingRows[$leadId]['notes'] ?? '');
            if (strtolower(trim($oldStatus)) === strtolower(trim($newStatus)) && trim($oldNotes) === $newNotes) {
                continue;
            }
            $updateStmt->execute([$newStatus, $newNotes !== '' ? $newNotes : null, $leadId, $reportId]);
            $changes++;
            $firstOldStatus ??= $oldStatus;
            $firstNewStatus ??= $newStatus;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    if ($changes > 0) {
        rsm_refresh_report_ad_counts($reportId);
        rsm_log($reportId, $area, $role, 'update_ad_leads', $firstOldStatus, $firstNewStatus, "{$changes} baris data hasil iklan diperbarui.");
    }
}

function rsm_report_scope_sql(?array $user, string $alias = ''): array
{
    if (!$user) {
        return ['', []];
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    $actualRole = (string) ($user['role'] ?? '');
    if (in_array($actualRole, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
        return ['', []];
    }

    if ($actualRole === 'koordinator' && !empty($user['regional'])) {
        return [" AND {$prefix}wilayah = ?", [(string) $user['regional']]];
    }

    if ($actualRole === 'staff') {
        $clauses = ["({$prefix}staff_name = ? OR {$prefix}created_by_name = ? OR {$prefix}report_type = 'ads')"];
        $params = [(string) ($user['name'] ?? ''), (string) ($user['name'] ?? '')];

        if (!empty($user['regional'])) {
            $clauses[] = "{$prefix}wilayah = ?";
            $params[] = (string) $user['regional'];
        }
        if (!empty($user['campus_name'])) {
            $clauses[] = "({$prefix}unit_name = ? OR {$prefix}partner_campus_id IN (
                SELECT id FROM partner_campuses
                WHERE display_name = ?
                   OR name = ?
                   OR display_name LIKE ?
                   OR name LIKE ?
                   OR ? LIKE CONCAT('%', display_name, '%')
                   OR ? LIKE CONCAT('%', name, '%')
            ))";
            $campusName = (string) $user['campus_name'];
            $params[] = (string) $user['campus_name'];
            $params[] = $campusName;
            $params[] = $campusName;
            $params[] = '%' . $campusName . '%';
            $params[] = '%' . $campusName . '%';
            $params[] = $campusName;
            $params[] = $campusName;
        }

        return [' AND ' . implode(' AND ', $clauses), $params];
    }

    return [' AND 1 = 0', []];
}

function rsm_reports(string $area, ?string $type = null, int $limit = 50, ?array $user = null, array $filters = []): array
{
    $sql = 'SELECT * FROM rsm_reports r WHERE r.area = ?';
    $params = [$area];
    if ($type !== null) {
        $sql .= ' AND r.report_type = ?';
        $params[] = $type;
    }
    [$filterSql, $filterParams] = rsm_dashboard_filter_sql($filters, 'r');
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $sql .= $filterSql . $scopeSql;
    $params = array_merge($params, $filterParams, $scopeParams);
    $sql .= ' ORDER BY r.report_date DESC, r.id DESC LIMIT ' . max(1, min(500, $limit));
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_ads_pending_reports(string $area, ?array $user = null, int $limit = 100): array
{
    $sql = "SELECT * FROM rsm_reports r
            WHERE r.area = ?
              AND r.report_type = 'ads'
              AND (
                LOWER(r.status) NOT IN ('dilaporkan unit', 'ditolak')
                OR (LOWER(r.status) = 'dilaporkan unit' AND (r.realization_amount <= 0 OR r.attachment_path IS NULL OR r.attachment_path = ''))
              )";
    $params = [$area];
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $sql .= $scopeSql . ' ORDER BY r.report_date DESC, r.id DESC LIMIT ' . max(1, min(500, $limit));
    $params = array_merge($params, $scopeParams);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['pending_reason'] = strtolower(trim((string) ($row['status'] ?? ''))) === 'dilaporkan unit' ? 'belum_tuntas' : 'belum_dilaporkan';
    }
    unset($row);

    return $rows;
}

function rsm_rekap_reports(string $area, array $filters, ?string $type = null, int $limit = 200, ?array $user = null): array
{
    $sql = 'SELECT * FROM rsm_reports r WHERE r.area = ?';
    $params = [$area];
    if ($type !== null && $type !== '') {
        $sql .= ' AND r.report_type = ?';
        $params[] = $type;
    }
    [$filterSql, $filterParams] = rsm_dashboard_filter_sql($filters, 'r');
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $sql .= $filterSql . $scopeSql . ' ORDER BY r.report_date DESC, r.id DESC LIMIT ' . max(1, min(500, $limit));
    $params = array_merge($params, $filterParams, $scopeParams);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_report(string $area, int $id, ?array $user = null): ?array
{
    $sql = 'SELECT * FROM rsm_reports WHERE area = ? AND id = ?';
    $params = [$area, $id];
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user);
    $sql .= $scopeSql . ' LIMIT 1';
    $params = array_merge($params, $scopeParams);
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rsm_report_logs(int $reportId): array
{
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_activity_logs WHERE report_id = ? ORDER BY id DESC');
    $stmt->execute([$reportId]);
    return $stmt->fetchAll();
}

function rsm_report_ad_leads(int $reportId): array
{
    $stmt = rsm_pdo()->prepare('SELECT * FROM rsm_ad_leads WHERE report_id = ? ORDER BY id ASC');
    $stmt->execute([$reportId]);
    return $stmt->fetchAll();
}

function rsm_import_ad_leads(int $reportId, string $area, string $role, bool $replaceExisting = false): int
{
    $file = $_FILES['ad_leads_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload data hasil iklan gagal. Periksa kembali file XLS.');
    }

    $originalName = (string) ($file['name'] ?? 'hasil-iklan.xls');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        throw new InvalidArgumentException('Format data hasil iklan wajib .xls atau .xlsx.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran file hasil iklan maksimal 5 MB.');
    }

    $uploadDir = __DIR__ . '/runtime/uploads/ad-leads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $safeName = preg_replace('/[^a-z0-9._-]+/i', '-', $originalName) ?: 'hasil-iklan.xls';
    $target = $uploadDir . '/' . $reportId . '-' . date('Ymd-His') . '-' . $safeName;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new RuntimeException('File hasil iklan tidak bisa disimpan sementara.');
    }

    $rows = rsm_parse_ad_lead_file($target);
    if ($rows === []) {
        return 0;
    }

    if ($replaceExisting) {
        rsm_pdo()->prepare('DELETE FROM rsm_ad_leads WHERE report_id = ?')->execute([$reportId]);
    }

    $stmt = rsm_pdo()->prepare(
        "INSERT INTO rsm_ad_leads
            (report_id, lead_name, whatsapp, email, campus_name, major_name, origin_city, follow_up_result, progress_status, closing_status, closing_update, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $count = 0;
    foreach ($rows as $row) {
        if (implode('', $row) === '') {
            continue;
        }
        $stmt->execute([
            $reportId,
            rsm_nullable($row['lead_name'] ?? ''),
            rsm_nullable($row['whatsapp'] ?? ''),
            rsm_nullable($row['email'] ?? ''),
            rsm_nullable($row['campus_name'] ?? ''),
            rsm_nullable($row['major_name'] ?? ''),
            rsm_nullable($row['origin_city'] ?? ''),
            rsm_nullable($row['follow_up_result'] ?? ''),
            rsm_nullable($row['progress_status'] ?? ''),
            rsm_nullable(rsm_normalize_closing_status($row['closing_status'] ?? ($row['closing_update'] ?? ''))),
            rsm_nullable($row['closing_update'] ?? ''),
            rsm_nullable($row['notes'] ?? ''),
        ]);
        $count++;
    }

    rsm_refresh_report_ad_counts($reportId);

    if ($count > 0) {
        rsm_log($reportId, $area, $role, 'import_ad_leads', null, null, "Import data hasil iklan {$count} baris.");
    }

    return $count;
}

function rsm_refresh_report_ad_counts(int $reportId): void
{
    $stmt = rsm_pdo()->prepare(
        "SELECT
            COUNT(*) AS lead_total,
            SUM(CASE WHEN LOWER(COALESCE(closing_status, '')) IN ('closing','daftar','herregistrasi') THEN 1 ELSE 0 END) AS closing_total
         FROM rsm_ad_leads
         WHERE report_id = ?"
    );
    $stmt->execute([$reportId]);
    $row = $stmt->fetch() ?: [];

    rsm_pdo()->prepare('UPDATE rsm_reports SET leads_count = ?, closing_count = ?, updated_at = NOW() WHERE id = ?')
        ->execute([(int) ($row['lead_total'] ?? 0), (int) ($row['closing_total'] ?? 0), $reportId]);
}

function rsm_normalize_closing_status(string $status): string
{
    $rawStatus = strtolower(trim($status));
    if ($rawStatus === '') {
        return 'Belum closing';
    }

    $normalized = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $rawStatus)) ?? $rawStatus;
    $negativePatterns = [
        'belum closing',
        'tidak closing',
        'gagal closing',
        'batal closing',
        'cancel closing',
        'non closing',
        'no closing',
        'not closing',
        'belum daftar',
        'tidak daftar',
    ];
    foreach ($negativePatterns as $pattern) {
        if (str_contains($normalized, $pattern)) {
            return str_contains($normalized, 'potensi') ? 'Potensi closing' : 'Belum closing';
        }
    }

    if (str_contains($normalized, 'potensi')) {
        return 'Potensi closing';
    }
    if (str_contains($normalized, 'her') || str_contains($normalized, 'daftar ulang')) {
        return 'Herregistrasi';
    }
    if (str_contains($normalized, 'registrasi') || str_contains($normalized, 'daftar') || str_contains($normalized, 'closing') || str_contains($normalized, 'close')) {
        return 'Closing';
    }

    return ucfirst($normalized);
}

function rsm_is_closing_status(string $status): bool
{
    return in_array(strtolower(trim($status)), ['closing', 'daftar', 'herregistrasi'], true);
}

function rsm_nullable(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function rsm_parse_ad_lead_file(string $path): array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'xlsx') {
        return rsm_parse_ad_lead_xlsx($path);
    }

    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return [];
    }

    if (stripos($content, '<Workbook') !== false && stripos($content, '<Row') !== false) {
        return rsm_parse_ad_lead_spreadsheet_xml($content);
    }

    if (stripos($content, '<tr') !== false) {
        return rsm_parse_ad_lead_html_table($content);
    }

    return rsm_parse_ad_lead_delimited($content);
}

function rsm_parse_ad_lead_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Server PHP belum mengaktifkan ekstensi ZIP untuk membaca file .xlsx.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File .xlsx tidak bisa dibuka.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $sharedXml, $matches);
        foreach ($matches[1] as $siXml) {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $siXml, $textMatches);
            $text = implode('', array_map(static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'), $textMatches[1]));
            $sharedStrings[] = $text;
        }
    }

    $sheetPath = rsm_xlsx_first_sheet_path($zip) ?? 'xl/worksheets/sheet1.xml';
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        return [];
    }

    preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches);
    $rows = [];
    foreach ($rowMatches[1] as $rowXml) {
        preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/is', $rowXml, $cellMatches, PREG_SET_ORDER);
        $cells = [];
        foreach ($cellMatches as $cellMatch) {
            $attributes = $cellMatch[1] ?? '';
            $index = count($cells);
            if (preg_match('/\br="([A-Z]+)\d+"/i', $attributes, $refMatch)) {
                $index = rsm_xlsx_column_index($refMatch[1]);
            }
            $type = preg_match('/\bt="([^"]+)"/i', $attributes, $typeMatch) ? strtolower($typeMatch[1]) : '';
            $rawValue = '';
            if (preg_match('/<v\b[^>]*>(.*?)<\/v>/is', $cellMatch[2] ?? '', $valueMatch)) {
                $rawValue = html_entity_decode($valueMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            } elseif (preg_match('/<t\b[^>]*>(.*?)<\/t>/is', $cellMatch[2] ?? '', $inlineMatch)) {
                $rawValue = html_entity_decode($inlineMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $cells[$index] = $type === 's' ? (string) ($sharedStrings[(int) $rawValue] ?? '') : trim($rawValue);
        }
        if ($cells !== []) {
            ksort($cells);
            $rows[] = rsm_preserve_blank_cells($cells);
        }
    }

    return rsm_map_ad_lead_rows($rows);
}

function rsm_xlsx_first_sheet_path(ZipArchive $zip): ?string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false || $rels === false) {
        return null;
    }
    if (!preg_match('/<sheet\b[^>]*r:id="([^"]+)"/i', $workbook, $sheetMatch)) {
        return null;
    }
    $relationId = preg_quote($sheetMatch[1], '/');
    if (!preg_match('/<Relationship\b[^>]*Id="' . $relationId . '"[^>]*Target="([^"]+)"/i', $rels, $relMatch)) {
        return null;
    }
    $target = str_replace('\\', '/', html_entity_decode($relMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    return str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
}

function rsm_xlsx_column_index(string $letters): int
{
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return max(0, $index - 1);
}

function rsm_parse_ad_lead_spreadsheet_xml(string $content): array
{
    preg_match_all('/<Row\b[^>]*>(.*?)<\/Row>/is', $content, $rowMatches);
    $tableRows = [];
    foreach ($rowMatches[1] as $rowXml) {
        preg_match_all('/<Cell\b([^>]*)>(.*?)<\/Cell>/is', $rowXml, $cellMatches, PREG_SET_ORDER);
        $cells = [];
        $position = 0;
        foreach ($cellMatches as $cellMatch) {
            $attributes = $cellMatch[1] ?? '';
            if (preg_match('/ss:Index="(\d+)"/i', $attributes, $indexMatch)) {
                $position = max(0, ((int) $indexMatch[1]) - 1);
            }
            $value = '';
            if (preg_match('/<Data\b[^>]*>(.*?)<\/Data>/is', $cellMatch[2] ?? '', $dataMatch)) {
                $value = html_entity_decode(strip_tags($dataMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $cells[$position] = trim($value);
            $position++;
        }
        if ($cells !== []) {
            ksort($cells);
            $tableRows[] = rsm_preserve_blank_cells($cells);
        }
    }

    return rsm_map_ad_lead_rows($tableRows);
}

function rsm_preserve_blank_cells(array $cells): array
{
    if ($cells === []) {
        return [];
    }

    $maxIndex = max(array_map('intval', array_keys($cells)));
    $row = [];
    for ($index = 0; $index <= $maxIndex; $index++) {
        $row[] = trim((string) ($cells[$index] ?? ''));
    }

    return $row;
}

function rsm_parse_ad_lead_html_table(string $content): array
{
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $content, $rowMatches);
    $tableRows = [];
    foreach ($rowMatches[1] as $rowHtml) {
        preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches);
        $cells = array_map(static function (string $cell): string {
            return trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }, $cellMatches[1]);
        if ($cells !== []) {
            $tableRows[] = $cells;
        }
    }

    return rsm_map_ad_lead_rows($tableRows);
}

function rsm_parse_ad_lead_delimited(string $content): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line, "\t");
    }
    return rsm_map_ad_lead_rows($rows);
}

function rsm_map_ad_lead_rows(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }

    $headers = array_map('rsm_normalize_header', $rows[0]);
    $map = [
        'nama_lead' => 'lead_name',
        'full_name' => 'lead_name',
        'nama_lengkap' => 'lead_name',
        'no_hp_wa' => 'whatsapp',
        'no_wa' => 'whatsapp',
        'nomor_wa' => 'whatsapp',
        'phone_number' => 'whatsapp',
        'phone' => 'whatsapp',
        'telepon' => 'whatsapp',
        'email' => 'email',
        'alamat_email' => 'email',
        'email_address' => 'email',
        'kampus' => 'campus_name',
        'conditional_question_1' => 'campus_name',
        'nama_kampus' => 'campus_name',
        'jurusan' => 'major_name',
        'conditional_question_2' => 'major_name',
        'program_studi' => 'major_name',
        'kota_asal' => 'origin_city',
        'city' => 'origin_city',
        'kota' => 'origin_city',
        'hasil_follow_up' => 'follow_up_result',
        'follow_up' => 'follow_up_result',
        'status_progress' => 'progress_status',
        'lead_status' => 'progress_status',
        'status' => 'progress_status',
        'status_closing' => 'closing_status',
        'closing_status' => 'closing_status',
        'status_daftar' => 'closing_status',
        'update_closing' => 'closing_update',
        'catatan_kendala_progress' => 'notes',
        'notes' => 'notes',
        'catatan' => 'notes',
    ];

    $result = [];
    foreach (array_slice($rows, 1) as $cells) {
        $item = array_fill_keys(array_values($map), '');
        foreach ($cells as $index => $value) {
            $header = $headers[$index] ?? '';
            if (isset($map[$header])) {
                $item[$map[$header]] = trim((string) $value);
            }
        }
        if (implode('', $item) !== '') {
            $result[] = $item;
        }
    }

    return $result;
}

function rsm_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace(['/', '.', '-'], ' ', $header);
    $header = preg_replace('/[^a-z0-9]+/i', '_', $header) ?? $header;
    return trim($header, '_');
}

function rsm_dashboard_filters_from_request(array $input): array
{
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['date_from'] ?? '')) ? (string) $input['date_from'] : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['date_to'] ?? '')) ? (string) $input['date_to'] : '';
    $page = strtolower(trim((string) ($input['page'] ?? 'dashboard')));

    if ($dateFrom === '' && $dateTo === '') {
        $dateFrom = in_array($page, ['dashboard', 'anggaran'], true) ? date('Y-m-01') : date('Y-m-d');
        $dateTo = date('Y-m-d');
    } elseif ($dateFrom !== '' && $dateTo === '') {
        $dateTo = $dateFrom;
    } elseif ($dateFrom === '' && $dateTo !== '') {
        $dateFrom = $dateTo;
    }

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $periode = strtolower(trim((string) ($input['periode'] ?? '')));
    if ($periode === 'daily') {
        $dateTo = $dateFrom;
    } elseif ($periode === 'weekly') {
        $timestamp = strtotime($dateFrom) ?: time();
        $dateFrom = date('Y-m-d', strtotime('monday this week', $timestamp));
        $dateTo = date('Y-m-d', strtotime('sunday this week', $timestamp));
    } elseif ($periode === 'monthly') {
        $timestamp = strtotime($dateFrom) ?: time();
        $dateFrom = date('Y-m-01', $timestamp);
        $dateTo = date('Y-m-t', $timestamp);
    }
    $month = substr($dateFrom, 0, 7);

    return [
        'month' => $month,
        'periode' => $periode,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'wilayah' => trim((string) ($input['wilayah'] ?? '')),
        'unit_name' => trim((string) ($input['unit_name'] ?? '')),
        'staff_name' => trim((string) ($input['staff_name'] ?? '')),
        'platform' => trim((string) ($input['platform'] ?? '')),
        'status' => trim((string) ($input['status'] ?? '')),
    ];
}

function rsm_dashboard_filter_sql(array $filters, string $alias = 'r'): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $sql = '';
    $params = [];

    if (($filters['date_from'] ?? '') !== '') {
        $sql .= " AND {$prefix}report_date >= ?";
        $params[] = (string) $filters['date_from'];
    }
    if (($filters['date_to'] ?? '') !== '') {
        $sql .= " AND {$prefix}report_date <= ?";
        $params[] = (string) $filters['date_to'];
    }
    foreach (['wilayah', 'unit_name', 'staff_name', 'platform', 'status', 'ad_period'] as $field) {
        if (($filters[$field] ?? '') !== '') {
            if ($field === 'unit_name') {
                $variants = rsm_campus_alias_variants((string) $filters[$field]);
                $sql .= " AND {$prefix}{$field} IN (" . implode(',', array_fill(0, count($variants), '?')) . ")";
                foreach ($variants as $variant) {
                    $params[] = $variant;
                }
                continue;
            }
            if ($field === 'ad_period') {
                $bounds = rsm_ad_period_bounds((string) $filters[$field]);
                if ($bounds !== null) {
                    $sql .= " AND ({$prefix}ad_period = ? OR (({$prefix}ad_period IS NULL OR {$prefix}ad_period = '') AND {$prefix}report_date BETWEEN ? AND ?))";
                    $params[] = (string) $filters[$field];
                    $params[] = $bounds[0];
                    $params[] = $bounds[1];
                    continue;
                }
            }
            $sql .= " AND {$prefix}{$field} = ?";
            $params[] = (string) $filters[$field];
        }
    }

    return [$sql, $params];
}

function rsm_ad_period_bounds(string $period): ?array
{
    $monthNumbers = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];
    if (!preg_match('/^([a-zA-Z]+)\s+(\d{4})$/', trim($period), $matches)) {
        return null;
    }
    $month = $monthNumbers[strtolower($matches[1])] ?? null;
    if ($month === null) {
        return null;
    }
    $year = (int) $matches[2];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    return [$start, $end];
}

function rsm_dashboard_percent(float $part, float $whole): float
{
    return $whole > 0 ? round(($part / $whole) * 100, 2) : 0.0;
}

function rsm_dashboard_divide(float $part, float $whole): float
{
    return $whole > 0 ? $part / $whole : 0.0;
}

function rsm_number_value(mixed $value): float
{
    $normalized = str_replace(',', '.', trim((string) $value));
    return is_numeric($normalized) ? (float) $normalized : 0.0;
}

function rsm_dashboard_status_map(string $area, array $filters, ?array $user): array
{
    [$filterSql, $filterParams] = rsm_dashboard_filter_sql($filters, 'r');
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $params = array_merge([$area], $filterParams, $scopeParams);

    $readDistinct = static function (string $select, string $from, string $where = '') use ($filterSql, $scopeSql, $params): array {
        $stmt = rsm_pdo()->prepare(
            "SELECT DISTINCT {$select} AS value
             FROM {$from}
             WHERE r.area = ? {$filterSql} {$scopeSql} {$where}
             ORDER BY value"
        );
        $stmt->execute($params);
        return array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['value'] ?? '')), $stmt->fetchAll()), static fn (string $value): bool => $value !== ''));
    };

    return [
        'report_type' => $readDistinct('r.report_type', 'rsm_reports r'),
        'status' => $readDistinct('r.status', 'rsm_reports r'),
        'progress_status' => $readDistinct('l.progress_status', 'rsm_reports r JOIN rsm_ad_leads l ON l.report_id = r.id', " AND COALESCE(l.progress_status, '') <> ''"),
        'follow_up_result' => $readDistinct('l.follow_up_result', 'rsm_reports r JOIN rsm_ad_leads l ON l.report_id = r.id', " AND COALESCE(l.follow_up_result, '') <> ''"),
        'closing_status' => $readDistinct('l.closing_status', 'rsm_reports r JOIN rsm_ad_leads l ON l.report_id = r.id', " AND COALESCE(l.closing_status, '') <> ''"),
    ];
}

function rsm_dashboard_closing_buckets(array $closingStatuses): array
{
    $registrasi = [];
    $herregistrasi = [];
    foreach ($closingStatuses as $status) {
        $statusValue = strtolower(trim((string) $status));
        if ($statusValue === '') {
            continue;
        }
        $normalized = strtolower(rsm_normalize_closing_status($statusValue));
        if ($normalized === 'closing' || $normalized === 'herregistrasi') {
            $registrasi[] = $statusValue;
        }
        if ($normalized === 'herregistrasi') {
            $herregistrasi[] = $statusValue;
        }
    }

    return [
        'registrasi' => array_values(array_unique($registrasi)),
        'herregistrasi' => array_values(array_unique($herregistrasi)),
    ];
}

function rsm_dashboard_in_condition(string $column, array $values): array
{
    if ($values === []) {
        return ['0', []];
    }
    return [
        'LOWER(TRIM(COALESCE(' . $column . ', \'\'))) IN (' . implode(',', array_fill(0, count($values), '?')) . ')',
        array_values($values),
    ];
}

function rsm_dashboard_overview(string $area, array $filters, ?array $user = null): array
{
    $statusMap = rsm_dashboard_status_map($area, $filters, $user);
    $buckets = rsm_dashboard_closing_buckets($statusMap['closing_status']);
    [$registrasiCondition, $registrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['registrasi']);
    [$herregistrasiCondition, $herregistrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['herregistrasi']);
    [$filterSql, $filterParams] = rsm_dashboard_filter_sql($filters, 'r');
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $baseParams = array_merge($registrasiParams, $herregistrasiParams, [$area], $filterParams, $scopeParams);

    $leadAggregateSql = "
        SELECT
            report_id,
            COUNT(*) AS detail_leads,
            SUM(CASE WHEN COALESCE(follow_up_result, '') <> '' OR COALESCE(progress_status, '') <> '' THEN 1 ELSE 0 END) AS detail_follow_up,
            SUM(CASE WHEN {$registrasiCondition} THEN 1 ELSE 0 END) AS detail_registrasi,
            SUM(CASE WHEN {$herregistrasiCondition} THEN 1 ELSE 0 END) AS detail_herregistrasi
        FROM rsm_ad_leads
        GROUP BY report_id
    ";

    $stmt = rsm_pdo()->prepare(
        "SELECT
            COUNT(*) AS report_count,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END), 0) AS leads_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_follow_up ELSE 0 END), 0) AS follow_up_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END), 0) AS registrasi_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_herregistrasi ELSE 0 END), 0) AS herregistrasi_total,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' AND COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads WHEN r.report_type = 'ads' THEN r.leads_count ELSE 0 END), 0) AS ads_leads,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' AND COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi WHEN r.report_type = 'ads' THEN r.closing_count ELSE 0 END), 0) AS ads_registrasi,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.budget_requested ELSE 0 END), 0) AS budget_requested,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.budget_approved ELSE 0 END), 0) AS budget_approved,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.realization_amount ELSE 0 END), 0) AS spend_total
         FROM rsm_reports r
         LEFT JOIN ({$leadAggregateSql}) la ON la.report_id = r.id
         WHERE r.area = ? {$filterSql} {$scopeSql}"
    );
    $stmt->execute($baseParams);
    $summary = $stmt->fetch() ?: [];

    $rankingStmt = rsm_pdo()->prepare(
        "SELECT
            CASE WHEN r.partner_campus_id IS NOT NULL THEN CONCAT('campus:', r.partner_campus_id) ELSE CONCAT('unit:', r.unit_name) END AS ranking_key,
            MAX(r.partner_campus_id) AS partner_campus_id,
            COALESCE(NULLIF(MAX(pc.display_name), ''), NULLIF(MAX(pc.name), ''), r.unit_name) AS unit_label,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END), 0) AS leads_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END), 0) AS registrasi_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_herregistrasi ELSE 0 END), 0) AS herregistrasi_total,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.realization_amount ELSE 0 END), 0) AS spend_total
         FROM rsm_reports r
         LEFT JOIN partner_campuses pc ON pc.id = r.partner_campus_id
         LEFT JOIN ({$leadAggregateSql}) la ON la.report_id = r.id
         WHERE r.area = ? {$filterSql} {$scopeSql}
         GROUP BY ranking_key, r.unit_name
         ORDER BY registrasi_total DESC, leads_total DESC, unit_label ASC
         LIMIT 10"
    );
    $rankingStmt->execute($baseParams);
    $ranking = array_map(static function (array $row): array {
        $leads = (float) ($row['leads_total'] ?? 0);
        $registrasi = (float) ($row['registrasi_total'] ?? 0);
        $spend = (float) ($row['spend_total'] ?? 0);
        $row['conversion_rate'] = rsm_dashboard_percent($registrasi, $leads);
        $row['cpl'] = rsm_dashboard_divide($spend, $leads);
        $row['cost_per_registrasi'] = rsm_dashboard_divide($spend, $registrasi);
        return $row;
    }, $rankingStmt->fetchAll());

    $dailyStmt = rsm_pdo()->prepare(
        "SELECT report_date, report_type, wilayah, unit_name, staff_name, category, title, result_text, obstacle_text, follow_up_text, status
         FROM rsm_reports r
         WHERE r.area = ? {$filterSql} {$scopeSql}
         ORDER BY r.report_date DESC, r.id DESC
         LIMIT 10"
    );
    $dailyStmt->execute(array_merge([$area], $filterParams, $scopeParams));

    $leads = (float) ($summary['leads_total'] ?? 0);
    $followUp = (float) ($summary['follow_up_total'] ?? 0);
    $registrasi = (float) ($summary['registrasi_total'] ?? 0);
    $herregistrasi = (float) ($summary['herregistrasi_total'] ?? 0);
    $adsLeads = (float) ($summary['ads_leads'] ?? 0);
    $adsRegistrasi = (float) ($summary['ads_registrasi'] ?? 0);
    $spend = (float) ($summary['spend_total'] ?? 0);

    return [
        'status_map' => $statusMap,
        'status_buckets' => $buckets,
        'kpi' => [
            'leads' => $leads,
            'follow_up' => $followUp,
            'registrasi' => $registrasi,
            'herregistrasi' => $herregistrasi,
            'conversion_rate' => rsm_dashboard_percent($registrasi, $leads),
        ],
        'funnel' => [
            ['label' => 'Leads', 'value' => $leads, 'rate' => 100.0],
            ['label' => 'Follow Up', 'value' => $followUp, 'rate' => rsm_dashboard_percent($followUp, $leads)],
            ['label' => 'Registrasi', 'value' => $registrasi, 'rate' => rsm_dashboard_percent($registrasi, $leads)],
            ['label' => 'Herregistrasi', 'value' => $herregistrasi, 'rate' => rsm_dashboard_percent($herregistrasi, $leads)],
        ],
        'budget' => [
            'requested' => (float) ($summary['budget_requested'] ?? 0),
            'approved' => (float) ($summary['budget_approved'] ?? 0),
            'spend' => $spend,
            'remaining' => (float) ($summary['budget_approved'] ?? 0) - $spend,
            'ads_leads' => $adsLeads,
            'ads_registrasi' => $adsRegistrasi,
            'cpl' => rsm_dashboard_divide($spend, $adsLeads),
            'cost_per_registrasi' => rsm_dashboard_divide($spend, $adsRegistrasi),
        ],
        'ranking' => $ranking,
        'daily_reports' => $dailyStmt->fetchAll(),
    ];
}

function rsm_gamification_badges_for(array $row): array
{
    $badges = [];
    if ((int) ($row['follow_up_total'] ?? 0) >= 10) {
        $badges[] = ['label' => 'Follow Up Hero', 'tone' => 'blue'];
    }
    if ((int) ($row['registrasi_total'] ?? 0) >= 3) {
        $badges[] = ['label' => 'Closing Hunter', 'tone' => 'green'];
    }
    if ((int) ($row['herreg_for_points'] ?? $row['herregistrasi_total'] ?? 0) >= 1) {
        $badges[] = ['label' => 'Herregistrasi Champion', 'tone' => 'purple'];
    }
    if ((int) ($row['report_days'] ?? 0) >= 5) {
        $badges[] = ['label' => 'Consistency Streak', 'tone' => 'amber'];
    }
    if ((float) ($row['spend_total'] ?? 0) > 0 && (float) ($row['registrasi_total'] ?? 0) > 0) {
        $badges[] = ['label' => 'Budget Efficient', 'tone' => 'cyan'];
    }

    return $badges ?: [['label' => 'On Progress', 'tone' => 'slate']];
}

function rsm_collab_history_path(): ?string
{
    $candidates = [
        getenv('RSM_COLLAB_HISTORY_PATH') ?: '',
        __DIR__ . '/../source_portal/rsm/storage/report-history.json',
        __DIR__ . '/../../source_portal/rsm/storage/report-history.json',
        __DIR__ . '/../../../source_portal/rsm/storage/report-history.json',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function rsm_collab_report_from_history(string $reportName): array
{
    $path = rsm_collab_history_path();
    if ($path === null) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    foreach ($decoded as $historyEntry) {
        foreach (($historyEntry['reports'] ?? []) as $report) {
            if (($report['name'] ?? '') === $reportName && !empty($report['tables'][0]) && is_array($report['tables'][0])) {
                return $report;
            }
        }
    }

    return [];
}

function rsm_collab_source_url(string $reportName): string
{
    return [
        'Closing Collab' => 'https://cb.web.id/pencapaian_closing_collab_template.php',
        'Herreg Collab' => 'https://cb.web.id/pencapaian_herreg_collab_template.php',
        'Closing Kampus Regional' => 'https://cb.web.id/pencapaian_closing_perkampus_peregional.php',
        'Herreg Kampus Regional' => 'https://cb.web.id/pencapaian_closing_herreg_perkampus_peregional.php',
        'Closing Personal Per Regional' => 'https://cb.web.id/pencapaian_closing_personal_per_regional.php',
        'Rekapitulasi PMB Periode Prioritas P2K' => 'https://cb.web.id/rekapitulasi_pencapaian_pmb_periode_prioritas.php?program=p2k',
    ][$reportName] ?? '';
}

function rsm_collab_known_reports(): array
{
    return [
        'Closing Collab',
        'Herreg Collab',
        'Closing Kampus Regional',
        'Herreg Kampus Regional',
        'Closing Personal Per Regional',
        'Rekapitulasi PMB Periode Prioritas P2K',
    ];
}

function rsm_collab_cache_path(): string
{
    return __DIR__ . '/runtime/cache/collab_achievement.json';
}

function rsm_bdc_report_users_cache_path(): string
{
    return __DIR__ . '/runtime/cache/bdc_report_users.json';
}

function rsm_bdc_report_users(int $maxAgeSeconds = 900): array
{
    $path = rsm_bdc_report_users_cache_path();
    if (is_file($path) && time() - (int) filemtime($path) <= $maxAgeSeconds) {
        $cached = json_decode((string) file_get_contents($path), true);
        if (is_array($cached)) {
            $cached['source_mode'] = 'cache';
            rsm_bdc_store_report_users((array) ($cached['listdata'] ?? []), (string) ($cached['fetched_at'] ?? rsm_wib_timestamp()));
            return $cached;
        }
    }

    $url = 'https://api.p2k.co.id/bdc-marketing/report-users';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: RegionalB-Dashboard/1.0\r\nAccept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $json = @file_get_contents($url, false, $context);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($decoded) || !isset($decoded['listdata']) || !is_array($decoded['listdata'])) {
        if (is_file($path)) {
            $cached = json_decode((string) file_get_contents($path), true);
            if (is_array($cached)) {
                $cached['source_mode'] = 'stale_cache';
                $cached['source_error'] = 'API tidak terbaca, memakai cache terakhir.';
                rsm_bdc_store_report_users((array) ($cached['listdata'] ?? []), (string) ($cached['fetched_at'] ?? rsm_wib_timestamp()));
                return $cached;
            }
        }
        return [
            'kode' => '',
            'message' => 'API tidak terbaca.',
            'source_url' => $url,
            'source_mode' => 'unreadable',
            'fetched_at' => rsm_wib_timestamp(),
            'listdata' => [],
        ];
    }

    $decoded['source_url'] = $url;
    $decoded['source_mode'] = 'live_api';
    $decoded['fetched_at'] = rsm_wib_timestamp();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rsm_bdc_store_report_users((array) ($decoded['listdata'] ?? []), (string) $decoded['fetched_at']);

    return $decoded;
}

function rsm_bdc_store_report_users(array $rows, string $fetchedAt): void
{
    if ($rows === []) {
        return;
    }

    try {
        $pdo = rsm_pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rsm_bdc_report_user_snapshots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_date DATE NOT NULL,
                fetched_at DATETIME NOT NULL,
                nik VARCHAR(80) NOT NULL,
                name VARCHAR(180) NOT NULL,
                campus_name VARCHAR(180) NULL,
                wilayah VARCHAR(120) NULL,
                total_count INT UNSIGNED NOT NULL DEFAULT 0,
                data_baru_count INT UNSIGNED NOT NULL DEFAULT 0,
                cold_count INT UNSIGNED NOT NULL DEFAULT 0,
                warm_count INT UNSIGNED NOT NULL DEFAULT 0,
                hot_count INT UNSIGNED NOT NULL DEFAULT 0,
                closing_count INT UNSIGNED NOT NULL DEFAULT 0,
                wawancara_count INT UNSIGNED NOT NULL DEFAULT 0,
                belum_herreg_count INT UNSIGNED NOT NULL DEFAULT 0,
                herreg_count INT UNSIGNED NOT NULL DEFAULT 0,
                fu_hari_ini_count INT UNSIGNED NOT NULL DEFAULT 0,
                raw_payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bdc_snapshot_user_day (snapshot_date, nik),
                INDEX idx_bdc_snapshot_wilayah (wilayah),
                INDEX idx_bdc_snapshot_name (name),
                INDEX idx_bdc_snapshot_fetched (fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $snapshotDate = substr($fetchedAt !== '' ? $fetchedAt : rsm_wib_timestamp(), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
            $snapshotDate = date('Y-m-d');
        }
        $regionalB = array_flip(rsm_area_regionals('Regional B'));
        $stmt = $pdo->prepare(
            "INSERT INTO rsm_bdc_report_user_snapshots
                (snapshot_date, fetched_at, nik, name, campus_name, wilayah, total_count, data_baru_count, cold_count, warm_count, hot_count, closing_count, wawancara_count, belum_herreg_count, herreg_count, fu_hari_ini_count, raw_payload)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                fetched_at = VALUES(fetched_at),
                name = VALUES(name),
                campus_name = VALUES(campus_name),
                wilayah = VALUES(wilayah),
                total_count = VALUES(total_count),
                data_baru_count = VALUES(data_baru_count),
                cold_count = VALUES(cold_count),
                warm_count = VALUES(warm_count),
                hot_count = VALUES(hot_count),
                closing_count = VALUES(closing_count),
                wawancara_count = VALUES(wawancara_count),
                belum_herreg_count = VALUES(belum_herreg_count),
                herreg_count = VALUES(herreg_count),
                fu_hari_ini_count = VALUES(fu_hari_ini_count),
                raw_payload = VALUES(raw_payload)"
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wilayah = trim((string) ($row['wilayah'] ?? ''));
            if (!isset($regionalB[$wilayah])) {
                continue;
            }
            $name = trim((string) ($row['nama'] ?? $row['name'] ?? ''));
            $nik = trim((string) ($row['nik'] ?? ''));
            if ($name === '' && $nik === '') {
                continue;
            }
            if ($nik === '') {
                $nik = rsm_username_from_nik_or_name(null, $name);
            }

            $stmt->execute([
                $snapshotDate,
                $fetchedAt !== '' ? $fetchedAt : rsm_wib_timestamp(),
                $nik,
                $name !== '' ? $name : $nik,
                trim((string) ($row['kampus'] ?? $row['campus_name'] ?? '')) ?: null,
                $wilayah !== '' ? $wilayah : null,
                (int) rsm_number_value($row['total'] ?? 0),
                (int) rsm_number_value($row['data_baru'] ?? $row['data_baru_count'] ?? 0),
                (int) rsm_number_value($row['cold'] ?? 0),
                (int) rsm_number_value($row['warm'] ?? 0),
                (int) rsm_number_value($row['hot'] ?? 0),
                (int) rsm_number_value($row['closing'] ?? 0),
                (int) rsm_number_value($row['wawancara'] ?? 0),
                (int) rsm_number_value($row['belum_herreg'] ?? 0),
                (int) rsm_number_value($row['herreg'] ?? 0),
                (int) rsm_number_value($row['fu_hari_ini'] ?? 0),
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    } catch (Throwable) {
        // BDC snapshot is a supporting cache; never block the dashboard/profile if storage fails.
    }
}

function rsm_bdc_fu_hari_ini_for_user(array $user): int
{
    try {
        rsm_bdc_report_users();
        $nik = trim((string) ($user['nik'] ?? ''));
        $name = trim((string) ($user['name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $keys = array_values(array_unique(array_filter([
            $nik !== '' ? str_replace('.', '', strtoupper($nik)) : '',
            $username !== '' ? str_replace('.', '', strtoupper($username)) : '',
        ])));

        $where = [];
        $params = [];
        foreach ($keys as $key) {
            $where[] = "REPLACE(UPPER(nik), '.', '') = ?";
            $params[] = $key;
        }
        if ($name !== '') {
            $where[] = 'LOWER(name) = LOWER(?)';
            $params[] = $name;
        }
        if ($where === []) {
            return 0;
        }

        $stmt = rsm_pdo()->prepare(
            'SELECT fu_hari_ini_count
             FROM rsm_bdc_report_user_snapshots
             WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM rsm_bdc_report_user_snapshots)
               AND (' . implode(' OR ', $where) . ')
             ORDER BY fetched_at DESC
             LIMIT 1'
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function rsm_collab_cache_read(): array
{
    $path = rsm_collab_cache_path();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function rsm_collab_build_snapshot_entry(string $reportName, array $report, string $error = ''): array
{
    $rows = $report['tables'][0] ?? [];
    $rows = is_array($rows) ? $rows : [];

    $dataStart = 0;
    if ($rows !== []) {
        $layout = rsm_collab_layout($rows);
        $dataStart = max(0, min((int) ($layout['data_start_index'] ?? 0), count($rows) - 1));
    }

    $columnCount = 0;
    foreach (array_slice($rows, $dataStart, null, true) as $row) {
        if (is_array($row)) {
            $columnCount = max($columnCount, count($row));
        }
    }
    if ($columnCount === 0) {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $columnCount = max($columnCount, count($row));
            }
        }
    }

    return [
        'name' => $reportName,
        'source_url' => (string) ($report['source_url'] ?? rsm_collab_source_url($reportName)),
        'source_mode' => (string) ($report['source_mode'] ?? ''),
        'cached_at' => (string) ($report['cached_at'] ?? ''),
        'created_at' => (string) ($report['created_at'] ?? ''),
        'error' => $error,
        'rows' => $rows,
        'row_count' => count($rows),
        'column_count' => $columnCount,
    ];
}

function rsm_collab_cache_snapshot(): array
{
    $cache = rsm_collab_cache_read();
    $reports = (array) ($cache['reports'] ?? []);
    $errors = (array) ($cache['errors'] ?? []);

    $snapshot = [];
    foreach (rsm_collab_known_reports() as $reportName) {
        $report = (array) ($reports[$reportName] ?? []);
        $snapshot[$reportName] = rsm_collab_build_snapshot_entry($reportName, $report, (string) ($errors[$reportName] ?? ''));
    }

    return [
        'synced_at' => (string) ($cache['synced_at'] ?? ''),
        'reports' => $snapshot,
    ];
}

function rsm_collab_available_report_months(string $reportName): array
{
    $stmt = rsm_pdo()->prepare(
        'SELECT report_month FROM rsm_collab_report_archive WHERE report_name = ? ORDER BY report_month DESC'
    );
    $stmt->execute([$reportName]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function rsm_collab_archive_snapshot_entry(string $reportName, string $month): array
{
    $report = rsm_collab_report_from_archive($reportName, $month);
    if ($report === []) {
        return rsm_collab_build_snapshot_entry($reportName, [], 'Belum ada arsip untuk bulan ini.');
    }
    return rsm_collab_build_snapshot_entry($reportName, $report);
}

function rsm_collab_cache_write(array $payload): void
{
    $path = rsm_collab_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function rsm_collab_archive_report(string $reportName, array $report): void
{
    $rows = $report['tables'][0] ?? [];
    if (!is_array($rows) || count($rows) < 3) {
        return;
    }
    $month = rsm_collab_report_month($rows);
    if ($month === '') {
        return;
    }
    $payload = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_collab_report_archive (report_name, report_month, payload)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$reportName, $month, $payload]);
}

function rsm_collab_report_from_archive(string $reportName, string $month): array
{
    if ($month === '') {
        return [];
    }
    $stmt = rsm_pdo()->prepare(
        'SELECT payload FROM rsm_collab_report_archive WHERE report_name = ? AND report_month = ? LIMIT 1'
    );
    $stmt->execute([$reportName, $month]);
    $payload = $stmt->fetchColumn();
    if (!is_string($payload) || $payload === '') {
        return [];
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || empty($decoded['tables'][0]) || !is_array($decoded['tables'][0])) {
        return [];
    }
    $decoded['source_mode'] = 'archive';
    return $decoded;
}

function rsm_collab_ingest_daily_metrics(string $reportName, array $report): void
{
    $rows = $report['tables'][0] ?? [];
    if (!is_array($rows) || count($rows) < 3) {
        return;
    }
    $reportMonth = rsm_collab_report_month($rows);
    if ($reportMonth === '') {
        return;
    }
    $layout = rsm_collab_layout($rows);
    $dateHeaderRow = $layout['date_row_index'] !== null ? ($rows[$layout['date_row_index']] ?? []) : [];
    if (!is_array($dateHeaderRow) || $dateHeaderRow === []) {
        return;
    }

    $isCampus = in_array($reportName, ['Closing Kampus Regional', 'Herreg Kampus Regional'], true);
    $isPersonalRegional = $reportName === 'Closing Personal Per Regional';
    $valueBase = $isCampus ? 4 : 5;

    $dayValueIndexes = [];
    $offset = 0;
    foreach ($layout['day_indexes'] as $headerIndex) {
        $dayLabel = trim((string) ($dateHeaderRow[$headerIndex] ?? ''));
        if (!preg_match('/^\d{1,2}$/', $dayLabel)) {
            continue;
        }
        $dayValueIndexes[(int) $dayLabel] = $isPersonalRegional ? $headerIndex : $valueBase + $offset;
        $offset++;
    }
    if ($dayValueIndexes === []) {
        return;
    }

    $pdo = rsm_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO rsm_collab_daily_metrics
            (report_name, metric_date, entity_key, staff_nik, staff_name, regional, campus_name, value)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            value = VALUES(value), staff_nik = VALUES(staff_nik), staff_name = VALUES(staff_name),
            regional = VALUES(regional), campus_name = VALUES(campus_name), synced_at = CURRENT_TIMESTAMP'
    );

    $pdo->beginTransaction();
    try {
        $currentRegional = null;
        foreach ($rows as $index => $row) {
            if ($index < (int) ($layout['data_start_index'] ?? 0) || !is_array($row)) {
                continue;
            }

            if ($isCampus) {
                if (count($row) < 5) {
                    continue;
                }
                $regionalLabel = trim((string) ($row[2] ?? ''));
                $campusName = trim((string) ($row[3] ?? ''));
                if ($campusName === '' || stripos($campusName, 'Total ') === 0 || !preg_match('/Regional\s+([1-7])/i', $regionalLabel, $match)) {
                    continue;
                }
                $regional = 'Regional ' . $match[1];
                $entityKey = mb_strtolower($regional . '|' . $campusName);
                $staffNik = null;
                $staffName = null;
            } elseif ($isPersonalRegional) {
                $label = trim((string) ($row[0] ?? ''));
                if ($label === '') {
                    continue;
                }
                if (preg_match('/\(Korwil Regional\s*([1-7])\)\s*$/i', $label, $korwilMatch)) {
                    $currentRegional = 'Regional ' . $korwilMatch[1];
                }
                if (preg_match('/^(Sub\.?\s*Total|Total\s|Grand\.?\s*Total)/i', $label)) {
                    if (preg_match('/\(Korwil Regional\s*[1-7]?\)/i', $label)) {
                        $currentRegional = null;
                    }
                    continue;
                }
                if ($currentRegional === null) {
                    continue;
                }
                $clean = trim((string) preg_replace('/\s*\(Korwil Regional[^)]*\)\s*$/i', '', $label));
                $staffNik = null;
                $staffName = $clean;
                if (preg_match('/^([A-Za-z]{1,4}[.\d-]+)\s*-\s*(.+)$/', $clean, $nameMatch)) {
                    $staffNik = trim($nameMatch[1]);
                    $staffName = trim($nameMatch[2]);
                }
                if ($staffName === '') {
                    continue;
                }
                $regional = $currentRegional;
                $entityKey = rsm_username_from_nik_or_name($staffNik, $staffName);
                $campusName = null;
            } else {
                $regionalDigit = trim((string) ($row[(int) $layout['regional_index']] ?? ''));
                $staffNik = trim((string) ($row[(int) $layout['staff_nik_index']] ?? ''));
                $staffName = trim((string) ($row[(int) $layout['staff_name_index']] ?? ''));
                if (!preg_match('/^SG[.\d-]+$/i', $staffNik) && preg_match('/^SG[.\d-]+$/i', trim((string) ($row[(int) $layout['staff_nik_index'] + 1] ?? '')))) {
                    $staffNik = trim((string) ($row[(int) $layout['staff_nik_index'] + 1] ?? ''));
                    $staffName = trim((string) ($row[(int) $layout['staff_name_index'] + 1] ?? $staffName));
                }
                if ($staffName === '' || !preg_match('/^[1-7]$/', $regionalDigit)) {
                    continue;
                }
                $regional = 'Regional ' . $regionalDigit;
                $entityKey = rsm_username_from_nik_or_name($staffNik !== '' ? $staffNik : null, $staffName);
                $campusName = null;
            }

            foreach ($dayValueIndexes as $dayNumber => $valueIndex) {
                $value = rsm_number_value($row[$valueIndex] ?? 0);
                $metricDate = $reportMonth . '-' . str_pad((string) $dayNumber, 2, '0', STR_PAD_LEFT);
                $stmt->execute([$reportName, $metricDate, $entityKey, $staffNik, $staffName, $regional, $campusName, $value]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function rsm_collab_daily_metrics_synced_at(string $reportName, string $dateFrom, string $dateTo): string
{
    $stmt = rsm_pdo()->prepare(
        'SELECT MAX(synced_at) FROM rsm_collab_daily_metrics WHERE report_name = ? AND metric_date BETWEEN ? AND ?'
    );
    $stmt->execute([$reportName, $dateFrom, $dateTo]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : '';
}

function rsm_collab_report_from_cache(string $reportName): array
{
    $cache = rsm_collab_cache_read();
    $report = $cache['reports'][$reportName] ?? [];
    if (!is_array($report) || empty($report['tables'][0]) || !is_array($report['tables'][0])) {
        return [];
    }
    $report['source_mode'] = (string) ($report['source_mode'] ?? 'cache_auto');
    $report['cache_synced_at'] = (string) ($cache['synced_at'] ?? '');
    $report['cache_age_seconds'] = max(0, time() - (int) ($cache['synced_at_unix'] ?? 0));
    return $report;
}

function rsm_collab_sync_cache(): array
{
    $result = [
        'synced_at' => rsm_wib_timestamp(),
        'synced_at_unix' => time(),
        'reports' => [],
        'errors' => [],
    ];
    foreach (rsm_collab_known_reports() as $reportName) {
        $report = rsm_collab_report_from_url($reportName);
        if ($report === []) {
            $result['errors'][$reportName] = 'Source tidak terbaca saat sinkronisasi.';
            continue;
        }
        $report['source_mode'] = 'cache_auto';
        $report['cached_at'] = $result['synced_at'];
        $result['reports'][$reportName] = $report;
        rsm_collab_archive_report($reportName, $report);
        if (in_array($reportName, ['Closing Collab', 'Herreg Collab', 'Closing Kampus Regional', 'Herreg Kampus Regional', 'Closing Personal Per Regional'], true)) {
            rsm_collab_ingest_daily_metrics($reportName, $report);
        }
    }

    if ($result['reports'] === []) {
        $existing = rsm_collab_cache_read();
        if ($existing !== []) {
            $existing['last_sync_error_at'] = $result['synced_at'];
            $existing['last_sync_errors'] = $result['errors'];
            rsm_collab_cache_write($existing);
        }
        return $result;
    }

    $existing = rsm_collab_cache_read();
    foreach ($existing['reports'] ?? [] as $reportName => $report) {
        if (!isset($result['reports'][$reportName]) && is_array($report)) {
            $result['reports'][$reportName] = $report;
        }
    }
    rsm_collab_cache_write($result);
    return $result;
}

function rsm_jadwal_source_url(int $zona): string
{
    return 'https://cb.web.id/media.php?p=jadwal&zona=' . $zona;
}

function rsm_jadwal_cache_path(): string
{
    return __DIR__ . '/runtime/cache/jadwal_koordinator_cb.json';
}

function rsm_jadwal_cache_read(): array
{
    $path = rsm_jadwal_cache_path();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function rsm_jadwal_cache_write(array $payload): void
{
    $path = rsm_jadwal_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function rsm_jadwal_extract_table_html(string $html, string $tableId): string
{
    if (preg_match('/<table[^>]*id="' . preg_quote($tableId, '/') . '"[^>]*>(.*?)<\/table>/is', $html, $m)) {
        $inner = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $m[1]) ?? $m[1];
        $inner = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $inner) ?? $inner;
        $inner = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $inner) ?? $inner;

        return '<table class="collab-raw-table">' . $inner . '</table>';
    }

    return '';
}

function rsm_jadwal_fetch_zona(int $zona): array
{
    $url = rsm_jadwal_source_url($zona);
    $html = rsm_collab_authenticated_html($url);
    if (trim($html) === '') {
        return ['zona' => $zona, 'source_url' => $url, 'error' => 'Source tidak terbaca (login/koneksi gagal).'];
    }
    $tableHtml = rsm_jadwal_extract_table_html($html, 'tb_jadwal');
    if ($tableHtml === '') {
        return ['zona' => $zona, 'source_url' => $url, 'error' => 'Tabel jadwal tidak ditemukan pada halaman sumber.'];
    }

    return [
        'zona' => $zona,
        'source_url' => $url,
        'table_html' => $tableHtml,
        'fetched_at' => rsm_wib_timestamp(),
    ];
}

function rsm_jadwal_sync_cache(): array
{
    $result = ['synced_at' => rsm_wib_timestamp(), 'zonas' => [], 'errors' => []];
    foreach ([2] as $zona) {
        $data = rsm_jadwal_fetch_zona($zona);
        if (!empty($data['error'])) {
            $result['errors'][$zona] = (string) $data['error'];
            continue;
        }
        $result['zonas'][$zona] = $data;
    }

    $existing = rsm_jadwal_cache_read();
    if ($result['zonas'] === []) {
        if ($existing !== []) {
            $existing['last_sync_error_at'] = $result['synced_at'];
            $existing['last_sync_errors'] = $result['errors'];
            rsm_jadwal_cache_write($existing);
        }

        return $result;
    }

    foreach ((array) ($existing['zonas'] ?? []) as $zona => $data) {
        if (!isset($result['zonas'][$zona]) && is_array($data)) {
            $result['zonas'][$zona] = $data;
        }
    }
    rsm_jadwal_cache_write($result);

    return $result;
}

function rsm_jadwal_cache_snapshot(): array
{
    return rsm_jadwal_cache_read();
}

function rsm_jadwal_koordinator_name_map(): array
{
    return [
        'Hamaruddin' => 'Hamaruddin',
        'Kundi Harto' => 'Kundi Harto',
        'M. Nor Abidin' => 'Moh Nor Abidin',
        'Nugroho Budi Santoso' => 'Nugroho Budi Santoso',
    ];
}

function rsm_jadwal_parse_zona_rows(string $tableHtml): array
{
    if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $trMatches)) {
        return [];
    }
    $rows = [];
    foreach ($trMatches[1] as $trInner) {
        if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $trInner, $tdMatches)) {
            continue;
        }
        $cells = array_map(
            static fn (string $cell): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($cell))),
            $tdMatches[1]
        );
        if (count($cells) < 36 || !preg_match('/^SG\./i', $cells[0])) {
            continue;
        }
        $days = [];
        for ($day = 1; $day <= 31; $day++) {
            $days[$day] = $cells[4 + $day] ?? '';
        }
        $rows[] = ['nik' => $cells[0], 'nama' => $cells[1], 'days' => $days];
    }

    return $rows;
}

function rsm_jadwal_koordinator_libur_map(): array
{
    $snapshot = rsm_jadwal_cache_snapshot();
    $tableHtml = (string) ($snapshot['zonas'][2]['table_html'] ?? '');
    $fetchedAt = (string) ($snapshot['zonas'][2]['fetched_at'] ?? '');
    if ($tableHtml === '' || $fetchedAt === '') {
        return ['month' => '', 'coordinators' => []];
    }
    $parsedRows = rsm_jadwal_parse_zona_rows($tableHtml);
    $coordinators = [];
    foreach (rsm_jadwal_koordinator_name_map() as $localName => $cbName) {
        foreach ($parsedRows as $parsedRow) {
            if (mb_strtolower(trim((string) $parsedRow['nama'])) !== mb_strtolower(trim($cbName))) {
                continue;
            }
            $liburDays = [];
            foreach ((array) $parsedRow['days'] as $day => $code) {
                $codeNorm = strtoupper(trim((string) $code));
                if ($codeNorm === 'L' || $codeNorm === 'LN') {
                    $liburDays[(int) $day] = $codeNorm;
                }
            }
            $coordinators[$localName] = $liburDays;
            break;
        }
    }

    return ['month' => substr($fetchedAt, 0, 7), 'coordinators' => $coordinators];
}

function rsm_sync_health_status(): array
{
    $collab = rsm_collab_cache_read();
    $bdc = [];
    try {
        $stmt = rsm_pdo()->query(
            "SELECT MAX(fetched_at) AS last_fetched_at,
                    MAX(snapshot_date) AS last_snapshot_date,
                    COUNT(*) AS rows_count
             FROM rsm_bdc_report_user_snapshots"
        );
        $bdc = $stmt ? ($stmt->fetch() ?: []) : [];
    } catch (Throwable) {
        $bdc = [];
    }

    return [
        'collab' => [
            'status' => !empty($collab['reports']) ? 'OK' : 'Belum ada cache',
            'synced_at' => (string) ($collab['synced_at'] ?? ''),
            'errors' => (array) ($collab['last_sync_errors'] ?? $collab['errors'] ?? []),
        ],
        'bdc' => [
            'status' => (int) ($bdc['rows_count'] ?? 0) > 0 ? 'OK' : 'Belum ada snapshot',
            'synced_at' => (string) ($bdc['last_fetched_at'] ?? ''),
            'snapshot_date' => (string) ($bdc['last_snapshot_date'] ?? ''),
            'rows_count' => (int) ($bdc['rows_count'] ?? 0),
        ],
    ];
}

function rsm_wib_timestamp(?string $value = null): string
{
    try {
        $timezone = new DateTimeZone('Asia/Jakarta');
        $date = $value === null || trim($value) === ''
            ? new DateTimeImmutable('now', $timezone)
            : new DateTimeImmutable($value, $timezone);

        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Exception $exception) {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}

function rsm_wib_datetime_label(?string $value = null): string
{
    return rsm_wib_timestamp($value) . ' WIB';
}

function rsm_wib_time_label(?string $value = null): string
{
    return substr(rsm_wib_timestamp($value), 11, 8) . ' WIB';
}

function rsm_collab_auth_credentials(): array
{
    $username = trim((string) (getenv('RSM_COLLAB_USERNAME') ?: getenv('CB_REKAP_USERNAME') ?: ''));
    $password = trim((string) (getenv('RSM_COLLAB_PASSWORD') ?: getenv('CB_REKAP_PASSWORD') ?: ''));
    if ($username === '' || $password === '') {
        return [];
    }

    return ['username' => $username, 'password' => $password];
}

function rsm_collab_authenticated_html(string $url): string
{
    $credentials = rsm_collab_auth_credentials();
    if ($credentials === []) {
        return '';
    }

    $cookieFile = tempnam(sys_get_temp_dir(), 'rsm_collab_');
    if (!is_string($cookieFile) || $cookieFile === '') {
        return '';
    }

    if (function_exists('curl_init')) {
        try {
            $postData = http_build_query([
                'acc_username' => $credentials['username'],
                'acc_password' => $credentials['password'],
                'bsignin' => '',
            ]);
            $login = curl_init($url);
            if ($login === false) {
                return '';
            }
            curl_setopt_array($login, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'RegionalB-Dashboard/1.0',
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            ]);
            $loginHtml = curl_exec($login);
            curl_setopt($login, CURLOPT_COOKIELIST, 'FLUSH');
            curl_close($login);

            $read = curl_init($url);
            if ($read === false) {
                return is_string($loginHtml) ? $loginHtml : '';
            }
            curl_setopt_array($read, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'RegionalB-Dashboard/1.0',
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            ]);
            $html = curl_exec($read);
            curl_close($read);

            return is_string($html) && trim($html) !== '' ? $html : (is_string($loginHtml) ? $loginHtml : '');
        } finally {
            @unlink($cookieFile);
        }
    }

    $postData = http_build_query([
        'acc_username' => $credentials['username'],
        'acc_password' => $credentials['password'],
        'bsignin' => '',
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'header' => "User-Agent: RegionalB-Dashboard/1.0\r\n"
                . "Accept: text/html,application/xhtml+xml\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($postData) . "\r\n",
            'content' => $postData,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    try {
        $html = @file_get_contents($url, false, $context);
    } finally {
        @unlink($cookieFile);
    }

    return is_string($html) ? $html : '';
}

function rsm_collab_fetch_plain_html(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: RegionalB-Dashboard/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    return is_string($html) ? $html : '';
}

function rsm_collab_curl_request(string $url, ?string $cookieFile, ?array $postFields): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'RegionalB-Dashboard/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ];
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($postFields !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    curl_setopt_array($ch, $opts);
    $html = curl_exec($ch);
    curl_close($ch);
    return is_string($html) ? $html : '';
}

function rsm_collab_auth_session_login(): ?string
{
    $credentials = rsm_collab_auth_credentials();
    if ($credentials === []) {
        return null;
    }
    $cookieFile = tempnam(sys_get_temp_dir(), 'rsm_collab_');
    if (!is_string($cookieFile) || $cookieFile === '') {
        return null;
    }
    $url = rsm_collab_source_url('Closing Kampus Regional');
    rsm_collab_curl_request($url, $cookieFile, [
        'acc_username' => $credentials['username'],
        'acc_password' => $credentials['password'],
        'bsignin' => '',
    ]);
    rsm_collab_curl_request($url, $cookieFile, null);
    return $cookieFile;
}

function rsm_collab_report_from_range(string $reportName, string $dayFrom, string $dayTo, ?string $cookieFile = null): array
{
    $url = rsm_collab_source_url($reportName);
    if ($url === '') {
        return [];
    }

    $isAuthenticated = in_array($reportName, ['Closing Kampus Regional', 'Herreg Kampus Regional', 'Closing Personal Per Regional', 'Rekapitulasi PMB Periode Prioritas P2K'], true);
    $postFields = ['filter_dayone' => $dayFrom, 'filter_daytwo' => $dayTo, 'bmonth' => 'View'];

    if ($isAuthenticated) {
        if ($cookieFile === null || !is_file($cookieFile)) {
            return [];
        }
        $html = rsm_collab_curl_request($url, $cookieFile, $postFields);
    } else {
        $html = rsm_collab_curl_request($url, null, $postFields);
    }

    if (trim($html) === '') {
        return [];
    }

    $tables = rsm_collab_parse_html_tables($html);
    if ($tables === []) {
        return [];
    }

    return [
        'name' => $reportName,
        'created_at' => rsm_wib_timestamp(),
        'source_url' => $url,
        'source_mode' => 'backfill_range',
        'tables' => $tables,
    ];
}

function rsm_collab_parse_html_tables(string $html): array
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;

    $tables = [];
    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded) {
            foreach ($dom->getElementsByTagName('table') as $table) {
                $rows = [];
                $rowSpans = [];
                foreach ($table->getElementsByTagName('tr') as $tr) {
                    $tds = [];
                    foreach ($tr->childNodes as $node) {
                        if (in_array(strtolower((string) $node->nodeName), ['td', 'th'], true)) {
                            $tds[] = $node;
                        }
                    }

                    if (count($tds) === 1 && (int) $tds[0]->getAttribute('colspan') > 1) {
                        continue;
                    }

                    $cells = [];
                    $col = 0;
                    $tdIndex = 0;
                    while ($tdIndex < count($tds) || isset($rowSpans[$col])) {
                        if (isset($rowSpans[$col]) && $rowSpans[$col]['remaining'] > 0) {
                            $cells[$col] = $rowSpans[$col]['value'];
                            $rowSpans[$col]['remaining']--;
                            if ($rowSpans[$col]['remaining'] <= 0) {
                                unset($rowSpans[$col]);
                            }
                            $col++;
                            continue;
                        }
                        if ($tdIndex >= count($tds)) {
                            break;
                        }
                        $node = $tds[$tdIndex];
                        $tdIndex++;
                        $text = trim(preg_replace('/\s+/', ' ', (string) $node->textContent) ?? '');
                        $colspan = max(1, (int) $node->getAttribute('colspan') ?: 1);
                        $rowspan = max(1, (int) $node->getAttribute('rowspan') ?: 1);
                        for ($i = 0; $i < $colspan; $i++) {
                            $cells[$col] = $text;
                            if ($rowspan > 1) {
                                $rowSpans[$col] = ['value' => $text, 'remaining' => $rowspan - 1];
                            }
                            $col++;
                        }
                    }

                    ksort($cells);
                    $cells = array_values($cells);
                    if ($cells !== []) {
                        $rows[] = $cells;
                    }
                }
                if (count($rows) >= 3) {
                    $tables[] = $rows;
                }
            }
        }
    }
    if ($tables === [] && preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $matches)) {
        $rows = [];
        foreach ($matches[1] as $rowHtml) {
            if (!preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = array_map(static function (string $cellHtml): string {
                return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cellHtml), ENT_QUOTES, 'UTF-8')) ?? '');
            }, $cellMatches[1]);
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if (count($rows) >= 3) {
            $tables[] = $rows;
        }
    }

    if ($tables === []) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?: [])));
        $rows = [];
        foreach ($lines as $line) {
            if (!str_contains($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', $line));
            if (count($cells) >= 5) {
                $rows[] = $cells;
            }
        }
        if (count($rows) >= 3) {
            $tables[] = $rows;
        }
    }

    return $tables;
}

function rsm_collab_report_from_url(string $reportName): array
{
    $url = rsm_collab_source_url($reportName);
    if ($url === '') {
        return [];
    }

    $isAuthenticated = in_array($reportName, ['Closing Kampus Regional', 'Herreg Kampus Regional', 'Closing Personal Per Regional', 'Rekapitulasi PMB Periode Prioritas P2K'], true);
    $html = $isAuthenticated ? rsm_collab_authenticated_html($url) : rsm_collab_fetch_plain_html($url);
    if (!$isAuthenticated && trim($html) === '') {
        usleep(500000);
        $html = rsm_collab_fetch_plain_html($url);
    }
    if (trim($html) === '') {
        return [];
    }

    $tables = rsm_collab_parse_html_tables($html);
    if ($tables === []) {
        return [];
    }

    return [
        'name' => $reportName,
        'created_at' => rsm_wib_timestamp(),
        'source_url' => $url,
        'source_mode' => 'live_url',
        'tables' => $tables,
    ];
}

function rsm_collab_report(string $reportName, string $month = ''): array
{
    $cache = rsm_collab_report_from_cache($reportName);
    if ($cache !== []) {
        $cacheMonth = rsm_collab_report_month($cache['tables'][0] ?? []);
        if ($month === '' || $cacheMonth === $month) {
            return $cache;
        }
        $archived = rsm_collab_report_from_archive($reportName, $month);
        if ($archived !== []) {
            return $archived;
        }
        return $cache;
    }

    if ($month !== '') {
        $archived = rsm_collab_report_from_archive($reportName, $month);
        if ($archived !== []) {
            return $archived;
        }
    }

    $history = rsm_collab_report_from_history($reportName);
    if ($history !== []) {
        $history['source_mode'] = 'history_snapshot';
        return $history;
    }

    $live = rsm_collab_report_from_url($reportName);
    if ($live !== []) {
        $live['source_mode'] = 'live_url_uncached';
    }
    return $live;
}

function rsm_collab_day_indexes(array $headerRow): array
{
    $dayIndexes = [];
    $totalIndex = null;
    foreach ($headerRow as $index => $label) {
        $label = trim((string) $label);
        if (preg_match('/^\d{1,2}$/', $label)) {
            $dayIndexes[] = (int) $index;
            continue;
        }
        if (strtolower($label) === 'total') {
            $totalIndex = (int) $index;
        }
    }

    return [$dayIndexes, $totalIndex];
}

function rsm_collab_layout(array $rows): array
{
    $monthRowIndex = null;
    $dateRowIndex = null;
    $month = '';
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $cell) {
            $label = trim((string) $cell);
            if ($month === '' && preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\b\s+\d{4}/i', $label, $match)) {
                $month = $match[0];
                $monthRowIndex = (int) $rowIndex;
            }
        }
    }

    if ($monthRowIndex !== null) {
        $candidateRow = $rows[$monthRowIndex + 1] ?? null;
        if (is_array($candidateRow)) {
            [$candidateDayIndexes] = rsm_collab_day_indexes($candidateRow);
            if ($candidateDayIndexes !== []) {
                $dateRowIndex = $monthRowIndex + 1;
            }
        }
    }
    if ($dateRowIndex === null) {
        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            [$dayIndexes] = rsm_collab_day_indexes($row);
            if ($dayIndexes !== [] && count($dayIndexes) >= 3) {
                $dateRowIndex = (int) $rowIndex;
                break;
            }
        }
    }

    $headerRow = $dateRowIndex !== null ? ($rows[$dateRowIndex] ?? []) : [];
    [$dayIndexes, $totalIndex] = is_array($headerRow) ? rsm_collab_day_indexes($headerRow) : [[], null];
    if ($totalIndex === null && $dayIndexes !== []) {
        $totalIndex = max($dayIndexes) + 1;
    }

    $lowerHeader = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), is_array($headerRow) ? $headerRow : []);
    $findHeader = static function (array $needles) use ($lowerHeader): ?int {
        foreach ($lowerHeader as $index => $label) {
            foreach ($needles as $needle) {
                if ($label === $needle || str_contains($label, $needle)) {
                    return (int) $index;
                }
            }
        }
        return null;
    };

    return [
        'month_label' => $month,
        'month_row_index' => $monthRowIndex,
        'date_row_index' => $dateRowIndex,
        'data_start_index' => $dateRowIndex !== null ? $dateRowIndex + 1 : 2,
        'day_indexes' => $dayIndexes,
        'total_index' => $totalIndex,
        'regional_index' => $findHeader(['wilayah']) ?? 0,
        'staff_nik_index' => $findHeader(['nik staff']) ?? 3,
        'staff_name_index' => $findHeader(['nama staff']) ?? 4,
    ];
}

function rsm_collab_report_month(array $rows): string
{
    $layout = rsm_collab_layout($rows);
    $label = trim((string) ($layout['month_label'] ?? ''));
    if ($label === '') {
        return '';
    }

    $timestamp = strtotime('1 ' . $label);
    return $timestamp ? date('Y-m', $timestamp) : '';
}

function rsm_collab_staff_totals(string $reportName, array $filters, string $area = 'Regional', ?array $user = null): array
{
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    if ($dateFrom === '' || $dateTo === '') {
        return [
            '__meta' => [
                'source_url' => rsm_collab_source_url($reportName),
                'source_mode' => 'no_range',
                'report_month' => '',
                'source_time' => '',
            ],
        ];
    }

    $allowedRegionals = rsm_area_regionals($area);
    $filterRegional = (string) ($filters['wilayah'] ?? '');
    $filterStaff = (string) ($filters['staff_name'] ?? '');
    if ($filterRegional !== '') {
        $allowedRegionals = [$filterRegional];
    }
    if (($user['role'] ?? '') === 'koordinator' && !empty($user['regional'])) {
        $allowedRegionals = [(string) $user['regional']];
    }
    if (($user['role'] ?? '') === 'staff') {
        if (!empty($user['regional'])) {
            $allowedRegionals = [(string) $user['regional']];
        }
        if ($filterStaff === '' && !empty($user['name'])) {
            $filterStaff = (string) $user['name'];
        }
    }

    $where = ['report_name = ?', 'metric_date BETWEEN ? AND ?'];
    $params = [$reportName, $dateFrom, $dateTo];
    if ($allowedRegionals !== []) {
        $where[] = 'regional IN (' . implode(',', array_fill(0, count($allowedRegionals), '?')) . ')';
        array_push($params, ...$allowedRegionals);
    }
    if ($filterStaff !== '') {
        $where[] = 'staff_name = ?';
        $params[] = $filterStaff;
    }

    $stmt = rsm_pdo()->prepare(
        'SELECT entity_key, MAX(staff_nik) AS staff_nik, MAX(staff_name) AS staff_name, MAX(regional) AS regional, SUM(value) AS total_value
         FROM rsm_collab_daily_metrics
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY entity_key'
    );
    $stmt->execute($params);
    $dbRows = $stmt->fetchAll();
    $syncedAt = rsm_collab_daily_metrics_synced_at($reportName, $dateFrom, $dateTo);

    $totals = [];
    foreach ($dbRows as $row) {
        $totals[(string) $row['entity_key']] = [
            'nik' => (string) ($row['staff_nik'] ?? ''),
            'name' => (string) ($row['staff_name'] ?? ''),
            'regional' => (string) ($row['regional'] ?? ''),
            'value' => (float) $row['total_value'],
            'source_url' => rsm_collab_source_url($reportName),
            'source_mode' => 'daily_metrics',
            'report_month' => substr($dateFrom, 0, 7),
            'source_time' => $syncedAt,
        ];
    }

    $totals['__meta'] = [
        'source_url' => rsm_collab_source_url($reportName),
        'source_mode' => $totals === [] ? 'daily_metrics_empty' : 'daily_metrics',
        'report_month' => substr($dateFrom, 0, 7),
        'source_time' => $syncedAt,
    ];
    return $totals;
}

function rsm_collab_staff_performance(string $area, array $filters, ?array $user = null): array
{
    $closing = rsm_collab_staff_totals('Closing Collab', $filters, $area, $user);
    $herreg = rsm_collab_staff_totals('Herreg Collab', $filters, $area, $user);
    $closingMeta = $closing['__meta'] ?? [];
    $herregMeta = $herreg['__meta'] ?? [];
    unset($closing['__meta'], $herreg['__meta']);
    $keys = array_values(array_unique(array_merge(array_keys($closing), array_keys($herreg))));
    $rows = [];
    foreach ($keys as $key) {
        $base = $closing[$key] ?? $herreg[$key] ?? [];
        $registrasi = (float) ($closing[$key]['value'] ?? 0);
        $herregistrasi = (float) ($herreg[$key]['value'] ?? 0);
        $rows[] = [
            'staff_key' => $key,
            'nik' => (string) ($base['nik'] ?? ''),
            'name' => (string) ($base['name'] ?? ''),
            'regional' => (string) ($base['regional'] ?? ''),
            'registrasi' => $registrasi,
            'herregistrasi' => $herregistrasi,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $a['regional'], (string) $b['regional'])
            ?: ((float) $b['registrasi'] <=> (float) $a['registrasi'])
            ?: ((float) $b['herregistrasi'] <=> (float) $a['herregistrasi'])
            ?: strcmp((string) $a['name'], (string) $b['name']);
    });

    $regionalSummary = [];
    foreach ($rows as $row) {
        $regional = (string) ($row['regional'] ?: 'Tanpa Regional');
        if (!isset($regionalSummary[$regional])) {
            $regionalSummary[$regional] = [
                'regional' => $regional,
                'staff_count' => 0,
                'registrasi' => 0.0,
                'herregistrasi' => 0.0,
                'total' => 0.0,
            ];
        }
        $regionalSummary[$regional]['staff_count']++;
        $regionalSummary[$regional]['registrasi'] += (float) $row['registrasi'];
        $regionalSummary[$regional]['herregistrasi'] += (float) $row['herregistrasi'];
        $regionalSummary[$regional]['total'] += (float) $row['registrasi'] + (float) $row['herregistrasi'];
    }

    ksort($regionalSummary);

    return [
        'rows' => $rows,
        'regional_summary' => array_values($regionalSummary),
        'totals' => [
            'staff_count' => count($rows),
            'registrasi' => array_sum(array_map(static fn (array $row): float => (float) $row['registrasi'], $rows)),
            'herregistrasi' => array_sum(array_map(static fn (array $row): float => (float) $row['herregistrasi'], $rows)),
        ],
        'sources' => [
            'registrasi' => [
                'label' => $closing !== [] ? 'Closing Collab' : 'Belum terbaca',
                'url' => rsm_collab_source_url('Closing Collab'),
                'mode' => (string) ($closingMeta['source_mode'] ?? ''),
                'month' => (string) ($closingMeta['report_month'] ?? ''),
                'time' => (string) ($closingMeta['source_time'] ?? ''),
            ],
            'herregistrasi' => [
                'label' => $herreg !== [] ? 'Herreg Collab' : 'Belum terbaca',
                'url' => rsm_collab_source_url('Herreg Collab'),
                'mode' => (string) ($herregMeta['source_mode'] ?? ''),
                'month' => (string) ($herregMeta['report_month'] ?? ''),
                'time' => (string) ($herregMeta['source_time'] ?? ''),
            ],
        ],
    ];
}

function rsm_collab_campus_totals(array $filters, string $area = 'Regional', ?array $user = null): array
{
    $reportName = 'Closing Kampus Regional';
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    if ($dateFrom === '' || $dateTo === '') {
        return [
            '__meta' => [
                'source_url' => rsm_collab_source_url($reportName),
                'source_mode' => 'no_range',
                'report_month' => '',
                'source_time' => '',
            ],
        ];
    }

    $allowedRegionals = rsm_area_regionals($area);
    $filterRegional = trim((string) ($filters['wilayah'] ?? ''));
    $filterUnit = trim((string) ($filters['unit_name'] ?? ''));
    if ($filterRegional !== '') {
        $allowedRegionals = [$filterRegional];
    }
    if (($user['role'] ?? '') === 'koordinator' && !empty($user['regional'])) {
        $allowedRegionals = [(string) $user['regional']];
    }
    if (($user['role'] ?? '') === 'staff' && !empty($user['regional'])) {
        $allowedRegionals = [(string) $user['regional']];
    }

    $where = ['report_name = ?', 'metric_date BETWEEN ? AND ?'];
    $params = [$reportName, $dateFrom, $dateTo];
    if ($allowedRegionals !== []) {
        $where[] = 'regional IN (' . implode(',', array_fill(0, count($allowedRegionals), '?')) . ')';
        array_push($params, ...$allowedRegionals);
    }
    if ($filterUnit !== '') {
        $where[] = 'campus_name = ?';
        $params[] = $filterUnit;
    }

    $stmt = rsm_pdo()->prepare(
        'SELECT entity_key, MAX(regional) AS regional, MAX(campus_name) AS campus_name, SUM(value) AS total_value
         FROM rsm_collab_daily_metrics
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY entity_key'
    );
    $stmt->execute($params);
    $dbRows = $stmt->fetchAll();
    $syncedAt = rsm_collab_daily_metrics_synced_at($reportName, $dateFrom, $dateTo);

    $totals = [];
    foreach ($dbRows as $row) {
        $totals[(string) $row['entity_key']] = [
            'regional' => (string) ($row['regional'] ?? ''),
            'unit' => (string) ($row['campus_name'] ?? ''),
            'registrasi' => (float) $row['total_value'],
            'source_url' => rsm_collab_source_url($reportName),
            'source_mode' => 'daily_metrics',
            'report_month' => substr($dateFrom, 0, 7),
            'source_time' => $syncedAt,
        ];
    }

    $totals['__meta'] = [
        'source_url' => rsm_collab_source_url($reportName),
        'source_mode' => $totals === [] ? 'daily_metrics_empty' : 'daily_metrics',
        'report_month' => substr($dateFrom, 0, 7),
        'source_time' => $syncedAt,
    ];
    return $totals;
}

function rsm_achievement_whatsapp_dir(): string
{
    return __DIR__ . '/runtime/generated/achievement';
}

function rsm_achievement_whatsapp_public_dir(): string
{
    return 'runtime/generated/achievement';
}

function rsm_achievement_whatsapp_default_filters(): array
{
    return rsm_dashboard_filters_from_request([
        'page' => 'dashboard',
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
    ]);
}

function rsm_achievement_whatsapp_payload(string $area, ?array $filters = null, ?array $authUser = null, ?string $generatedAt = null): array
{
    $filters = $filters ?: rsm_achievement_whatsapp_default_filters();
    $generatedAt = rsm_wib_timestamp($generatedAt);
    $achievement = rsm_collab_staff_performance($area, $filters, $authUser);
    $campusAchievement = rsm_collab_campus_totals($filters, $area, $authUser);
    $campusMeta = $campusAchievement['__meta'] ?? [];
    unset($campusAchievement['__meta']);
    $users = rsm_users($area);
    $actualRole = (string) ($authUser['role'] ?? '');
    $authRegional = trim((string) ($authUser['regional'] ?? ''));
    $authName = mb_strtolower(trim((string) ($authUser['name'] ?? '')));
    $authNik = mb_strtolower(trim((string) ($authUser['nik'] ?? '')));
    $regionals = rsm_area_regionals($area);
    if (in_array($actualRole, ['koordinator', 'staff'], true) && $authRegional !== '') {
        $regionals = [$authRegional];
    }
    $regionalLookup = array_fill_keys(array_map(static fn (string $value): string => mb_strtolower($value), $regionals), true);

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
        if (in_array($role, ['koordinator', 'staff'], true) && $regionalLookup !== [] && $userRegional !== '' && !isset($regionalLookup[mb_strtolower($userRegional)])) {
            continue;
        }
        if ($actualRole === 'staff') {
            $userName = mb_strtolower(trim((string) ($user['name'] ?? '')));
            $userNik = mb_strtolower(trim((string) ($user['nik'] ?? '')));
            if (!in_array($role, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true) && $userName !== $authName && ($authNik === '' || $userNik !== $authNik)) {
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
        if (in_array($role, ['super_user', 'executive_director', 'director', 'senior'], true) && !$senior) {
            $senior = $user;
        }
        if ($role === 'koordinator') {
            rsm_add_unique_coordinator($coordinators, (string) (($user['regional'] ?? '') ?: 'Tanpa Regional'), $user);
        }
    }

    $regionalUnits = [];
    $canUseCampusSource = trim((string) ($filters['staff_name'] ?? '')) === '' && $actualRole !== 'staff';
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
        if ($regionalLookup !== [] && !isset($regionalLookup[mb_strtolower($regional)])) {
            continue;
        }
        $rawUnit = (string) ($staffUser['campus_name'] ?? '');
        $unit = $rawUnit !== '' ? rsm_campus_canonical_label($rawUnit) : 'Unit belum diatur';
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
    if ($canUseCampusSource) {
        foreach ($campusAchievement as $campusRow) {
            if (!is_array($campusRow)) {
                continue;
            }
            $registrasi = (float) ($campusRow['registrasi'] ?? 0);
            if ($registrasi <= 0) {
                continue;
            }
            $regional = (string) (($campusRow['regional'] ?? '') ?: 'Tanpa Regional');
            $rawUnit = (string) ($campusRow['unit'] ?? '');
            $unit = $rawUnit !== '' ? rsm_campus_canonical_label($rawUnit) : 'Unit belum diatur';
            if ($regionalLookup !== [] && !isset($regionalLookup[mb_strtolower($regional)])) {
                continue;
            }
            if (!isset($regionalUnits[$regional][$unit])) {
                $regionalUnits[$regional][$unit] = [
                    'unit' => $unit,
                    'registrasi' => 0.0,
                    'herregistrasi' => 0.0,
                    'staff' => [],
                ];
            }
            $regionalUnits[$regional][$unit]['registrasi'] = max((float) ($regionalUnits[$regional][$unit]['registrasi'] ?? 0), $registrasi);
            $regionalUnits[$regional][$unit]['source'] = 'Closing Kampus Regional';
        }
    }

    $regionalCards = [];
    $visibleTotal = 0.0;
    foreach ($regionals as $regional) {
        $units = array_values($regionalUnits[$regional] ?? []);
        usort($units, static fn (array $a, array $b): int => ((float) $b['registrasi'] <=> (float) $a['registrasi']) ?: strcmp((string) $a['unit'], (string) $b['unit']));
        foreach ($units as &$unit) {
            usort($unit['staff'], static fn (array $a, array $b): int => ((float) $b['registrasi'] <=> (float) $a['registrasi']) ?: strcmp((string) $a['name'], (string) $b['name']));
        }
        unset($unit);
        $regionalTotal = array_sum(array_map(static fn (array $unit): float => (float) $unit['registrasi'], $units));
        $visibleTotal += $regionalTotal;
        $korwil = $coordinators[$regional][0] ?? ['name' => 'Korwil belum diatur'];
        $regionalCards[] = [
            'regional' => $regional,
            'registrasi' => $regionalTotal,
            'korwil' => [
                'name' => (string) (($korwil['name'] ?? '') ?: 'Korwil belum diatur'),
                'photo_path' => (string) ($korwil['photo_path'] ?? ''),
                'registrasi' => $regionalTotal,
            ],
            'units' => $units,
        ];
    }

    return [
        'area' => $area,
        'generated_at' => $generatedAt,
        'period' => [
            'date_from' => (string) ($filters['date_from'] ?? ''),
            'date_to' => (string) ($filters['date_to'] ?? ''),
        ],
        'leader' => [
            'label' => $actualRole === 'staff' ? 'Staff' : ($actualRole === 'koordinator' ? 'Korwil' : 'Senior Manager'),
            'name' => (string) (($actualRole !== '' ? ($authUser['name'] ?? '') : ($senior['name'] ?? '')) ?: 'Senior Manager'),
            'photo_path' => (string) (($actualRole !== '' ? ($authUser['photo_path'] ?? '') : ($senior['photo_path'] ?? '')) ?: ''),
            'registrasi' => $visibleTotal,
        ],
        'totals' => [
            'registrasi' => $visibleTotal,
            'herregistrasi' => array_sum(array_map(static fn (array $row): float => (float) ($row['herregistrasi'] ?? 0), $achievement['rows'] ?? [])),
        ],
        'sources' => [
            'campus' => [
                'label' => $campusAchievement !== [] ? 'Closing Kampus Regional' : 'Belum terbaca',
                'url' => rsm_collab_source_url('Closing Kampus Regional'),
                'mode' => (string) ($campusMeta['source_mode'] ?? ''),
                'month' => (string) ($campusMeta['report_month'] ?? ''),
                'time' => (string) ($campusMeta['source_time'] ?? ''),
            ],
        ],
        'regionals' => $regionalCards,
    ];
}

function rsm_achievement_whatsapp_text(array $payload): string
{
    $lines = ['*Laporan Pencapaian Regional B*'];
    $period = $payload['period'] ?? [];
    $dateFrom = (string) ($period['date_from'] ?? '');
    $dateTo = (string) ($period['date_to'] ?? '');
    $periodText = $dateFrom === $dateTo || $dateTo === ''
        ? rsm_format_date_long_id($dateFrom)
        : rsm_format_date_long_id($dateFrom) . ' s/d ' . rsm_format_date_long_id($dateTo);
    $lines[] = 'Periode : ' . $periodText;
    $lines[] = 'Sinkron : ' . rsm_wib_time_label((string) ($payload['generated_at'] ?? rsm_wib_timestamp()));
    $lines[] = '___________________________________________';
    $lines[] = '';
    $leader = $payload['leader'] ?? [];
    $lines[] = '*' . (string) ($leader['label'] ?? 'Senior Manager') . ': ' . (string) ($leader['name'] ?? '-') . ' - ' . number_format((float) ($leader['registrasi'] ?? 0), 0, ',', '.') . ' closing*';
    $lines[] = '';

    foreach (($payload['regionals'] ?? []) as $regional) {
        $korwil = $regional['korwil'] ?? [];
        $lines[] = '*' . (string) ($regional['regional'] ?? '-') . ' - ' . (string) ($korwil['name'] ?? '-') . ' = ' . number_format((float) ($regional['registrasi'] ?? 0), 0, ',', '.') . '*';
        $units = $regional['units'] ?? [];
        if (!$units) {
            $lines[] = '- Belum ada unit closing';
        }
        foreach ($units as $unit) {
            $unitClosing = (float) ($unit['registrasi'] ?? 0);
            $staffTotal = array_sum(array_map(static fn (array $staff): float => (float) ($staff['registrasi'] ?? 0), is_array($unit['staff'] ?? null) ? $unit['staff'] : []));
            $unitSuffix = abs($unitClosing - $staffTotal) > 0.0001 ? ' closing kampus' : '';
            $lines[] = '> - ' . (string) ($unit['unit'] ?? '-') . ': ' . number_format($unitClosing, 0, ',', '.') . $unitSuffix;
            if ($unitSuffix === '') {
                foreach (($unit['staff'] ?? []) as $staff) {
                    $staffClosing = (float) ($staff['registrasi'] ?? 0);
                    $lines[] = '- ' . (string) ($staff['name'] ?? '-') . ': ' . number_format($staffClosing, 0, ',', '.') . ' closing staff';
                }
            }
        }
        $lines[] = '';
    }

    return trim(implode("\n", $lines));
}

function rsm_format_date_long_id(string $date): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date !== '' ? $date : '-';
    }
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $date;
    }
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    return date('j', $timestamp) . ' ' . ($months[(int) date('n', $timestamp)] ?? date('F', $timestamp)) . ' ' . date('Y', $timestamp);
}

function rsm_time_only(string $timestamp): string
{
    if (preg_match('/(\d{2}:\d{2}:\d{2})/', $timestamp, $matches)) {
        return $matches[1];
    }
    $time = strtotime($timestamp);
    return $time ? date('H:i:s', $time) : date('H:i:s');
}

function rsm_format_date_id(string $date): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date !== '' ? $date : '-';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : $date;
}

function rsm_generate_achievement_whatsapp_artifact(string $area, ?array $filters = null, ?array $authUser = null): array
{
    $generatedAt = rsm_wib_timestamp();
    $payload = rsm_achievement_whatsapp_payload($area, $filters, $authUser, $generatedAt);
    $text = rsm_achievement_whatsapp_text($payload);
    $dir = rsm_achievement_whatsapp_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $timestamp = preg_replace('/[^0-9]/', '', $generatedAt) ?: date('YmdHis');
    $timestamp = substr($timestamp, 0, 8) . '-' . substr($timestamp, 8, 6);
    $base = 'pencapaian-' . strtolower(str_replace(' ', '-', $area)) . '-' . $timestamp;
    $textFile = $base . '.txt';
    $jsonFile = $base . '.json';
    file_put_contents($dir . '/' . $textFile, $text, LOCK_EX);
    file_put_contents($dir . '/' . $jsonFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $imageFile = null;
    $imageError = null;
    try {
        $imageFile = rsm_generate_achievement_whatsapp_image($payload, $dir, $base . '.png');
    } catch (Throwable $error) {
        $imageError = $error->getMessage();
    }

    $index = [
        'generated_at' => (string) ($payload['generated_at'] ?? rsm_wib_timestamp()),
        'area' => $area,
        'period' => $payload['period'] ?? [],
        'text_file' => rsm_achievement_whatsapp_public_dir() . '/' . $textFile,
        'json_file' => rsm_achievement_whatsapp_public_dir() . '/' . $jsonFile,
        'image_file' => $imageFile ? rsm_achievement_whatsapp_public_dir() . '/' . $imageFile : null,
        'image_error' => $imageError,
        'text' => $text,
    ];
    file_put_contents($dir . '/latest.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $index;
}

function rsm_generate_achievement_whatsapp_image(array $payload, string $dir, string $filename): string
{
    $browser = rsm_headless_browser_binary();
    if ($browser !== null) {
        $htmlFile = preg_replace('/\.png$/', '.html', $filename) ?: ($filename . '.html');
        $htmlPath = $dir . '/' . $htmlFile;
        $pngPath = $dir . '/' . $filename;
        $screenshotPath = $pngPath;
        $copyScreenshot = false;
        if (str_contains($browser, '/snap/') && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $screenshotPath = '/root/' . basename($filename);
            $copyScreenshot = true;
        }
        file_put_contents($htmlPath, rsm_achievement_whatsapp_image_html($payload), LOCK_EX);

        $height = rsm_achievement_whatsapp_image_height($payload);
        $targetUrl = 'https://regionalb.online/' . rsm_achievement_whatsapp_public_dir() . '/' . $htmlFile;
        $command = escapeshellarg($browser)
            . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --ignore-certificate-errors'
            . ' --host-resolver-rules=' . escapeshellarg('MAP regionalb.online 127.0.0.1')
            . ' --window-size=2048,' . $height
            . ' --run-all-compositor-stages-before-draw --virtual-time-budget=1200'
            . ' --screenshot=' . escapeshellarg($screenshotPath)
            . ' ' . escapeshellarg($targetUrl);
        $output = [];
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);
        if ($code === 0 && is_file($screenshotPath) && filesize($screenshotPath) > 0) {
            if ($copyScreenshot) {
                @copy($screenshotPath, $pngPath);
                @unlink($screenshotPath);
            }
            if (!is_file($pngPath) || filesize($pngPath) <= 0) {
                throw new RuntimeException('Screenshot browser berhasil tetapi file tujuan tidak bisa ditulis.');
            }
            return $filename;
        }
    }

    return rsm_generate_achievement_whatsapp_image_gd($payload, $dir, $filename);
}

function rsm_headless_browser_binary(): ?string
{
    foreach (['/snap/bin/chromium', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', 'chromium', 'chromium-browser', 'google-chrome'] as $candidate) {
        if (str_contains($candidate, '/') && is_file($candidate)) {
            return $candidate;
        }
        if (!str_contains($candidate, '/')) {
            $output = [];
            $code = 1;
            @exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null', $output, $code);
            if ($code === 0 && trim((string) ($output[0] ?? '')) !== '') {
                return trim((string) $output[0]);
            }
        }
    }
    return null;
}

function rsm_achievement_whatsapp_image_height(array $payload): int
{
    $regionals = $payload['regionals'] ?? [];
    $cardHeights = [];
    foreach ($regionals as $regional) {
        $units = $regional['units'] ?? [];
        $staffRows = 0;
        foreach ($units as $unit) {
            $staffRows += max(1, count($unit['staff'] ?? []));
        }
        $cardHeights[] = 150 + ($units ? (34 + (count($units) * 112) + ($staffRows * 22)) : 170);
    }
    $left = ($cardHeights[0] ?? 320) + ($cardHeights[2] ?? 320) + 18;
    $right = ($cardHeights[1] ?? 320) + ($cardHeights[3] ?? 320) + 18;
    return max(1200, min(4096, 240 + max($left, $right) + 120));
}

function rsm_achievement_whatsapp_image_html(array $payload): string
{
    $leader = is_array($payload['leader'] ?? null) ? $payload['leader'] : [];
    $regionals = is_array($payload['regionals'] ?? null) ? $payload['regionals'] : [];
    $styleVersion = is_file(__DIR__ . '/assets/style.css') ? (string) @filemtime(__DIR__ . '/assets/style.css') : '';
    $styleHref = '/assets/style.css' . ($styleVersion !== '' ? '?v=' . rawurlencode($styleVersion) : '');
    $html = '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="' . rsm_html($styleHref) . '"></head><body>';
    $html .= '<main class="main">';
    $html .= '<section class="panel achievement-report-panel">';
    $html .= '<div class="panel-head"><div><h2>Laporan Pencapaian</h2><span>Regional/kampus memakai closing kampus; staff memakai closing staff</span></div></div>';
    $html .= '<div class="achievement-report">';
    $html .= '<article class="achievement-leader">' . rsm_achievement_whatsapp_person_html($leader, (string) ($leader['label'] ?? 'Senior Manager'), (float) ($leader['registrasi'] ?? 0), true, 'closing kampus') . '</article>';
    $html .= '<div class="achievement-regional-grid">';

    for ($columnIndex = 0; $columnIndex < 2; $columnIndex++) {
        $html .= '<div class="achievement-regional-column">';
        foreach ($regionals as $regionalIndex => $regional) {
            if ($regionalIndex % 2 !== $columnIndex || !is_array($regional)) {
                continue;
            }
            $regionalName = (string) ($regional['regional'] ?? '-');
            $regionalClass = 'regional-tone-' . strtolower(str_replace(' ', '-', $regionalName));
            $regionalClosing = (float) ($regional['registrasi'] ?? 0);
            $korwil = is_array($regional['korwil'] ?? null) ? $regional['korwil'] : ['name' => 'Korwil belum diatur'];
            $units = is_array($regional['units'] ?? null) ? $regional['units'] : [];

            $html .= '<article class="achievement-regional-card ' . (!$units ? 'no-closing ' : '') . rsm_html($regionalClass) . '">';
            $html .= '<div class="achievement-regional-head">';
            $html .= '<div class="achievement-regional-title"><span>' . rsm_html($regionalName) . '</span><strong>' . rsm_html(number_format($regionalClosing, 0, ',', '.')) . ' closing kampus</strong></div>';
            $html .= '<div class="achievement-korwil-list">' . rsm_achievement_whatsapp_person_html($korwil, 'Korwil', (float) ($korwil['registrasi'] ?? $regionalClosing), false, 'closing kampus') . '</div>';
            $html .= '</div>';
            $html .= '<div class="achievement-unit-list">';
            if (!$units) {
                $html .= '<p class="muted">Belum ada unit closing pada periode ini.</p>';
            }
            foreach ($units as $unit) {
                if (!is_array($unit)) {
                    continue;
                }
                $unitClosing = (float) ($unit['registrasi'] ?? 0);
                $staffTotal = array_sum(array_map(static fn (array $staff): float => (float) ($staff['registrasi'] ?? 0), is_array($unit['staff'] ?? null) ? $unit['staff'] : []));
                $html .= '<div class="achievement-unit-card">';
                $html .= '<div class="achievement-unit-title"><strong>' . rsm_html((string) ($unit['unit'] ?? '-')) . '</strong><span>' . rsm_html(number_format($unitClosing, 0, ',', '.')) . ' closing kampus</span></div>';
                $html .= '<div class="achievement-staff-list">';
                if (abs($unitClosing - $staffTotal) <= 0.0001) {
                    foreach (($unit['staff'] ?? []) as $staff) {
                        if (is_array($staff)) {
                            $html .= rsm_achievement_whatsapp_person_html($staff, 'Staff', (float) ($staff['registrasi'] ?? 0), false, 'closing staff');
                        }
                    }
                }
                $html .= '</div></div>';
            }
            $html .= '</div></article>';
        }
        $html .= '</div>';
    }

    $html .= '</div></div></section></main></body></html>';
    return $html;
}

function rsm_achievement_whatsapp_person_html(array $person, string $label, float $closing, bool $large = false, string $closingLabel = 'closing'): string
{
    $name = (string) (($person['name'] ?? '') ?: '-');
    $initial = strtoupper(substr($name !== '' ? $name : 'U', 0, 1));
    $photo = rsm_achievement_whatsapp_image_src((string) ($person['photo_path'] ?? ''));
    $html = '<div class="achievement-person ' . ($large ? 'large' : '') . '">';
    $html .= '<span class="achievement-avatar">';
    $html .= $photo !== '' ? '<img src="' . rsm_html($photo) . '" alt="Foto ' . rsm_html($name) . '">' : rsm_html($initial);
    $html .= '</span><div><small>' . rsm_html($label) . '</small><strong>' . rsm_html($name) . '</strong><em>' . rsm_html(number_format($closing, 0, ',', '.')) . ' ' . rsm_html($closingLabel) . '</em></div></div>';
    return $html;
}

function rsm_achievement_whatsapp_image_src(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }
    $absolute = __DIR__ . '/' . ltrim($path, '/');
    if (!is_file($absolute)) {
        return '';
    }
    return '/' . ltrim($path, '/');
}

function rsm_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rsm_generate_achievement_whatsapp_image_gd(array $payload, string $dir, string $filename): string
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Extension GD belum aktif.');
    }

    $regionals = $payload['regionals'] ?? [];
    $width = 2048;
    $padding = 16;
    $gap = 16;
    $leaderHeight = 92;
    $cardHeaderHeight = 148;
    $emptyHeaderHeight = 132;
    $unitHeight = 86;
    $emptyHeight = 58;
    $cardHeights = [];
    foreach ($regionals as $regional) {
        $units = $regional['units'] ?? [];
        $unitCount = max(1, count($units));
        $headerHeight = $units ? $cardHeaderHeight : $emptyHeaderHeight;
        $cardHeights[] = $headerHeight + ($units ? ($unitCount * ($unitHeight + 12) + 34) : $emptyHeight);
    }
    $leftHeight = ($cardHeights[0] ?? 390) + ($cardHeights[2] ?? 390) + $gap;
    $rightHeight = ($cardHeights[1] ?? 390) + ($cardHeights[3] ?? 390) + $gap;
    $height = max(1010, 184 + max($leftHeight, $rightHeight) + 16);
    $image = imagecreatetruecolor($width, $height);
    imageantialias($image, true);
    $colors = [
        'white' => imagecolorallocate($image, 255, 255, 255),
        'bg' => imagecolorallocate($image, 248, 250, 252),
        'ink' => imagecolorallocate($image, 15, 23, 42),
        'muted' => imagecolorallocate($image, 107, 124, 148),
        'line' => imagecolorallocate($image, 222, 232, 244),
        'soft' => imagecolorallocate($image, 248, 251, 255),
        'green' => imagecolorallocate($image, 45, 125, 65),
        'lightGreen' => imagecolorallocate($image, 234, 253, 241),
        'greenLine' => imagecolorallocate($image, 187, 247, 208),
        'blue' => imagecolorallocate($image, 64, 92, 214),
        'orange' => imagecolorallocate($image, 158, 68, 28),
        'purple' => imagecolorallocate($image, 91, 31, 154),
    ];
    imagefill($image, 0, 0, $colors['white']);

    rsm_gd_text($image, 'Laporan Pencapaian', 16, 25, 18, $colors['ink'], true);
    rsm_gd_text($image, 'Regional/kampus memakai closing kampus; staff memakai closing staff', 16, 52, 14, $colors['muted'], false);

    $leader = $payload['leader'] ?? [];
    rsm_gd_round_rect($image, $padding, 76, $width - $padding, 76 + $leaderHeight, 12, $colors['lightGreen'], $colors['greenLine']);
    rsm_gd_avatar($image, (string) ($leader['photo_path'] ?? ''), (string) ($leader['name'] ?? 'S'), 34, 92, 56, $colors);
    rsm_gd_text($image, strtoupper((string) ($leader['label'] ?? 'Senior Manager')), 102, 98, 11, $colors['muted'], true);
    rsm_gd_text($image, (string) ($leader['name'] ?? '-'), 102, 121, 16, $colors['ink'], true);
    rsm_gd_text($image, number_format((float) ($leader['registrasi'] ?? 0), 0, ',', '.') . ' closing kampus', 102, 145, 14, $colors['green'], true);

    $cardWidth = (int) (($width - ($padding * 2) - $gap) / 2);
    $xPositions = [$padding, $padding + $cardWidth + $gap];
    $yPositions = [184, 184];
    $tones = [
        ['from' => [46, 115, 61], 'to' => [82, 190, 94]],
        ['from' => [63, 83, 213], 'to' => [92, 178, 234]],
        ['from' => [147, 64, 29], 'to' => [221, 105, 34]],
        ['from' => [92, 31, 151], 'to' => [137, 82, 232]],
    ];
    foreach ($regionals as $index => $regional) {
        $column = $index % 2;
        $x = $xPositions[$column];
        $y = $yPositions[$column];
        $units = $regional['units'] ?? [];
        $headerHeight = $units ? $cardHeaderHeight : $emptyHeaderHeight;
        $cardHeight = $cardHeights[$index] ?? 390;
        $tone = $tones[$index % count($tones)];
        rsm_gd_round_rect($image, $x, $y, $x + $cardWidth, $y + $cardHeight, 12, $colors['white'], $colors['line']);
        rsm_gd_gradient_rect($image, $x + 1, $y + 1, $x + $cardWidth - 1, $y + $headerHeight, $tone['from'], $tone['to']);
        $titleY = $units ? $y + 80 : $y + 52;
        rsm_gd_text($image, strtoupper((string) ($regional['regional'] ?? '-')), $x + 18, $titleY, 12, $colors['white'], true);
        rsm_gd_text($image, number_format((float) ($regional['registrasi'] ?? 0), 0, ',', '.') . ' closing kampus', $x + 18, $titleY + 34, 18, $colors['white'], true);
        $korwil = $regional['korwil'] ?? [];
        $korwilY = $units ? $y + 54 : $y + 26;
        $avatarSize = $units ? 80 : 58;
        $korwilTextX = $x + $cardWidth - ($units ? 238 : 210);
        rsm_gd_avatar($image, (string) ($korwil['photo_path'] ?? ''), (string) ($korwil['name'] ?? 'K'), $x + $cardWidth - ($units ? 330 : 280), $korwilY, $avatarSize, $colors);
        rsm_gd_text($image, 'KORWIL', $korwilTextX, $korwilY + 14, 10, $colors['white'], true);
        rsm_gd_text($image, (string) ($korwil['name'] ?? '-'), $korwilTextX, $korwilY + 38, 13, $colors['white'], true, 196);
        rsm_gd_text($image, number_format((float) ($korwil['registrasi'] ?? $regional['registrasi'] ?? 0), 0, ',', '.') . ' closing kampus', $korwilTextX, $korwilY + 60, 11, $colors['white'], true);

        $contentY = $y + $headerHeight + 24;
        if (!$units) {
            rsm_gd_text($image, 'Belum ada unit closing pada periode ini.', $x + 16, $contentY + 28, 15, $colors['muted'], false);
        } else {
            foreach ($units as $unit) {
                rsm_gd_round_rect($image, $x + 16, $contentY, $x + $cardWidth - 16, $contentY + 76, 10, $colors['soft'], $colors['line']);
                rsm_gd_text($image, (string) ($unit['unit'] ?? '-'), $x + 28, $contentY + 26, 15, $colors['ink'], true, $cardWidth - 210);
                rsm_gd_text($image, strtoupper(number_format((float) ($unit['registrasi'] ?? 0), 0, ',', '.') . ' closing kampus'), $x + $cardWidth - 160, $contentY + 27, 10, $colors['muted'], true);
                $staffX = $x + 28;
                $staffY = $contentY + 44;
                foreach (array_slice($unit['staff'] ?? [], 0, 1) as $staff) {
                    rsm_gd_avatar($image, (string) ($staff['photo_path'] ?? ''), (string) ($staff['name'] ?? 'S'), $staffX, $staffY, 38, $colors);
                    rsm_gd_text($image, 'STAFF', $staffX + 50, $staffY + 9, 9, $colors['muted'], true);
                    rsm_gd_text($image, (string) ($staff['name'] ?? '-'), $staffX + 50, $staffY + 28, 13, $colors['ink'], true, $cardWidth - 260);
                    rsm_gd_text($image, number_format((float) ($staff['registrasi'] ?? 0), 0, ',', '.') . ' closing staff', $staffX + 50, $staffY + 48, 12, $colors['green'], true);
                }
                $contentY += $unitHeight + 12;
            }
        }
        $yPositions[$column] += $cardHeight + $gap;
    }

    imagepng($image, $dir . '/' . $filename);
    imagedestroy($image);
    return $filename;
}

function rsm_gd_font(bool $bold = false): ?string
{
    $candidates = $bold
        ? ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf', 'C:/Windows/Fonts/arialbd.ttf']
        : ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf', 'C:/Windows/Fonts/arial.ttf'];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function rsm_gd_text(GdImage $image, string $text, int $x, int $y, int $size, int $color, bool $bold = false, int $maxWidth = 0): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    if ($maxWidth > 0) {
        while (strlen($text) > 3 && rsm_gd_text_width($text, $size, $bold) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        if (rsm_gd_text_width($text, $size, $bold) > $maxWidth) {
            $text = mb_substr($text, 0, max(1, mb_strlen($text) - 3)) . '...';
        }
    }
    $font = rsm_gd_font($bold);
    if ($font !== null) {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
        return;
    }
    imagestring($image, $bold ? 5 : 4, $x, $y - 14, $text, $color);
}

function rsm_gd_text_width(string $text, int $size, bool $bold = false): int
{
    $font = rsm_gd_font($bold);
    if ($font !== null) {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box ? (int) abs($box[2] - $box[0]) : 0;
    }
    return strlen($text) * imagefontwidth($bold ? 5 : 4);
}

function rsm_gd_round_rect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $fill, ?int $border = null): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $fill);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $fill);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $fill);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $fill);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $fill);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $fill);
    if ($border !== null) {
        imagerectangle($image, $x1, $y1, $x2, $y2, $border);
    }
}

function rsm_gd_gradient_rect(GdImage $image, int $x1, int $y1, int $x2, int $y2, array $from, array $to): void
{
    $width = max(1, $x2 - $x1);
    for ($x = $x1; $x <= $x2; $x++) {
        $ratio = ($x - $x1) / $width;
        $r = (int) round($from[0] + (($to[0] - $from[0]) * $ratio));
        $g = (int) round($from[1] + (($to[1] - $from[1]) * $ratio));
        $b = (int) round($from[2] + (($to[2] - $from[2]) * $ratio));
        imageline($image, $x, $y1, $x, $y2, imagecolorallocate($image, $r, $g, $b));
    }
}

function rsm_gd_avatar(GdImage $image, string $path, string $name, int $x, int $y, int $size, array $colors): void
{
    rsm_gd_round_rect($image, $x, $y, $x + $size, $y + $size, 12, $colors['blue'], $colors['line']);
    $absolute = $path !== '' ? __DIR__ . '/' . ltrim($path, '/') : '';
    $source = null;
    if ($absolute !== '' && is_file($absolute)) {
        $info = @getimagesize($absolute);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if ($mime === 'image/jpeg') {
            $source = @imagecreatefromjpeg($absolute);
        } elseif ($mime === 'image/png') {
            $source = @imagecreatefrompng($absolute);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $source = @imagecreatefromwebp($absolute);
        }
    }
    if ($source instanceof GdImage) {
        imagecopyresampled($image, $source, $x, $y, 0, 0, $size, $size, imagesx($source), imagesy($source));
        imagedestroy($source);
        imagerectangle($image, $x, $y, $x + $size, $y + $size, $colors['line']);
        return;
    }
    $initial = strtoupper(substr(trim($name) !== '' ? trim($name) : 'U', 0, 1));
    $textSize = max(14, (int) floor($size * 0.38));
    $textWidth = rsm_gd_text_width($initial, $textSize, true);
    rsm_gd_text($image, $initial, $x + (int) (($size - $textWidth) / 2), $y + (int) (($size + $textSize) / 2), $textSize, $colors['white'], true);
}

function rsm_latest_achievement_whatsapp_artifact(): ?array
{
    $path = rsm_achievement_whatsapp_dir() . '/latest.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function rsm_gamification_summary(string $area, array $filters, ?array $user = null): array
{
    $statusMap = rsm_dashboard_status_map($area, $filters, $user);
    $buckets = rsm_dashboard_closing_buckets($statusMap['closing_status']);
    [$registrasiCondition, $registrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['registrasi']);
    [$herregistrasiCondition, $herregistrasiParams] = rsm_dashboard_in_condition('closing_status', $buckets['herregistrasi']);
    [$filterSql, $filterParams] = rsm_dashboard_filter_sql($filters, 'r');
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');

    $leadAggregateSql = "
        SELECT
            report_id,
            COUNT(*) AS detail_leads,
            SUM(CASE WHEN COALESCE(follow_up_result, '') <> '' OR COALESCE(progress_status, '') <> '' THEN 1 ELSE 0 END) AS detail_follow_up,
            SUM(CASE WHEN {$registrasiCondition} THEN 1 ELSE 0 END) AS detail_registrasi,
            SUM(CASE WHEN {$herregistrasiCondition} THEN 1 ELSE 0 END) AS detail_herregistrasi
        FROM rsm_ad_leads
        GROUP BY report_id
    ";

    $stmt = rsm_pdo()->prepare(
        "SELECT
            COALESCE(r.user_id, 0) AS user_id,
            COALESCE(NULLIF(r.staff_name, ''), NULLIF(r.created_by_name, ''), 'Staff Unit') AS staff_label,
            MAX(r.wilayah) AS wilayah,
            MAX(r.unit_name) AS unit_name,
            COUNT(*) AS report_total,
            COUNT(DISTINCT r.report_date) AS report_days,
            SUM(CASE WHEN r.status IN ('Diverifikasi','Disetujui','Disetujui Senior Manager','Selesai','Berjalan') THEN 1 ELSE 0 END) AS approved_reports,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_leads ELSE r.leads_count END), 0) AS leads_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_follow_up ELSE 0 END), 0) AS follow_up_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_registrasi ELSE r.closing_count END), 0) AS registrasi_total,
            COALESCE(SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN la.detail_herregistrasi ELSE 0 END), 0) AS herregistrasi_total,
            COALESCE(SUM(CASE WHEN r.report_type = 'ads' THEN r.realization_amount ELSE 0 END), 0) AS spend_total,
            SUM(CASE WHEN COALESCE(la.detail_leads, 0) > 0 THEN 1 ELSE 0 END) AS uploaded_ad_reports,
            SUM(CASE WHEN COALESCE(r.obstacle_text, '') <> '' AND COALESCE(r.follow_up_text, '') <> '' THEN 1 ELSE 0 END) AS complete_follow_up_notes
         FROM rsm_reports r
         LEFT JOIN ({$leadAggregateSql}) la ON la.report_id = r.id
         WHERE r.area = ? {$filterSql} {$scopeSql}
         GROUP BY COALESCE(r.user_id, 0), staff_label
         ORDER BY staff_label ASC"
    );

    $stmt->execute(array_merge($registrasiParams, $herregistrasiParams, [$area], $filterParams, $scopeParams));
    $closingCollab = rsm_collab_staff_totals('Closing Collab', $filters, $area, $user);
    $herregCollab = rsm_collab_staff_totals('Herreg Collab', $filters, $area, $user);
    unset($closingCollab['__meta'], $herregCollab['__meta']);
    $usesCollabClosing = $closingCollab !== [];
    $usesCollabHerreg = $herregCollab !== [];
    $usersById = [];
    $usersByName = [];
    $usersByNik = [];
    $activeUsers = [];
    foreach (rsm_users($area) as $rsmUser) {
        if ((int) ($rsmUser['is_active'] ?? 1) !== 1) {
            continue;
        }
        $activeUsers[] = $rsmUser;
        $userId = (int) ($rsmUser['id'] ?? 0);
        if ($userId > 0) {
            $usersById[$userId] = $rsmUser;
        }
        $nameKey = rsm_username_from_nik_or_name(null, (string) ($rsmUser['name'] ?? ''));
        $nikKey = rsm_username_from_nik_or_name((string) ($rsmUser['nik'] ?? ''), (string) ($rsmUser['name'] ?? ''));
        if ($nameKey !== '') {
            $usersByName[$nameKey] = $rsmUser;
        }
        if ($nikKey !== '') {
            $usersByNik[$nikKey] = $rsmUser;
        }
    }

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $staffName = (string) ($row['staff_label'] ?? '');
        $staffKey = rsm_username_from_nik_or_name(null, $staffName);
        $userByName = $usersById[(int) ($row['user_id'] ?? 0)] ?? $usersByName[$staffKey] ?? [];
        $rows[$staffKey] = $row + [
            'photo_path' => (string) ($userByName['photo_path'] ?? ''),
            'nik' => (string) ($userByName['nik'] ?? ''),
            'user_role' => (string) ($userByName['role'] ?? 'staff'),
        ];
    }

    foreach ($closingCollab as $collabKey => $collabRow) {
        if (!is_array($collabRow)) {
            continue;
        }
        $staffName = (string) ($collabRow['name'] ?? '');
        $staffNik = (string) ($collabRow['nik'] ?? '');
        $nameKey = rsm_username_from_nik_or_name(null, $staffName);
        $nikKey = rsm_username_from_nik_or_name($staffNik !== '' ? $staffNik : null, $staffName);
        $rowKey = isset($rows[$nameKey]) ? $nameKey : ($nameKey !== '' ? $nameKey : (string) $collabKey);
        $staffUser = $usersByNik[$nikKey] ?? $usersByName[$nameKey] ?? [];
        if (isset($rows[$rowKey])) {
            if ((string) ($rows[$rowKey]['photo_path'] ?? '') === '' && (string) ($staffUser['photo_path'] ?? '') !== '') {
                $rows[$rowKey]['photo_path'] = (string) $staffUser['photo_path'];
            }
            if ((string) ($rows[$rowKey]['nik'] ?? '') === '' && $staffNik !== '') {
                $rows[$rowKey]['nik'] = $staffNik;
            }
            if ((string) ($staffUser['role'] ?? '') !== '') {
                $rows[$rowKey]['user_role'] = (string) $staffUser['role'];
            }
            continue;
        }
        $rows[$rowKey] = [
            'user_id' => (int) ($staffUser['id'] ?? 0),
            'staff_label' => $staffName,
            'wilayah' => (string) (($collabRow['regional'] ?? '') ?: ($staffUser['regional'] ?? '')),
            'unit_name' => (string) ($staffUser['campus_name'] ?? ''),
            'report_total' => 0,
            'report_days' => 0,
            'approved_reports' => 0,
            'leads_total' => 0,
            'follow_up_total' => 0,
            'registrasi_total' => 0,
            'herregistrasi_total' => 0,
            'spend_total' => 0,
            'uploaded_ad_reports' => 0,
            'complete_follow_up_notes' => 0,
            'photo_path' => (string) ($staffUser['photo_path'] ?? ''),
            'nik' => $staffNik,
            'user_role' => (string) (($staffUser['role'] ?? '') ?: 'staff'),
        ];
    }

    foreach ($herregCollab as $collabKey => $collabRow) {
        if (!is_array($collabRow)) {
            continue;
        }
        $staffName = (string) ($collabRow['name'] ?? '');
        $staffNik = (string) ($collabRow['nik'] ?? '');
        $nameKey = rsm_username_from_nik_or_name(null, $staffName);
        $nikKey = rsm_username_from_nik_or_name($staffNik !== '' ? $staffNik : null, $staffName);
        $rowKey = isset($rows[$nameKey]) ? $nameKey : ($nameKey !== '' ? $nameKey : (string) $collabKey);
        $staffUser = $usersByNik[$nikKey] ?? $usersByName[$nameKey] ?? [];
        if (isset($rows[$rowKey])) {
            if ((string) ($rows[$rowKey]['photo_path'] ?? '') === '' && (string) ($staffUser['photo_path'] ?? '') !== '') {
                $rows[$rowKey]['photo_path'] = (string) $staffUser['photo_path'];
            }
            if ((string) ($rows[$rowKey]['nik'] ?? '') === '' && $staffNik !== '') {
                $rows[$rowKey]['nik'] = $staffNik;
            }
            if ((string) ($staffUser['role'] ?? '') !== '') {
                $rows[$rowKey]['user_role'] = (string) $staffUser['role'];
            }
            continue;
        }
        $rows[$rowKey] = [
            'user_id' => (int) ($staffUser['id'] ?? 0),
            'staff_label' => $staffName,
            'wilayah' => (string) (($collabRow['regional'] ?? '') ?: ($staffUser['regional'] ?? '')),
            'unit_name' => (string) ($staffUser['campus_name'] ?? ''),
            'report_total' => 0,
            'report_days' => 0,
            'approved_reports' => 0,
            'leads_total' => 0,
            'follow_up_total' => 0,
            'registrasi_total' => 0,
            'herregistrasi_total' => 0,
            'spend_total' => 0,
            'uploaded_ad_reports' => 0,
            'complete_follow_up_notes' => 0,
            'photo_path' => (string) ($staffUser['photo_path'] ?? ''),
            'nik' => $staffNik,
            'user_role' => (string) (($staffUser['role'] ?? '') ?: 'staff'),
        ];
    }
    $rows = array_values($rows);

    $rows = array_map(static function (array $row) use ($closingCollab, $herregCollab, $usesCollabClosing, $usesCollabHerreg): array {
        $staffName = (string) ($row['staff_label'] ?? '');
        $nameKey = rsm_username_from_nik_or_name(null, $staffName);
        $nikKey = rsm_username_from_nik_or_name((string) ($row['nik'] ?? ''), $staffName);
        $collabClosing = (float) (($closingCollab[$nikKey]['value'] ?? null) ?? ($closingCollab[$nameKey]['value'] ?? 0));
        $collabHerreg = (float) (($herregCollab[$nikKey]['value'] ?? null) ?? ($herregCollab[$nameKey]['value'] ?? 0));
        $closingForPoints = $usesCollabClosing ? $collabClosing : (float) ($row['registrasi_total'] ?? 0);
        $herregForPoints = $usesCollabHerreg ? $collabHerreg : (float) ($row['herregistrasi_total'] ?? 0);

        $points =
            ((int) ($row['report_total'] ?? 0) * 5)
            + ((int) ($row['approved_reports'] ?? 0) * 10)
            + ((int) ($row['leads_total'] ?? 0) * 2)
            + ((int) ($row['follow_up_total'] ?? 0) * 4)
            + ((int) $closingForPoints * 20)
            + ((int) $herregForPoints * 35)
            + ((int) ($row['uploaded_ad_reports'] ?? 0) * 10)
            + ((int) ($row['complete_follow_up_notes'] ?? 0) * 5);

        $row['points'] = $points;
        $row['closing_points_source'] = $usesCollabClosing ? 'Closing Collab' : 'RSM fallback';
        $row['herreg_points_source'] = $usesCollabHerreg ? 'Herreg Collab' : 'RSM fallback';
        $row['closing_for_points'] = $closingForPoints;
        $row['herreg_for_points'] = $herregForPoints;
        $row['conversion_rate'] = rsm_dashboard_percent((float) ($row['registrasi_total'] ?? 0), (float) ($row['leads_total'] ?? 0));
        $row['badges'] = rsm_gamification_badges_for($row);
        return $row;
    }, $rows);

    $staffRows = array_values(array_filter($rows, static fn (array $row): bool => !in_array((string) ($row['user_role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator'], true)));
    $aggregateBase = static function (array $user): array {
        return [
            'user_id' => (int) ($user['id'] ?? 0),
            'staff_label' => (string) ($user['name'] ?? '-'),
            'wilayah' => (string) (($user['regional'] ?? '') ?: ($user['area'] ?? 'Regional B')),
            'unit_name' => (string) ($user['role'] ?? ''),
            'report_total' => 0,
            'report_days' => 0,
            'approved_reports' => 0,
            'leads_total' => 0,
            'follow_up_total' => 0,
            'registrasi_total' => 0,
            'herregistrasi_total' => 0,
            'spend_total' => 0,
            'uploaded_ad_reports' => 0,
            'complete_follow_up_notes' => 0,
            'photo_path' => (string) ($user['photo_path'] ?? ''),
            'nik' => (string) ($user['nik'] ?? ''),
            'user_role' => (string) ($user['role'] ?? ''),
            'points' => 0,
            'closing_for_points' => 0.0,
            'herreg_for_points' => 0.0,
            'closing_points_source' => 'Akumulasi staff',
            'herreg_points_source' => 'Akumulasi staff',
        ];
    };
    $addToAggregate = static function (array &$aggregate, array $source): void {
        foreach (['report_total', 'report_days', 'approved_reports', 'leads_total', 'follow_up_total', 'registrasi_total', 'herregistrasi_total', 'uploaded_ad_reports', 'complete_follow_up_notes', 'points'] as $field) {
            $aggregate[$field] = (float) ($aggregate[$field] ?? 0) + (float) ($source[$field] ?? 0);
        }
        $aggregate['spend_total'] = (float) ($aggregate['spend_total'] ?? 0) + (float) ($source['spend_total'] ?? 0);
        $aggregate['closing_for_points'] = (float) ($aggregate['closing_for_points'] ?? 0) + (float) ($source['closing_for_points'] ?? 0);
        $aggregate['herreg_for_points'] = (float) ($aggregate['herreg_for_points'] ?? 0) + (float) ($source['herreg_for_points'] ?? 0);
    };

    $aggregateRows = [];
    foreach ($activeUsers as $aggregateUser) {
        $aggregateRole = (string) ($aggregateUser['role'] ?? '');
        if ($aggregateRole === 'koordinator') {
            $regional = (string) ($aggregateUser['regional'] ?? '');
            if ($regional === '') {
                continue;
            }
            $aggregate = $aggregateBase($aggregateUser);
            $aggregate['unit_name'] = 'Akumulasi staff regional';
            foreach ($staffRows as $staffRow) {
                if ((string) ($staffRow['wilayah'] ?? '') === $regional) {
                    $addToAggregate($aggregate, $staffRow);
                }
            }
            $aggregate['conversion_rate'] = rsm_dashboard_percent((float) ($aggregate['registrasi_total'] ?? 0), (float) ($aggregate['leads_total'] ?? 0));
            $aggregate['badges'] = rsm_gamification_badges_for($aggregate);
            $aggregateRows[] = $aggregate;
            continue;
        }
        if (in_array($aggregateRole, ['super_user', 'executive_director', 'director', 'senior', 'mentor'], true)) {
            $aggregate = $aggregateBase($aggregateUser);
            $aggregate['wilayah'] = (string) (($aggregateUser['area'] ?? '') ?: $area);
            $aggregate['unit_name'] = 'Akumulasi koordinator';
            foreach ($staffRows as $staffRow) {
                $addToAggregate($aggregate, $staffRow);
            }
            $aggregate['conversion_rate'] = rsm_dashboard_percent((float) ($aggregate['registrasi_total'] ?? 0), (float) ($aggregate['leads_total'] ?? 0));
            $aggregate['badges'] = rsm_gamification_badges_for($aggregate);
            $aggregateRows[] = $aggregate;
        }
    }
    $rows = array_merge($rows, $aggregateRows);

    usort($rows, static fn (array $a, array $b): int => ((int) $b['points'] <=> (int) $a['points']) ?: strcmp((string) $a['staff_label'], (string) $b['staff_label']));
    $leaderboardRows = array_values(array_filter($rows, static fn (array $row): bool => !in_array((string) ($row['user_role'] ?? ''), ['super_user', 'executive_director', 'director', 'senior', 'mentor', 'koordinator'], true)));
    usort($leaderboardRows, static fn (array $a, array $b): int => ((int) $b['points'] <=> (int) $a['points']) ?: strcmp((string) $a['staff_label'], (string) $b['staff_label']));
    $topRows = array_slice($leaderboardRows, 0, 5);

    $currentName = (string) ($user['name'] ?? '');
    $currentUserId = (int) ($user['id'] ?? 0);
    $myRank = null;
    foreach ($rows as $index => $row) {
        if (($currentUserId > 0 && (int) ($row['user_id'] ?? 0) === $currentUserId) || ($currentName !== '' && (string) ($row['staff_label'] ?? '') === $currentName)) {
            $myRank = $row + ['rank' => $index + 1];
            break;
        }
    }

    return [
        'leaderboard' => $topRows,
        'my_rank' => $myRank,
        'sources' => [
            'closing' => $usesCollabClosing ? 'Closing Collab' : 'RSM fallback',
            'herregistrasi' => $usesCollabHerreg ? 'Herreg Collab' : 'RSM fallback',
        ],
        'collab_totals' => [
            'closing' => $usesCollabClosing ? array_sum(array_map(static fn (array $row): float => (float) ($row['value'] ?? 0), $closingCollab)) : null,
            'herregistrasi' => $usesCollabHerreg ? array_sum(array_map(static fn (array $row): float => (float) ($row['value'] ?? 0), $herregCollab)) : null,
        ],
        'challenge' => [
            'title' => 'Challenge Bulan Ini',
            'items' => [
                'Kumpulkan 50 follow up valid',
                'Raih 10 registrasi',
                'Input laporan harian konsisten',
                'Jaga CPL dan cost per registrasi tetap efisien',
            ],
        ],
        'point_rules' => [
            'Laporan harian +5',
            'Laporan tervalidasi +10',
            'Lead +2',
            'Follow up +4',
            'Registrasi +20',
            'Herregistrasi +35',
            'Upload data iklan +10',
        ],
    ];
}

function rsm_summary(string $area, ?array $user = null): array
{
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user);
    $stmt = rsm_pdo()->prepare(
        "SELECT
            SUM(report_type = 'marketing') AS marketing_count,
            COALESCE(SUM(budget_requested), 0) AS budget_total,
            COALESCE(SUM(realization_amount), 0) AS realization_total,
            SUM(report_type = 'other') AS other_count,
            SUM(status IN ('Draft','Dikirim','Pengajuan','Berjalan')) AS pending_count,
            SUM(status IN ('Disetujui','Disetujui Senior Manager','Selesai')) AS approved_count
         FROM rsm_reports
         WHERE area = ? AND report_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') {$scopeSql}"
    );
    $stmt->execute(array_merge([$area], $scopeParams));
    $row = $stmt->fetch() ?: [];
    return [
        'marketing_count' => (int) ($row['marketing_count'] ?? 0),
        'budget_total' => (float) ($row['budget_total'] ?? 0),
        'realization_total' => (float) ($row['realization_total'] ?? 0),
        'other_count' => (int) ($row['other_count'] ?? 0),
        'pending_count' => (int) ($row['pending_count'] ?? 0),
        'approved_count' => (int) ($row['approved_count'] ?? 0),
    ];
}

function rsm_logs(string $area, int $limit = 20, ?array $user = null): array
{
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user, 'r');
    $sql = 'SELECT l.*
            FROM rsm_activity_logs l
            LEFT JOIN rsm_reports r ON r.id = l.report_id
            WHERE l.area = ?';
    if ($scopeSql !== '') {
        $sql .= ' AND l.report_id IS NOT NULL' . $scopeSql;
    }
    $sql .= ' ORDER BY l.id DESC LIMIT ' . max(1, min(100, $limit));
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute(array_merge([$area], $scopeParams));
    return $stmt->fetchAll();
}
