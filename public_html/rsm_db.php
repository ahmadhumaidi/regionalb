<?php
declare(strict_types=1);

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
            role ENUM('senior','koordinator','staff') NOT NULL DEFAULT 'staff',
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
    rsm_add_column_if_missing('rsm_users', 'bio_text', 'TEXT NULL AFTER campus_name');
    rsm_add_column_if_missing('rsm_users', 'photo_path', 'VARCHAR(500) NULL AFTER bio_text');

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
        "CREATE TABLE IF NOT EXISTS rsm_activity_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NULL,
            area VARCHAR(40) NOT NULL,
            actor_role VARCHAR(40) NOT NULL,
            actor_name VARCHAR(180) NULL,
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
}

function rsm_seed_users(): void
{
    $passwordHash = password_hash('kptsukses', PASSWORD_DEFAULT);
    $pdo = rsm_pdo();

    $specialUsers = [
        ['Ahmad Humaidi', 'SG.0202.2014', 'senior', 'Regional Senior Manager B', 'Regional 7', 'Regional B', 'ITB STIKOM Bali'],
        ['Nugroho Budi Santoso', 'SG.0631.2021', 'koordinator', 'Koordinator Regional 7', 'Regional 7', 'Regional B', 'POLNAS'],
        ['Hamaruddin', null, 'koordinator', 'Koordinator Regional 4', 'Regional 4', 'Regional B', null],
        ['Kundi Harto', null, 'koordinator', 'Koordinator Regional 5', 'Regional 5', 'Regional B', null],
        ['M. Nor Abidin', null, 'koordinator', 'Koordinator Regional 6', 'Regional 6', 'Regional B', null],
    ];

    foreach ($specialUsers as $user) {
        rsm_upsert_user($user[0], $user[1], $user[2], $user[3], $user[4], $user[5], $user[6], $passwordHash);
    }

    $sql = "SELECT
        COALESCE(NULLIF(a.source_staff_name,''), u.name) AS nama,
        NULLIF(a.source_nik,'') AS nik,
        COALESCE(NULLIF(u.regional,''), '-') AS regional,
        COALESCE(NULLIF(pc.display_name,''), NULLIF(pc.name,''), NULLIF(u.campus,''), NULLIF(a.source_campus_name,''), '-') AS kampus
    FROM activities a
    LEFT JOIN users u ON u.name = a.source_staff_name OR u.username = a.source_nik
    LEFT JOIN partner_campuses pc ON pc.id = u.partner_campus_id
    WHERE NULLIF(COALESCE(a.source_staff_name, u.name), '') IS NOT NULL
    GROUP BY nama, nik, regional, kampus";
    foreach ($pdo->query($sql) as $row) {
        $name = (string) $row['nama'];
        if (in_array(strtolower($name), ['ahmad humaidi', 'nugroho budi santoso'], true)) {
            continue;
        }
        rsm_upsert_user($name, $row['nik'] ?: null, 'staff', 'Staff Unit', $row['regional'] ?: null, rsm_area_for_regional((string) $row['regional']), $row['kampus'] ?: null, $passwordHash);
    }
}

function rsm_upsert_user(string $name, ?string $nik, string $role, string $jabatan, ?string $regional, ?string $area, ?string $campus, string $passwordHash): void
{
    $username = rsm_username_from_nik_or_name($nik, $name);
    $stmt = rsm_pdo()->prepare('SELECT id FROM rsm_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        rsm_pdo()->prepare('UPDATE rsm_users SET name = ?, nik = ?, role = ?, jabatan = ?, regional = ?, area = ?, campus_name = ?, is_active = 1 WHERE id = ?')
            ->execute([$name, $nik, $role, $jabatan, $regional, $area, $campus, $existingId]);
        return;
    }

    rsm_pdo()->prepare(
        'INSERT INTO rsm_users (name, nik, username, password_hash, role, jabatan, regional, area, campus_name, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$name, $nik, $username, $passwordHash, $role, $jabatan, $regional, $area, $campus]);
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

function rsm_input(string $key, string $fallback = ''): string
{
    return trim((string) ($_POST[$key] ?? $fallback));
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
        'senior' => 'Senior Manager',
        'koordinator' => 'Koordinator Wilayah',
        'staff' => 'Staff Unit',
    ];

    return [
        'role' => $role,
        'name' => $labels[$role] ?? 'User RSM',
    ];
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
    return in_array((string) ($user['role'] ?? ''), ['senior'], true);
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

function rsm_users(string $area): array
{
    $sql = "SELECT * FROM rsm_users WHERE is_active IN (0,1)";
    $params = [];
    if ($area !== 'Regional') {
        $sql .= " AND (area = ? OR area IS NULL OR role = 'senior')";
        $params[] = $area;
    }
    $sql .= " ORDER BY FIELD(role, 'senior', 'koordinator', 'staff'), regional ASC, name ASC";
    $stmt = rsm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rsm_admin_save_user(string $area, array $actor): void
{
    if (($actor['role'] ?? '') !== 'senior') {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengelola user.');
    }

    $name = rsm_input('name');
    $nik = rsm_input('nik');
    $username = rsm_input('username');
    $role = rsm_input('user_role', 'staff');
    $jabatan = rsm_input('jabatan', $role === 'koordinator' ? 'Koordinator Wilayah' : 'Staff Unit');
    $regional = rsm_input('regional');
    $userArea = rsm_input('area', $area);
    $campus = rsm_input('campus_name');
    $password = rsm_input('new_user_password', 'kptsukses');

    if ($name === '' || $username === '' || $role === '') {
        throw new InvalidArgumentException('Nama, username, dan role wajib diisi.');
    }
    if (!in_array($role, ['senior', 'koordinator', 'staff'], true)) {
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
        'INSERT INTO rsm_users (name, nik, username, password_hash, role, jabatan, regional, area, campus_name, must_change_password, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
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
    ]);
}

function rsm_admin_update_user(array $actor): void
{
    if (($actor['role'] ?? '') !== 'senior') {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengedit user.');
    }

    $userId = (int) rsm_input('user_id');
    $name = rsm_input('name');
    $nik = rsm_input('nik');
    $username = rsm_input('username');
    $role = rsm_input('user_role', 'staff');
    $jabatan = rsm_input('jabatan');
    $regional = rsm_input('regional');
    $area = rsm_input('area');
    $campus = rsm_input('campus_name');

    if ($userId <= 0 || $name === '' || $username === '' || $jabatan === '') {
        throw new InvalidArgumentException('Data edit user belum lengkap.');
    }
    if (!in_array($role, ['senior', 'koordinator', 'staff'], true)) {
        throw new InvalidArgumentException('Role user tidak valid.');
    }

    $stmt = rsm_pdo()->prepare('SELECT id FROM rsm_users WHERE username = ? AND id <> ? LIMIT 1');
    $stmt->execute([$username, $userId]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('Username sudah digunakan user lain.');
    }

    rsm_pdo()->prepare(
        'UPDATE rsm_users
         SET name = ?, nik = ?, username = ?, role = ?, jabatan = ?, regional = ?, area = ?, campus_name = ?, updated_at = NOW()
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
        $userId,
    ]);
}

function rsm_admin_reset_user_password(array $actor): void
{
    if (($actor['role'] ?? '') !== 'senior') {
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
    if (($actor['role'] ?? '') !== 'senior') {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa menghapus user.');
    }
    $userId = (int) rsm_input('user_id');
    if ($userId <= 0) {
        throw new InvalidArgumentException('User tidak valid.');
    }
    if ($userId === (int) ($actor['id'] ?? 0)) {
        throw new InvalidArgumentException('User aktif tidak bisa menghapus akunnya sendiri.');
    }
    rsm_pdo()->prepare('DELETE FROM rsm_users WHERE id = ?')->execute([$userId]);
}

function rsm_admin_set_user_active(array $actor): void
{
    if (($actor['role'] ?? '') !== 'senior') {
        throw new RuntimeException('Hanya Regional Senior Manager yang bisa mengubah status user.');
    }
    $userId = (int) rsm_input('user_id');
    $active = (int) rsm_input('is_active');
    if ($userId <= 0) {
        throw new InvalidArgumentException('User tidak valid.');
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
        $regionalOptions = $pdo->query("SELECT DISTINCT regional FROM users WHERE NULLIF(regional, '') IS NOT NULL ORDER BY regional")->fetchAll(PDO::FETCH_COLUMN);
    }

    $params = $regionals;
    $regionalWhere = '';
    if ($regionals !== []) {
        $regionalWhere = 'AND u.regional IN (' . implode(',', array_fill(0, count($regionals), '?')) . ')';
    }

    $staffSql = "SELECT u.id, u.name, u.username, u.regional, COALESCE(pc.display_name, u.campus, pc.name) AS campus_name
                 FROM users u
                 LEFT JOIN partner_campuses pc ON pc.id = u.partner_campus_id
                 WHERE u.role = 'staff'
                   AND u.is_active = 1
                   AND NULLIF(u.name, '') IS NOT NULL
                   {$regionalWhere}
                 ORDER BY u.regional ASC, u.name ASC";
    $stmt = $pdo->prepare($staffSql);
    $stmt->execute($params);
    $staff = $stmt->fetchAll();

    $campusSql = "SELECT DISTINCT pc.id, COALESCE(pc.display_name, pc.name) AS label, pc.kode_kampus, MIN(u.regional) AS regional
                  FROM partner_campuses pc
                  JOIN user_campuses uc ON uc.partner_campus_id = pc.id
                  JOIN users u ON u.id = uc.user_id
                  WHERE u.role = 'staff'
                    AND u.is_active = 1
                    {$regionalWhere}
                  GROUP BY pc.id, pc.display_name, pc.name, pc.kode_kampus
                  ORDER BY regional ASC, label ASC";
    $stmt = $pdo->prepare($campusSql);
    $stmt->execute($params);
    $campuses = $stmt->fetchAll();

    return [
        'regionals' => $regionalOptions,
        'staff' => $staff,
        'campuses' => $campuses,
    ];
}

function rsm_log(?int $reportId, string $area, string $role, string $action, ?string $oldStatus = null, ?string $newStatus = null, ?string $note = null): void
{
    $actor = rsm_current_actor($role);
    $stmt = rsm_pdo()->prepare(
        "INSERT INTO rsm_activity_logs
            (report_id, area, actor_role, actor_name, action_name, old_status, new_status, note, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $reportId,
        $area,
        $actor['role'],
        $actor['name'],
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
        'title' => rsm_input('title', rsm_input('campaign_name', rsm_input('category', 'Laporan RSM'))),
        'activity_kind' => rsm_input('activity_kind'),
        'location_name' => rsm_input('location_name'),
        'target_text' => rsm_input('target_text'),
        'result_text' => rsm_input('result_text'),
        'leads_count' => (int) rsm_number_input('leads_count'),
        'notes' => rsm_input('notes'),
        'platform' => rsm_input('platform'),
        'campaign_name' => rsm_input('campaign_name'),
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

    $columns = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = rsm_pdo()->prepare(
        'INSERT INTO rsm_reports (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute(array_values($data));
    $id = (int) rsm_pdo()->lastInsertId();
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
    $stmt = rsm_pdo()->prepare('SELECT id, status, wilayah, staff_name, created_by_name FROM rsm_reports WHERE id = ? AND area = ?' . $scopeSql . ' LIMIT 1');
    $stmt->execute(array_merge([$id, $area], $scopeParams));
    $report = $stmt->fetch();
    if (!$report) {
        throw new RuntimeException('Laporan tidak ditemukan.');
    }

    if ($role === 'staff') {
        throw new RuntimeException('Staff tidak dapat mengubah status persetujuan.');
    }
    if ($role === 'koordinator' && !in_array($newStatus, ['Diverifikasi', 'Revisi'], true)) {
        throw new RuntimeException('Koordinator hanya dapat memverifikasi atau mengembalikan revisi.');
    }

    $oldStatus = (string) $report['status'];
    $stmt = rsm_pdo()->prepare('UPDATE rsm_reports SET status = ?, revision_note = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newStatus, $note !== '' ? $note : null, $id]);
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

    $data = [
        'report_date' => $date !== '' ? $date : date('Y-m-d'),
        'user_id' => $staffRow['id'] ?? $report['user_id'],
        'partner_campus_id' => $campusRow['id'] ?? ($staffRow['partner_campus_id'] ?? $report['partner_campus_id']),
        'wilayah' => $wilayah !== '' ? $wilayah : 'Belum diisi',
        'unit_name' => $unit !== '' ? $unit : 'Belum diisi',
        'staff_name' => $staff !== '' ? $staff : (string) $report['staff_name'],
        'status' => rsm_input('status', (string) $report['status']),
        'title' => rsm_input('title', rsm_input('campaign_name', rsm_input('category', (string) $report['title']))),
        'activity_kind' => rsm_input('activity_kind'),
        'location_name' => rsm_input('location_name'),
        'target_text' => rsm_input('target_text'),
        'result_text' => rsm_input('result_text'),
        'leads_count' => (int) rsm_number_input('leads_count'),
        'notes' => rsm_input('notes'),
        'platform' => rsm_input('platform'),
        'campaign_name' => rsm_input('campaign_name'),
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
    rsm_assert_report_can_edit($report, $role);

    rsm_log($id, $area, $role, 'delete_report', (string) $report['status'], null, (string) $report['title']);
    $stmt = rsm_pdo()->prepare('DELETE FROM rsm_reports WHERE id = ? AND area = ?');
    $stmt->execute([$id, $area]);
}

function rsm_assert_report_can_edit(array $report, string $role): void
{
    $status = strtolower((string) ($report['status'] ?? ''));
    if ($role !== 'staff') {
        throw new RuntimeException('Edit dan hapus saat ini hanya untuk role Staff Unit.');
    }
    if (!in_array($status, ['draft', 'revisi'], true)) {
        throw new RuntimeException('Laporan hanya bisa diedit atau dihapus saat status Draft atau Revisi.');
    }
}

function rsm_handle_post(string $area, string $role): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

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
        return 'Laporan anggaran iklan berhasil disimpan ke database.' . ($importedRows > 0 ? " Data hasil iklan terimpor {$importedRows} baris." : '');
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

function rsm_report_scope_sql(?array $user, string $alias = ''): array
{
    if (!$user) {
        return ['', []];
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    $actualRole = (string) ($user['role'] ?? '');
    if ($actualRole === 'senior') {
        return ['', []];
    }

    if ($actualRole === 'koordinator' && !empty($user['regional'])) {
        return [" AND {$prefix}wilayah = ?", [(string) $user['regional']]];
    }

    if ($actualRole === 'staff') {
        $clauses = ["({$prefix}staff_name = ? OR {$prefix}created_by_name = ?)"];
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

function rsm_reports(string $area, ?string $type = null, int $limit = 50, ?array $user = null): array
{
    $sql = 'SELECT * FROM rsm_reports WHERE area = ?';
    $params = [$area];
    if ($type !== null) {
        $sql .= ' AND report_type = ?';
        $params[] = $type;
    }
    [$scopeSql, $scopeParams] = rsm_report_scope_sql($user);
    $sql .= $scopeSql;
    $params = array_merge($params, $scopeParams);
    $sql .= ' ORDER BY report_date DESC, id DESC LIMIT ' . max(1, min(200, $limit));
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
    $status = strtolower(trim($status));
    if ($status === '') {
        return 'Belum closing';
    }
    if (str_contains($status, 'her')) {
        return 'Herregistrasi';
    }
    if (str_contains($status, 'daftar') || str_contains($status, 'closing') || str_contains($status, 'close')) {
        return 'Closing';
    }
    if (str_contains($status, 'potensi')) {
        return 'Potensi closing';
    }
    if (str_contains($status, 'tidak')) {
        return 'Tidak closing';
    }
    return ucfirst($status);
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
            $rows[] = array_values($cells);
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
            $tableRows[] = array_values($cells);
        }
    }

    return rsm_map_ad_lead_rows($tableRows);
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

    if ($dateFrom === '' && $dateTo === '') {
        $dateFrom = date('Y-m-d');
        $dateTo = $dateFrom;
    } elseif ($dateFrom !== '' && $dateTo === '') {
        $dateTo = $dateFrom;
    } elseif ($dateFrom === '' && $dateTo !== '') {
        $dateFrom = $dateTo;
    }

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $month = substr($dateFrom, 0, 7);

    return [
        'month' => $month,
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
    foreach (['wilayah', 'unit_name', 'staff_name', 'platform', 'status'] as $field) {
        if (($filters[$field] ?? '') !== '') {
            $sql .= " AND {$prefix}{$field} = ?";
            $params[] = (string) $filters[$field];
        }
    }

    return [$sql, $params];
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
        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            continue;
        }
        $isHer = str_contains($normalized, 'herregistrasi') || str_contains($normalized, 'daftar ulang') || str_contains($normalized, 'her registrasi');
        $isRegistration = $isHer
            || str_contains($normalized, 'closing')
            || str_contains($normalized, 'daftar')
            || str_contains($normalized, 'registrasi')
            || str_contains($normalized, 'terdaftar');
        if ($isRegistration) {
            $registrasi[] = $normalized;
        }
        if ($isHer) {
            $herregistrasi[] = $normalized;
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
    ][$reportName] ?? '';
}

function rsm_wib_timestamp(?string $value = null): string
{
    try {
        $timezone = new DateTimeZone('Asia/Jakarta');
        $date = $value === null || trim($value) === ''
            ? new DateTimeImmutable('now', $timezone)
            : new DateTimeImmutable($value);

        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Exception $exception) {
        return $value ?? '';
    }
}

function rsm_collab_report_from_url(string $reportName): array
{
    $url = rsm_collab_source_url($reportName);
    if ($url === '') {
        return [];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "User-Agent: RegionalB-Dashboard/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    if (!is_string($html) || trim($html) === '') {
        return [];
    }
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
                foreach ($table->getElementsByTagName('tr') as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $cell) {
                        if (!in_array(strtolower((string) $cell->nodeName), ['td', 'th'], true)) {
                            continue;
                        }
                        $cells[] = trim(preg_replace('/\s+/', ' ', (string) $cell->textContent) ?? '');
                    }
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

function rsm_collab_report(string $reportName): array
{
    $live = rsm_collab_report_from_url($reportName);
    if ($live !== []) {
        return $live;
    }

    $history = rsm_collab_report_from_history($reportName);
    if ($history !== []) {
        $history['source_mode'] = 'history_snapshot';
    }
    return $history;
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
        [$dayIndexes, $totalIndex] = rsm_collab_day_indexes($row);
        if ($dayIndexes !== [] && count($dayIndexes) >= 3) {
            $dateRowIndex = (int) $rowIndex;
            break;
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
    $report = rsm_collab_report($reportName);
    $rows = $report['tables'][0] ?? [];
    if (!is_array($rows) || count($rows) < 3) {
        return [
            '__meta' => [
                'source_url' => rsm_collab_source_url($reportName),
                'source_mode' => 'unreadable',
                'report_month' => '',
                'source_time' => '',
            ],
        ];
    }
    $reportMonth = rsm_collab_report_month($rows);
    if ($reportMonth !== '' && ($filters['month'] ?? '') !== '' && $reportMonth !== (string) $filters['month']) {
        return [
            '__meta' => [
                'source_url' => (string) ($report['source_url'] ?? rsm_collab_source_url($reportName)),
                'source_mode' => (string) ($report['source_mode'] ?? 'unknown') . '_month_mismatch',
                'report_month' => $reportMonth,
                'source_time' => (string) ($report['created_at'] ?? ''),
            ],
        ];
    }

    $layout = rsm_collab_layout($rows);
    $dayIndexes = $layout['day_indexes'];
    $totalIndex = $layout['total_index'];

    if ($totalIndex === null) {
        return [
            '__meta' => [
                'source_url' => (string) ($report['source_url'] ?? rsm_collab_source_url($reportName)),
                'source_mode' => (string) ($report['source_mode'] ?? 'unknown') . '_no_total',
                'report_month' => $reportMonth,
                'source_time' => (string) ($report['created_at'] ?? ''),
            ],
        ];
    }

    $dateHeaderRow = $layout['date_row_index'] !== null ? ($rows[$layout['date_row_index']] ?? []) : [];
    $dayValueIndexes = [];
    $dayOffset = 0;
    foreach ($dayIndexes as $headerIndex) {
        $dayLabel = trim((string) ($dateHeaderRow[$headerIndex] ?? ''));
        if (!preg_match('/^\d{1,2}$/', $dayLabel)) {
            continue;
        }
        $dayValueIndexes[(int) $dayLabel] = 5 + $dayOffset;
        $dayOffset++;
    }
    if ($dayValueIndexes !== []) {
        $totalIndex = 5 + count($dayValueIndexes);
    }

    $valueIndexes = [];
    $fromTimestamp = !empty($filters['date_from']) ? strtotime((string) $filters['date_from']) : false;
    $toTimestamp = !empty($filters['date_to']) ? strtotime((string) $filters['date_to']) : false;
    if ($fromTimestamp !== false && $toTimestamp !== false && $reportMonth !== '') {
        if ($fromTimestamp > $toTimestamp) {
            [$fromTimestamp, $toTimestamp] = [$toTimestamp, $fromTimestamp];
        }
        foreach ($dayValueIndexes as $dayNumber => $valueIndex) {
            $columnTimestamp = strtotime($reportMonth . '-' . str_pad((string) $dayNumber, 2, '0', STR_PAD_LEFT));
            if ($columnTimestamp !== false && $columnTimestamp >= $fromTimestamp && $columnTimestamp <= $toTimestamp) {
                $valueIndexes[] = (int) $valueIndex;
            }
        }
    }
    if ($valueIndexes === []) {
        $valueIndexes = [(int) $totalIndex];
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

    $totals = [];
    foreach ($rows as $index => $row) {
        if ($index < (int) $layout['data_start_index'] || !is_array($row)) {
            continue;
        }

        $regional = trim((string) ($row[(int) $layout['regional_index']] ?? ''));
        $staffNik = trim((string) ($row[(int) $layout['staff_nik_index']] ?? ''));
        $staffName = trim((string) ($row[(int) $layout['staff_name_index']] ?? ''));
        if (!preg_match('/^SG[.\d-]+$/i', $staffNik) && preg_match('/^SG[.\d-]+$/i', trim((string) ($row[(int) $layout['staff_nik_index'] + 1] ?? '')))) {
            $staffNik = trim((string) ($row[(int) $layout['staff_nik_index'] + 1] ?? ''));
            $staffName = trim((string) ($row[(int) $layout['staff_name_index'] + 1] ?? $staffName));
        }
        if ($staffName === '' || !preg_match('/^[1-7]$/', $regional)) {
            continue;
        }
        if ($allowedRegionals !== [] && !in_array('Regional ' . $regional, $allowedRegionals, true)) {
            continue;
        }
        if ($filterStaff !== '' && strcasecmp($staffName, $filterStaff) !== 0) {
            continue;
        }

        $value = 0.0;
        foreach ($valueIndexes as $valueIndex) {
            $value += rsm_number_value($row[$valueIndex] ?? 0);
        }
        $keys = [rsm_username_from_nik_or_name($staffNik !== '' ? $staffNik : null, $staffName)];
        foreach ($keys as $key) {
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'nik' => $staffNik,
                    'name' => $staffName,
                    'regional' => 'Regional ' . $regional,
                    'value' => 0.0,
                    'source_url' => (string) ($report['source_url'] ?? rsm_collab_source_url($reportName)),
                    'source_mode' => (string) ($report['source_mode'] ?? 'unknown'),
                    'report_month' => $reportMonth,
                    'source_time' => (string) ($report['created_at'] ?? ''),
                ];
            }
            $totals[$key]['value'] += $value;
        }
    }

    $totals['__meta'] = [
        'source_url' => (string) ($report['source_url'] ?? rsm_collab_source_url($reportName)),
        'source_mode' => (string) ($report['source_mode'] ?? 'unknown'),
        'report_month' => $reportMonth,
        'source_time' => (string) ($report['created_at'] ?? ''),
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
    $rows = array_map(static function (array $row): array {
        return $row;
    }, $stmt->fetchAll());

    $rows = array_map(static function (array $row) use ($closingCollab, $herregCollab, $usesCollabClosing, $usesCollabHerreg): array {
        $staffKey = rsm_username_from_nik_or_name(null, (string) ($row['staff_label'] ?? ''));
        $collabClosing = (float) ($closingCollab[$staffKey]['value'] ?? 0);
        $collabHerreg = (float) ($herregCollab[$staffKey]['value'] ?? 0);
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

    usort($rows, static fn (array $a, array $b): int => ((int) $b['points'] <=> (int) $a['points']) ?: strcmp((string) $a['staff_label'], (string) $b['staff_label']));
    $topRows = array_slice($rows, 0, 5);

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
