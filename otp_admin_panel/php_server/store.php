<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025-2026 Shomy
 *
 * Shared storage for:
 *   - Main website (tool-multi-web.php)
 *   - Admin panel & service pricing (admin/*, admin/price-service.php, admin/users.php)
 *   - User portal, credit balance & app logs (user/*)
 *   - Let's Fire App OTP service (otp/*)
 *   - DA catalog & binary stream (daNewPath/*)
 *   - Penumbra SLA API (api/*)
 */

declare(strict_types=1);

@date_default_timezone_set('UTC');

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }
}
if (!function_exists('fmt_bytes')) {
    function fmt_bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / 1048576, 2) . ' MB';
    }
}

const PURPOSE_NAMES = ['brom_sla', 'pl_sla', 'meta_sla', 'da_sla'];

const DEFAULT_PERMISSIONS = [
    'brom_sla' => true,
    'pl_sla'   => true,
    'meta_sla' => true,
    'da_sla'   => true,
    'is_admin' => false,
];

const MIN_OTP_LEN = 4;
const MAX_OTP_LEN = 64;
const THROTTLE_WINDOW = 300;
const MAX_TOTAL = 100;
const MAX_REJECTED = 15;

const OTP_VERDICTS = [
    'valid'     => 'OTP valid for 1 use',
    'used'      => 'OTP already used.',
    'expired'   => 'OTP has expired.',
    'inactive'  => 'OTP is not active.',
    'not_found' => 'Invalid OTP.',
    'invalid'   => 'Invalid OTP.',
    'throttled' => 'Too many requests — rate limit exceeded.',
    'offline'   => 'Signing server is currently offline.',
];

#region State & Logs Directories

function ensure_state_dir(string $dir): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $marker = $dir . '/.htaccess';
    if (!is_file($marker)) {
        @file_put_contents($marker, "Require all denied\nDeny from all\n");
    }

    return $dir;
}

function store_state(array $config): string
{
    static $cached = null;

    if ($cached === null) {
        $custom = trim((string)($config['state_dir'] ?? ''));
        $cached = ensure_state_dir($custom !== '' ? $custom : __DIR__ . '/state');
    }

    return $cached;
}

function store_log_dir(array $config): string
{
    $custom = trim((string)($config['log_dir'] ?? ''));
    if ($custom !== '') {
        return ensure_state_dir($custom);
    }
    return store_state($config);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

#endregion

#region Throttle and Auth Log

function throttle_file($configOrState, string $ip): string
{
    $state = is_array($configOrState) ? store_state($configOrState) : (string)$configOrState;
    return $state . '/throttle_' . substr(hash('sha256', $ip), 0, 32) . '.json';
}

function throttle_normalize($decoded): array
{
    if (!is_array($decoded) || (int)($decoded['window'] ?? 0) + THROTTLE_WINDOW < time()) {
        return ['window' => time(), 'total' => 0, 'rejected' => 0];
    }

    return [
        'window'   => (int)$decoded['window'],
        'total'    => (int)($decoded['total'] ?? 0),
        'rejected' => (int)($decoded['rejected'] ?? 0),
    ];
}

/**
 * Returns [$total, $rejected] as numeric list AND ['window', 'total', 'rejected'] keys
 * so both list-destructuring `[$total, $rejected] = throttle_read(...)` and associative access work.
 */
function throttle_read($configOrState, string $ip): array
{
    $file = throttle_file($configOrState, $ip);
    $raw  = is_file($file) ? @file_get_contents($file) : false;
    $norm = throttle_normalize(is_string($raw) ? json_decode($raw, true) : null);

    return [
        0          => $norm['total'],
        1          => $norm['rejected'],
        'window'   => $norm['window'],
        'total'    => $norm['total'],
        'rejected' => $norm['rejected'],
    ];
}

function throttle_record($configOrState, string $ip, bool $rejected): void
{
    $handle = @fopen(throttle_file($configOrState, $ip), 'c+b');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw  = stream_get_contents($handle);
    $data = throttle_normalize(is_string($raw) ? json_decode($raw, true) : null);
    $data['total']++;
    if ($rejected) {
        $data['rejected']++;
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string)json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function otp_log($configOrState, string $ip, string $otp, string $verdict): void
{
    $state  = is_array($configOrState) ? store_log_dir($configOrState) : (string)$configOrState;
    $masked = ($otp === '' || $otp === '-') ? '-' : mask_otp($otp);
    $line   = sprintf("[%s] ip=%s otp=%s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $ip, $masked, $verdict);
    @file_put_contents($state . '/otp_auth.log', $line, FILE_APPEND | LOCK_EX);
}

#endregion

#region Settings & Profiles

function settings_defaults(): array
{
    return [
        'signing_enabled' => true,
        'profiles'        => null,
    ];
}

function settings_path(array $config): string
{
    return store_state($config) . '/settings.json';
}

function settings_get(array $config, bool $forceReload = false): array
{
    static $loaded = false;
    static $settings = [];

    if ($loaded && !$forceReload) {
        return $settings;
    }

    $loaded   = true;
    $settings = settings_defaults();

    $path   = settings_path($config);
    $raw    = is_file($path) ? (string)@file_get_contents($path) : '';
    $stored = json_decode($raw, true);

    if (is_array($stored)) {
        if (array_key_exists('signing_enabled', $stored)) {
            $settings['signing_enabled'] = (bool)$stored['signing_enabled'];
        }
        if (isset($stored['profiles']) && is_array($stored['profiles'])) {
            $settings['profiles'] = $stored['profiles'];
        }
    }

    return $settings;
}

function settings_save(array $config, array $settings): bool
{
    $payload = [
        'signing_enabled' => (bool)($settings['signing_enabled'] ?? true),
        'profiles'        => $settings['profiles'] ?? null,
    ];

    $handle = @fopen(settings_path($config), 'c+b');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    settings_get($config, true);
    return true;
}

function signing_enabled(array $config): bool
{
    $settings = settings_get($config);
    return (bool)($settings['signing_enabled'] ?? true);
}

function set_signing_enabled(array $config, bool $enabled): bool
{
    $settings = settings_get($config, true);
    $settings['signing_enabled'] = $enabled;
    return settings_save($config, $settings);
}

/**
 * Load profiles as associative map:
 *   ['default' => ['brom_sla' => true, 'pl_sla' => true, 'meta_sla' => true, 'da_sla' => true, 'is_admin' => false], ...]
 */
function profiles_load(array $config): array
{
    $settings = settings_get($config);
    $source   = is_array($settings['profiles'] ?? null)
        ? $settings['profiles']
        : (array)($config['profiles'] ?? []);

    $out = [];
    foreach ($source as $name => $profile) {
        $name = (string)preg_replace('/[^A-Za-z0-9_-]/', '', (string)$name);
        if ($name === '' || !is_array($profile)) {
            continue;
        }

        if (isset($profile['permissions']) && is_array($profile['permissions'])) {
            $granted = array_map('strval', $profile['permissions']);
            $out[$name] = [
                'brom_sla' => in_array('brom_sla', $granted, true),
                'pl_sla'   => in_array('pl_sla', $granted, true),
                'meta_sla' => in_array('meta_sla', $granted, true),
                'da_sla'   => in_array('da_sla', $granted, true),
                'is_admin' => !empty($profile['is_admin']),
            ];
        } else {
            $perms = DEFAULT_PERMISSIONS;
            foreach (array_keys(DEFAULT_PERMISSIONS) as $k) {
                if (array_key_exists($k, $profile)) {
                    $perms[$k] = (bool)$profile[$k];
                }
            }
            $out[$name] = $perms;
        }
    }

    if (!isset($out['default'])) {
        $out['default'] = DEFAULT_PERMISSIONS;
    }

    return $out;
}

function profiles_save(array $config, array $profiles): bool
{
    $settings = settings_get($config, true);
    $settings['profiles'] = $profiles;
    return settings_save($config, $settings);
}

function profile_permissions(array $config, string $profile): array
{
    $profiles = profiles_load($config);
    return $profiles[$profile] ?? $profiles['default'] ?? DEFAULT_PERMISSIONS;
}

function effective_profiles(array $config): array
{
    return profiles_load($config);
}

#endregion

#region Database & Storage Backend

function mysql_configured(array $config): bool
{
    $db = $config['db'] ?? [];
    if (!empty($db['dsn']) && class_exists('PDO')) {
        return true;
    }
    return trim((string)($db['user'] ?? '')) !== ''
        && trim((string)($db['name'] ?? '')) !== ''
        && class_exists('mysqli');
}

function use_mysql(array $config): bool
{
    static $reachable = null;

    if ($reachable !== null) {
        return $reachable;
    }

    if (!mysql_configured($config)) {
        $reachable = false;
        return false;
    }

    try {
        $conn = db($config);
        ensure_mysql_schema($conn);
        $conn->close();
        $reachable = true;
    } catch (Throwable $ex) {
        $reachable = false;
    }

    return $reachable;
}

function db(array $config): mysqli
{
    $db = $config['db'] ?? [];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli(
        (string)($db['host'] ?? 'localhost'),
        (string)($db['user'] ?? ''),
        (string)($db['pass'] ?? ''),
        (string)($db['name'] ?? '')
    );
    $conn->set_charset('utf8mb4');
    $conn->query("SET SESSION wait_timeout=60, net_read_timeout=30, net_write_timeout=30, time_zone='+00:00'");

    return $conn;
}

function ensure_mysql_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $conn->query("CREATE TABLE IF NOT EXISTS `otp` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `otp`        VARCHAR(64)     NOT NULL,
        `status`     ENUM('valid','used','expired','inactive') NOT NULL DEFAULT 'valid',
        `used`       TINYINT(1)      NOT NULL DEFAULT 0,
        `expired_at` DATETIME        NULL DEFAULT NULL,
        `user`       VARCHAR(64)     NOT NULL DEFAULT 'admin',
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_otp` (`otp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `admins` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`      VARCHAR(64)     NOT NULL,
        `password_hash` VARCHAR(255)    NOT NULL,
        `role`          VARCHAR(32)     NOT NULL DEFAULT 'superadmin',
        `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_login_at` DATETIME        NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_admin_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $chkAdmin = $conn->query("SELECT COUNT(*) AS cnt FROM `admins`");
    $adminCnt = $chkAdmin ? (int)($chkAdmin->fetch_assoc()['cnt'] ?? 0) : 0;
    if ($chkAdmin) {
        $chkAdmin->free();
    }
    if ($adminCnt === 0) {
        $defaultAdminHash = password_hash('multitool@', PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO `admins` (`username`, `password_hash`, `role`) VALUES ('admin', ?, 'superadmin')"
        );
        $stmt->bind_param('s', $defaultAdminHash);
        $stmt->execute();
        $stmt->close();
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `users` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`      VARCHAR(64)     NOT NULL,
        `password_hash` VARCHAR(255)    NOT NULL,
        `credits`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        `profile`       VARCHAR(32)     NOT NULL DEFAULT 'default',
        `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
        `note`          VARCHAR(255)    NOT NULL DEFAULT '',
        `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_login_at` DATETIME        NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `service_settings` (
        `setting_key`   VARCHAR(64)     NOT NULL,
        `setting_value` VARCHAR(255)    NOT NULL DEFAULT '',
        `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `credit_logs` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`      VARCHAR(64)     NOT NULL,
        `amount`        DECIMAL(12,2)   NOT NULL,
        `balance_after` DECIMAL(12,2)   NOT NULL,
        `type`          VARCHAR(32)     NOT NULL DEFAULT 'admin_add',
        `description`   VARCHAR(255)    NOT NULL DEFAULT '',
        `actor`         VARCHAR(64)     NOT NULL DEFAULT 'admin',
        `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_created` (`username`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `app_service_logs` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`   VARCHAR(64)     NOT NULL DEFAULT '',
        `otp_code`   VARCHAR(64)     NOT NULL DEFAULT '',
        `event_type` VARCHAR(48)     NOT NULL DEFAULT '',
        `details`    VARCHAR(255)    NOT NULL DEFAULT '',
        `ip`         VARCHAR(45)     NOT NULL DEFAULT '',
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_created` (`username`, `created_at`),
        KEY `idx_otp` (`otp_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_table(array $config): string
{
    $table = (string)($config['db']['table'] ?? 'otp');
    return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 ? $table : 'otp';
}

function file_store_path(array $config): string
{
    return store_state($config) . '/otps.json';
}

function file_store_read(array $config, $handle = null): array
{
    if ($handle === null) {
        $path = file_store_path($config);
        if (!is_file($path)) {
            $seed = [
                'TEST1234' => [
                    'status'     => 'valid',
                    'used'       => 0,
                    'expired_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                    'user'       => 'admin',
                    'profile'    => 'default',
                    'note'       => 'Default test OTP',
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'used_at'    => null,
                    'used_ip'    => null,
                ],
            ];
            @file_put_contents($path, (string)json_encode($seed, JSON_PRETTY_PRINT));
            return $seed;
        }
        $raw = (string)@file_get_contents($path);
    } else {
        rewind($handle);
        $raw = (string)stream_get_contents($handle);
    }

    $rows = json_decode($raw, true);
    return is_array($rows) ? $rows : [];
}

function file_store_update(array $config, callable $change): bool
{
    $path = file_store_path($config);
    if (!is_file($path)) {
        file_store_read($config);
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    $rows = file_store_read($config, $handle);
    $ok   = $change($rows);

    if ($ok) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $ok;
}

#endregion

#region Service Pricing (price-service.php)

function pricing_path(array $config): string
{
    return store_state($config) . '/pricing.json';
}

function pricing_defaults(): array
{
    return [
        'otp_price'           => 1.00,
        'otp_default_minutes' => 60,
        'currency_label'      => 'Credits',
        'min_credits_notice'  => 'Contact the administrator to recharge your account credits.',
        'profile_prices'      => [
            'default' => 1.00,
            'admin'   => 0.00,
        ],
        'updated_at'          => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

function pricing_load(array $config): array
{
    $defaults = pricing_defaults();

    $path   = pricing_path($config);
    $stored = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($stored)) {
        $defaults = array_replace_recursive($defaults, $stored);
    }

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $res  = $conn->query("SELECT setting_key, setting_value, updated_at FROM `service_settings`");
            while ($row = $res->fetch_assoc()) {
                $k = (string)$row['setting_key'];
                $v = (string)$row['setting_value'];
                if ($k === 'otp_price') {
                    $defaults['otp_price'] = max(0.0, round((float)$v, 2));
                } elseif ($k === 'otp_default_minutes') {
                    $defaults['otp_default_minutes'] = max(0, (int)$v);
                } elseif ($k === 'currency_label' && $v !== '') {
                    $defaults['currency_label'] = $v;
                } elseif ($k === 'min_credits_notice') {
                    $defaults['min_credits_notice'] = $v;
                } elseif ($k === 'profile_prices') {
                    $dec = json_decode($v, true);
                    if (is_array($dec)) {
                        $defaults['profile_prices'] = $dec;
                    }
                }
                if (!empty($row['updated_at'])) {
                    $defaults['updated_at'] = (string)$row['updated_at'];
                }
            }
            $res->free();
            $conn->close();
        } catch (Throwable $e) {
            // fallback to file
        }
    }

    $defaults['otp_price'] = max(0.0, round((float)$defaults['otp_price'], 2));
    $defaults['otp_default_minutes'] = max(0, (int)$defaults['otp_default_minutes']);
    return $defaults;
}

function pricing_save(array $config, array $newPricing): bool
{
    $current = pricing_load($config);
    $merged  = array_replace($current, $newPricing);
    $merged['otp_price']           = max(0.0, round((float)($merged['otp_price'] ?? 1.0), 2));
    $merged['otp_default_minutes'] = max(0, (int)($merged['otp_default_minutes'] ?? 60));
    $merged['currency_label']      = trim((string)($merged['currency_label'] ?? 'Credits')) ?: 'Credits';
    $merged['updated_at']          = gmdate('Y-m-d\TH:i:s\Z');

    if (isset($newPricing['profile_prices']) && is_array($newPricing['profile_prices'])) {
        $cleanPrices = [];
        foreach ($newPricing['profile_prices'] as $pName => $pVal) {
            $cleanPrices[(string)$pName] = max(0.0, round((float)$pVal, 2));
        }
        $merged['profile_prices'] = $cleanPrices;
    }

    @file_put_contents(
        pricing_path($config),
        (string)json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare(
                "INSERT INTO `service_settings` (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $pairs = [
                'otp_price'           => number_format((float)$merged['otp_price'], 2, '.', ''),
                'otp_default_minutes' => (string)$merged['otp_default_minutes'],
                'currency_label'      => (string)$merged['currency_label'],
                'min_credits_notice'  => (string)($merged['min_credits_notice'] ?? ''),
                'profile_prices'      => (string)json_encode($merged['profile_prices'] ?? []),
            ];
            foreach ($pairs as $k => $v) {
                $stmt->bind_param('ss', $k, $v);
                $stmt->execute();
            }
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
            // file already saved
        }
    }

    return true;
}

function otp_price_for_profile(array $config, string $profile = 'default'): float
{
    $pricing = pricing_load($config);
    if (isset($pricing['profile_prices'][$profile]) && is_numeric($pricing['profile_prices'][$profile])) {
        return max(0.0, round((float)$pricing['profile_prices'][$profile], 2));
    }
    return max(0.0, round((float)$pricing['otp_price'], 2));
}

#endregion

#region Users & Credits Management

function users_file_path(array $config): string
{
    return store_state($config) . '/users.json';
}

function users_file_read(array $config, $handle = null): array
{
    if ($handle === null) {
        $path = users_file_path($config);
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
    } else {
        rewind($handle);
        $raw = (string)stream_get_contents($handle);
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function users_file_update(array $config, callable $mutator): bool
{
    $path   = users_file_path($config);
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    $users = users_file_read($config, $handle);
    $ok    = $mutator($users);
    if ($ok) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $ok;
}

function normalize_user_row(string $username, array $row): array
{
    return [
        'username'      => $username,
        'password_hash' => (string)($row['password_hash'] ?? ''),
        'credits'       => round((float)($row['credits'] ?? 0.0), 2),
        'profile'       => (string)($row['profile'] ?? 'default'),
        'status'        => (($row['status'] ?? 'active') === 'disabled') ? 'disabled' : 'active',
        'note'          => (string)($row['note'] ?? ''),
        'created_at'    => (string)($row['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        'last_login_at' => $row['last_login_at'] ?? null,
    ];
}

function user_find(array $config, string $username): ?array
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    if (use_mysql($config)) {
        $conn = db($config);
        $stmt = $conn->prepare(
            "SELECT username, password_hash, credits, profile, status, note, created_at, last_login_at
             FROM `users` WHERE username = ? LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();

        return $row ? normalize_user_row((string)$row['username'], $row) : null;
    }

    $users = users_file_read($config);
    foreach ($users as $uKey => $row) {
        if (strcasecmp((string)$uKey, $username) === 0 && is_array($row)) {
            return normalize_user_row((string)$uKey, $row);
        }
    }
    return null;
}

function users_list(array $config): array
{
    if (use_mysql($config)) {
        $conn = db($config);
        $res  = $conn->query(
            "SELECT username, password_hash, credits, profile, status, note, created_at, last_login_at
             FROM `users` ORDER BY id DESC"
        );
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = normalize_user_row((string)$row['username'], $row);
        }
        $res->free();
        $conn->close();
        return $out;
    }

    $out = [];
    foreach (users_file_read($config) as $uKey => $row) {
        if (is_array($row)) {
            $out[] = normalize_user_row((string)$uKey, $row);
        }
    }
    usort($out, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

function user_create(
    array $config,
    string $username,
    string $password,
    float $initialCredits = 0.0,
    string $profile = 'default',
    string $note = ''
): array {
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3–32 characters (letters, digits, ., _, -).');
    }
    if (strlen($password) < 4) {
        throw new InvalidArgumentException('Password must be at least 4 characters.');
    }
    if (user_find($config, $username) !== null) {
        throw new RuntimeException("Username '$username' already exists.");
    }

    $hash    = password_hash($password, PASSWORD_BCRYPT);
    $credits = max(0.0, round($initialCredits, 2));
    $profile = trim($profile) !== '' ? trim($profile) : 'default';
    $note    = substr(trim($note), 0, 200);
    $now     = gmdate('Y-m-d H:i:s');

    if (use_mysql($config)) {
        $conn = db($config);
        $stmt = $conn->prepare(
            "INSERT INTO `users` (username, password_hash, credits, profile, status, note, created_at)
             VALUES (?, ?, ?, ?, 'active', ?, ?)"
        );
        $stmt->bind_param('ssdsss', $username, $hash, $credits, $profile, $note, $now);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } else {
        users_file_update($config, function (array &$users) use ($username, $hash, $credits, $profile, $note): bool {
            $users[$username] = [
                'username'      => $username,
                'password_hash' => $hash,
                'credits'       => $credits,
                'profile'       => $profile,
                'status'        => 'active',
                'note'          => $note,
                'created_at'    => gmdate('Y-m-d\TH:i:s\Z'),
                'last_login_at' => null,
            ];
            return true;
        });
    }

    if ($credits > 0) {
        credit_log_record($config, $username, $credits, $credits, 'initial_credit', 'Initial account credits', 'admin');
    }

    return user_find($config, $username) ?? normalize_user_row($username, [
        'credits' => $credits,
        'profile' => $profile,
        'status'  => 'active',
        'note'    => $note,
    ]);
}

function verify_password_compat(string $plain, string $stored): bool
{
    if ($stored === '' || $plain === '') {
        return false;
    }
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$') || str_starts_with($stored, '$argon2')) {
        return password_verify($plain, $stored);
    }
    if (strlen($stored) === 64 && ctype_xdigit($stored)) {
        return hash_equals(strtolower($stored), hash('sha256', $plain));
    }
    if (str_starts_with($stored, 'sha256:')) {
        return hash_equals(strtolower(substr($stored, 7)), hash('sha256', $plain));
    }
    return hash_equals($stored, $plain);
}

function admin_credentials_file(array $config): string
{
    return store_state($config) . '/admin_credentials.json';
}

function admin_current_account(array $config): array
{
    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $res  = $conn->query("SELECT id, username, role, created_at, last_login_at FROM `admins` ORDER BY id ASC LIMIT 1");
            $row  = $res ? $res->fetch_assoc() : null;
            if ($res) {
                $res->free();
            }
            $conn->close();
            if (is_array($row) && !empty($row['username'])) {
                return [
                    'id'            => (int)($row['id'] ?? 1),
                    'username'      => (string)$row['username'],
                    'role'          => (string)($row['role'] ?? 'superadmin'),
                    'last_login_at' => $row['last_login_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
        }
    }

    $file = admin_credentials_file($config);
    if (is_file($file)) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && !empty($data['username'])) {
            return [
                'id'            => 1,
                'username'      => (string)$data['username'],
                'role'          => 'superadmin',
                'last_login_at' => $data['updated_at'] ?? null,
            ];
        }
    }

    return [
        'id'            => 1,
        'username'      => (string)($config['admin']['user'] ?? 'admin'),
        'role'          => 'superadmin',
        'last_login_at' => null,
    ];
}

function admin_update_credentials(array $config, string $newUsername, string $newPassword): array
{
    $newUsername = trim($newUsername);
    if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $newUsername)) {
        throw new InvalidArgumentException('O nome de admin deve ter entre 3 e 64 caracteres (letras, números, ., _, -, @).');
    }
    if (strlen($newPassword) < 4) {
        throw new InvalidArgumentException('A nova senha deve ter pelo menos 4 caracteres.');
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $now     = gmdate('Y-m-d H:i:s');

    if (use_mysql($config)) {
        $conn = db($config);
        $res  = $conn->query("SELECT id FROM `admins` ORDER BY id ASC LIMIT 1");
        $row  = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }

        if (is_array($row) && isset($row['id'])) {
            $id   = (int)$row['id'];
            $stmt = $conn->prepare("UPDATE `admins` SET username = ?, password_hash = ? WHERE id = ?");
            $stmt->bind_param('ssi', $newUsername, $newHash, $id);
            $stmt->execute();
            $stmt->close();

            // Remove any other duplicate admin rows so there is no ambiguity
            $del = $conn->prepare("DELETE FROM `admins` WHERE id <> ?");
            $del->bind_param('i', $id);
            $del->execute();
            $del->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO `admins` (username, password_hash, role, created_at) VALUES (?, ?, 'superadmin', ?)");
            $stmt->bind_param('sss', $newUsername, $newHash, $now);
            $stmt->execute();
            $stmt->close();
        }
        $conn->close();
    }

    @file_put_contents(
        admin_credentials_file($config),
        (string)json_encode([
            'username'      => $newUsername,
            'password_hash' => $newHash,
            'updated_at'    => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return [
        'username'   => $newUsername,
        'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ];
}

function admin_verify_login(array $config, string $username, string $password): ?string
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return null;
    }

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare("SELECT id, username, password_hash FROM `admins` WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && verify_password_compat($password, (string)$row['password_hash'])) {
                $now = gmdate('Y-m-d H:i:s');
                $stored = (string)$row['password_hash'];
                if (!str_starts_with($stored, '$2y$')) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT);
                    $up = $conn->prepare("UPDATE `admins` SET password_hash = ?, last_login_at = ? WHERE id = ?");
                    $id = (int)$row['id'];
                    $up->bind_param('ssi', $newHash, $now, $id);
                    $up->execute();
                    $up->close();
                } else {
                    $up = $conn->prepare("UPDATE `admins` SET last_login_at = ? WHERE id = ?");
                    $id = (int)$row['id'];
                    $up->bind_param('si', $now, $id);
                    $up->execute();
                    $up->close();
                }
                $conn->close();
                return (string)$row['username'];
            }

            // If `admins` table in MySQL has accounts, do not fall back to hardcoded config.php credentials
            $cntRes = $conn->query("SELECT COUNT(*) AS cnt FROM `admins`");
            $cnt    = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            if ($cntRes) {
                $cntRes->free();
            }
            $conn->close();
            if ($cnt > 0) {
                return null;
            }
        } catch (Throwable $e) {
            // fallback to local file / config.php below
        }
    }

    $credFile = admin_credentials_file($config);
    if (is_file($credFile)) {
        $saved = json_decode((string)@file_get_contents($credFile), true);
        if (is_array($saved) && !empty($saved['username']) && !empty($saved['password_hash'])) {
            if (hash_equals((string)$saved['username'], $username) &&
                verify_password_compat($password, (string)$saved['password_hash'])) {
                return (string)$saved['username'];
            }
            return null;
        }
    }

    $cfgUser = (string)($config['admin']['user'] ?? 'admin');
    $cfgPass = (string)($config['admin']['password'] ?? '');
    $cfgHash = (string)($config['admin']['password_hash'] ?? $config['admin']['pass_hash'] ?? '');

    if (hash_equals($cfgUser, $username)) {
        if ($cfgPass !== '' && verify_password_compat($password, $cfgPass)) {
            return $cfgUser;
        }
        if ($cfgHash !== '' && verify_password_compat($password, $cfgHash)) {
            return $cfgUser;
        }
    }

    return null;
}

function user_verify_login(array $config, string $username, string $password): ?array
{
    $u = user_find($config, $username);
    if ($u === null) {
        return null;
    }
    if (!verify_password_compat($password, $u['password_hash'])) {
        return null;
    }

    $now = gmdate('Y-m-d H:i:s');
    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare("UPDATE `users` SET last_login_at = ? WHERE username = ?");
            $stmt->bind_param('ss', $now, $u['username']);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
        }
    } else {
        $realUser = $u['username'];
        users_file_update($config, function (array &$users) use ($realUser): bool {
            if (isset($users[$realUser])) {
                $users[$realUser]['last_login_at'] = gmdate('Y-m-d\TH:i:s\Z');
                return true;
            }
            return false;
        });
    }

    return $u;
}

function user_update(array $config, string $username, array $fields): bool
{
    $u = user_find($config, $username);
    if ($u === null) {
        return false;
    }
    $realUser = $u['username'];

    $status  = isset($fields['status']) ? ($fields['status'] === 'disabled' ? 'disabled' : 'active') : $u['status'];
    $profile = isset($fields['profile']) ? trim((string)$fields['profile']) : $u['profile'];
    $note    = isset($fields['note']) ? substr(trim((string)$fields['note']), 0, 200) : $u['note'];
    $hash    = !empty($fields['new_password'])
        ? password_hash((string)$fields['new_password'], PASSWORD_BCRYPT)
        : $u['password_hash'];

    if (use_mysql($config)) {
        $conn = db($config);
        $stmt = $conn->prepare(
            "UPDATE `users` SET password_hash = ?, profile = ?, status = ?, note = ? WHERE username = ?"
        );
        $stmt->bind_param('sssss', $hash, $profile, $status, $note, $realUser);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return true;
    }

    return users_file_update($config, function (array &$users) use ($realUser, $hash, $profile, $status, $note): bool {
        if (!isset($users[$realUser])) {
            return false;
        }
        $users[$realUser]['password_hash'] = $hash;
        $users[$realUser]['profile']       = $profile;
        $users[$realUser]['status']        = $status;
        $users[$realUser]['note']          = $note;
        return true;
    });
}

function user_delete(array $config, string $username): bool
{
    $u = user_find($config, $username);
    if ($u === null) {
        return false;
    }
    $realUser = $u['username'];

    if (use_mysql($config)) {
        $conn = db($config);
        $stmt = $conn->prepare("DELETE FROM `users` WHERE username = ?");
        $stmt->bind_param('s', $realUser);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        $conn->close();
        return $ok;
    }

    return users_file_update($config, function (array &$users) use ($realUser): bool {
        if (!isset($users[$realUser])) {
            return false;
        }
        unset($users[$realUser]);
        return true;
    });
}

/**
 * Atomically adjusts a user's credit balance ($delta can be positive or negative).
 * If $delta < 0 and current credits + $delta < 0, returns null (insufficient credits).
 */
function user_adjust_credits(
    array $config,
    string $username,
    float $delta,
    string $type,
    string $description,
    string $actor = 'admin'
): ?array {
    $u = user_find($config, $username);
    if ($u === null) {
        return null;
    }
    $realUser = $u['username'];
    $delta    = round($delta, 2);

    if (use_mysql($config)) {
        $conn = db($config);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT credits FROM `users` WHERE username = ? FOR UPDATE");
            $stmt->bind_param('s', $realUser);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $conn->rollback();
                $conn->close();
                return null;
            }

            $current = round((float)$row['credits'], 2);
            $newBal  = round($current + $delta, 2);
            if ($delta < 0 && $newBal < -0.001) {
                $conn->rollback();
                $conn->close();
                return null;
            }
            $newBal = max(0.0, $newBal);

            $up = $conn->prepare("UPDATE `users` SET credits = ? WHERE username = ?");
            $up->bind_param('ds', $newBal, $realUser);
            $up->execute();
            $up->close();

            $conn->commit();
            $conn->close();

            credit_log_record($config, $realUser, $delta, $newBal, $type, $description, $actor);
            return user_find($config, $realUser);
        } catch (Throwable $e) {
            @$conn->rollback();
            @$conn->close();
            throw $e;
        }
    }

    $newBal = null;
    $ok = users_file_update($config, function (array &$users) use ($realUser, $delta, &$newBal): bool {
        if (!isset($users[$realUser])) {
            return false;
        }
        $current   = round((float)($users[$realUser]['credits'] ?? 0.0), 2);
        $candidate = round($current + $delta, 2);
        if ($delta < 0 && $candidate < -0.001) {
            return false;
        }
        $newBal = max(0.0, $candidate);
        $users[$realUser]['credits'] = $newBal;
        return true;
    });

    if (!$ok || $newBal === null) {
        return null;
    }

    credit_log_record($config, $realUser, $delta, $newBal, $type, $description, $actor);
    return user_find($config, $realUser);
}

/**
 * Generates an OTP for a registered user, verifying they have enough credits
 * according to the current price in price-service.php.
 */
function user_generate_otp_with_credits(array $config, string $username, string $note = ''): array
{
    $user = user_find($config, $username);
    if ($user === null) {
        return ['ok' => false, 'error' => 'User account not found.'];
    }
    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Your account is currently disabled. Contact the administrator.'];
    }

    $pricing  = pricing_load($config);
    $profile  = $user['profile'] ?: 'default';
    $cost     = otp_price_for_profile($config, $profile);
    $currency = (string)($pricing['currency_label'] ?? 'Credits');
    $minutes  = (int)($pricing['otp_default_minutes'] ?? 60);

    if ($user['credits'] < $cost) {
        return [
            'ok'    => false,
            'error' => sprintf(
                'Insufficient credits to generate OTP. Your balance: %.2f %s · OTP price: %.2f %s.',
                $user['credits'],
                $currency,
                $cost,
                $currency
            ),
        ];
    }

    $code = otp_generate_code(10);
    while (otp_find($config, $code) !== null) {
        $code = otp_generate_code(10);
    }

    if ($cost > 0) {
        $updatedUser = user_adjust_credits(
            $config,
            $user['username'],
            -$cost,
            'otp_generate',
            "Generated OTP code $code",
            $user['username']
        );
        if ($updatedUser === null) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Insufficient credits to generate OTP. Required: %.2f %s.',
                    $cost,
                    $currency
                ),
            ];
        }
        $newBalance = (float)$updatedUser['credits'];
    } else {
        $newBalance = (float)$user['credits'];
    }

    $expiresAt = $minutes > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $minutes * 60) : null;
    otp_upsert($config, $code, [
        'status'     => 'valid',
        'used'       => false,
        'user_id'    => $user['username'],
        'profile'    => $profile,
        'expires_at' => $expiresAt,
        'note'       => substr(trim($note), 0, 200),
    ]);

    app_service_log_record(
        $config,
        $user['username'],
        $code,
        'OTP_GENERATED',
        sprintf('Generated in user portal (cost: %.2f %s)', $cost, $currency)
    );

    return [
        'ok'         => true,
        'code'       => $code,
        'cost'       => $cost,
        'balance'    => $newBalance,
        'expires_at' => $expiresAt,
        'minutes'    => $minutes,
    ];
}

#endregion

#region Credit Logs & App Service Logs

function credit_logs_file_path(array $config): string
{
    return store_state($config) . '/credit_logs.jsonl';
}

function credit_log_record(
    array $config,
    string $username,
    float $amount,
    float $balanceAfter,
    string $type,
    string $description,
    string $actor = 'admin'
): void {
    $now = gmdate('Y-m-d H:i:s');
    $row = [
        'username'      => $username,
        'amount'        => round($amount, 2),
        'balance_after' => round($balanceAfter, 2),
        'type'          => $type,
        'description'   => substr($description, 0, 255),
        'actor'         => $actor,
        'created_at'    => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare(
                "INSERT INTO `credit_logs` (username, amount, balance_after, type, description, actor, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'sddssss',
                $row['username'],
                $row['amount'],
                $row['balance_after'],
                $row['type'],
                $row['description'],
                $row['actor'],
                $now
            );
            $stmt->execute();
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
        }
    }

    @file_put_contents(
        credit_logs_file_path($config),
        json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function credit_logs_list(array $config, ?string $username = null, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            if ($username !== null && $username !== '') {
                $stmt = $conn->prepare(
                    "SELECT username, amount, balance_after, type, description, actor, created_at
                     FROM `credit_logs` WHERE username = ? ORDER BY id DESC LIMIT ?"
                );
                $stmt->bind_param('si', $username, $limit);
                $stmt->execute();
                $res = $stmt->get_result();
            } else {
                $res = $conn->query(
                    "SELECT username, amount, balance_after, type, description, actor, created_at
                     FROM `credit_logs` ORDER BY id DESC LIMIT {$limit}"
                );
            }
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $r['amount']        = round((float)$r['amount'], 2);
                $r['balance_after'] = round((float)$r['balance_after'], 2);
                $rows[] = $r;
            }
            if (isset($stmt)) {
                $stmt->close();
            }
            $conn->close();
            return $rows;
        } catch (Throwable $e) {
        }
    }

    $path = credit_logs_file_path($config);
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out   = [];
    foreach (array_reverse($lines) as $line) {
        $item = json_decode($line, true);
        if (!is_array($item)) {
            continue;
        }
        if ($username !== null && $username !== '' && strcasecmp((string)($item['username'] ?? ''), $username) !== 0) {
            continue;
        }
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function app_service_logs_file_path(array $config): string
{
    return store_state($config) . '/app_service_logs.jsonl';
}

function app_service_log_record(
    array $config,
    string $username,
    string $otpCode,
    string $eventType,
    string $details = ''
): void {
    $ip  = client_ip();
    $now = gmdate('Y-m-d H:i:s');
    $row = [
        'username'   => $username,
        'otp_code'   => $otpCode,
        'event_type' => $eventType,
        'details'    => substr($details, 0, 255),
        'ip'         => $ip,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare(
                "INSERT INTO `app_service_logs` (username, otp_code, event_type, details, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssssss',
                $row['username'],
                $row['otp_code'],
                $row['event_type'],
                $row['details'],
                $row['ip'],
                $now
            );
            $stmt->execute();
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
        }
    }

    @file_put_contents(
        app_service_logs_file_path($config),
        json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function app_service_logs_list(array $config, ?string $username = null, int $limit = 150): array
{
    $limit = max(1, min(500, $limit));

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            if ($username !== null && $username !== '') {
                $stmt = $conn->prepare(
                    "SELECT username, otp_code, event_type, details, ip, created_at
                     FROM `app_service_logs` WHERE username = ? ORDER BY id DESC LIMIT ?"
                );
                $stmt->bind_param('si', $username, $limit);
                $stmt->execute();
                $res = $stmt->get_result();
            } else {
                $res = $conn->query(
                    "SELECT username, otp_code, event_type, details, ip, created_at
                     FROM `app_service_logs` ORDER BY id DESC LIMIT {$limit}"
                );
            }
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            if (isset($stmt)) {
                $stmt->close();
            }
            $conn->close();
            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {
        }
    }

    $path = app_service_logs_file_path($config);
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out   = [];
    foreach (array_reverse($lines) as $line) {
        $item = json_decode($line, true);
        if (!is_array($item)) {
            continue;
        }
        if ($username !== null && $username !== '' && strcasecmp((string)($item['username'] ?? ''), $username) !== 0) {
            continue;
        }
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

#endregion

#region OTP Operations (Admin, User & App API)

function otp_generate_code(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max      = strlen($alphabet) - 1;
    $out      = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function normalize_otp_entry(string $code, array $row): array
{
    $state     = otp_verdict_of($row);
    $userField = trim((string)($row['user_id'] ?? $row['user'] ?? 'admin'));
    $profile   = trim((string)($row['profile'] ?? ''));
    if ($profile === '') {
        $profile = 'default';
    }

    return [
        'code'       => $code,
        'otp'        => $code,
        'status'     => (string)($row['status'] ?? 'valid'),
        'state'      => $state,
        'used'       => (int)($row['used'] ?? 0),
        'user'       => $userField,
        'user_id'    => $userField,
        'profile'    => $profile,
        'created_at' => (string)($row['created_at'] ?? ''),
        'expires_at' => $row['expires_at'] ?? $row['expired_at'] ?? null,
        'expired_at' => $row['expired_at'] ?? $row['expires_at'] ?? null,
        'used_at'    => $row['used_at'] ?? null,
        'used_ip'    => $row['used_ip'] ?? null,
        'note'       => (string)($row['note'] ?? ''),
    ];
}

function otp_find(array $config, string $otp): ?array
{
    $otp = trim($otp);
    if ($otp === '') {
        return null;
    }

    if (use_mysql($config)) {
        try {
            $conn  = db($config);
            $table = db_table($config);
            $stmt  = $conn->prepare("SELECT otp, status, used, expired_at, user, created_at FROM `{$table}` WHERE UPPER(otp) = UPPER(?) LIMIT 1");
            $stmt->bind_param('s', $otp);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($row !== null) {
                $canonicalCode = (string)($row['otp'] ?? $otp);
                $allMeta       = file_store_read($config);
                $meta          = $allMeta[$canonicalCode] ?? $allMeta[$otp] ?? [];
                if (is_array($meta)) {
                    $row = array_merge($meta, $row);
                }
                return normalize_otp_entry($canonicalCode, $row);
            }
        } catch (Throwable $e) {
            // Fall through to file store
        }
    }

    $allRows = file_store_read($config);
    if (isset($allRows[$otp]) && is_array($allRows[$otp])) {
        return normalize_otp_entry($otp, $allRows[$otp]);
    }
    foreach ($allRows as $codeKey => $r) {
        if (strcasecmp((string)$codeKey, $otp) === 0 && is_array($r)) {
            return normalize_otp_entry((string)$codeKey, $r);
        }
    }
    return null;
}

function parse_utc_timestamp(string $dateStr): ?int
{
    $s = trim($dateStr);
    if ($s === '' || str_starts_with($s, '0000-00-00')) {
        return null;
    }
    // If string is SQL DATETIME "YYYY-MM-DD HH:MM:SS" without timezone, treat as UTC
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $s) === 1) {
        $s .= ' UTC';
    }
    $ts = strtotime($s);
    return ($ts !== false && $ts > 0) ? $ts : null;
}

function otp_verdict_of(array $row): string
{
    if ((int)($row['used'] ?? 0) === 1) {
        return 'used';
    }

    $status = strtolower(trim((string)($row['status'] ?? 'valid')));
    if ($status === 'used') {
        return 'used';
    }
    if ($status === 'expired') {
        return 'expired';
    }
    if (!in_array($status, ['valid', 'active', 'unused', 'ok', ''], true)) {
        return 'inactive';
    }

    $expiredAt = (string)($row['expired_at'] ?? $row['expires_at'] ?? '');
    $expTs     = parse_utc_timestamp($expiredAt);
    if ($expTs !== null && time() > $expTs) {
        return 'expired';
    }

    return 'valid';
}

function otp_classify(array $config, string $otp): string
{
    $row = otp_find($config, $otp);
    return $row === null ? 'not_found' : $row['state'];
}

/**
 * Burns the OTP code atomically and returns the normalized OTP entry on success, or null on failure.
 */
function otp_consume(array $config, string $otp): ?array
{
    $existing = otp_find($config, $otp);
    if ($existing === null || $existing['state'] !== 'valid') {
        return null;
    }

    $canonicalOtp = (string)($existing['code'] ?? $otp);
    $ip  = client_ip();
    $now = gmdate('Y-m-d\TH:i:s\Z');

    if (use_mysql($config)) {
        try {
            $conn  = db($config);
            $table = db_table($config);
            $stmt  = $conn->prepare("UPDATE `{$table}` SET status='used', used=1 WHERE UPPER(otp) = UPPER(?) AND used = 0");
            $stmt->bind_param('s', $canonicalOtp);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
            // Continue to update file store as well
        }
    }

    file_store_update($config, function (array &$rows) use ($canonicalOtp, $ip, $now, $existing): bool {
        if (!isset($rows[$canonicalOtp])) {
            $rows[$canonicalOtp] = $existing;
        }
        $rows[$canonicalOtp]['used']    = 1;
        $rows[$canonicalOtp]['status']  = 'used';
        $rows[$canonicalOtp]['used_at'] = $now;
        $rows[$canonicalOtp]['used_ip'] = $ip;
        return true;
    });

    $existing['used']    = 1;
    $existing['status']  = 'used';
    $existing['state']   = 'used';
    $existing['used_at'] = $now;
    $existing['used_ip'] = $ip;
    return $existing;
}

function otp_upsert(array $config, string $code, array $data): void
{
    $status    = (string)($data['status'] ?? 'valid');
    $used      = !empty($data['used']) ? 1 : 0;
    $userId    = trim((string)($data['user_id'] ?? $data['user'] ?? 'admin')) ?: 'admin';
    $profile   = trim((string)($data['profile'] ?? 'default')) ?: 'default';
    $expiresAt = $data['expires_at'] ?? $data['expired_at'] ?? null;
    $note      = (string)($data['note'] ?? '');
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');

    if (use_mysql($config)) {
        $conn      = db($config);
        $table     = db_table($config);
        $sqlExpiry = $expiresAt ? gmdate('Y-m-d H:i:s', (int)strtotime((string)$expiresAt)) : null;
        $stmt      = $conn->prepare(
            "INSERT INTO `{$table}` (otp, status, used, expired_at, user)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), used = VALUES(used),
                                     expired_at = VALUES(expired_at), user = VALUES(user)"
        );
        $stmt->bind_param('ssiss', $code, $status, $used, $sqlExpiry, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }

    file_store_update($config, function (array &$rows) use ($code, $status, $used, $userId, $profile, $expiresAt, $note, $createdAt): bool {
        $rows[$code] = [
            'status'     => $status,
            'used'       => $used,
            'expired_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'user'       => $userId,
            'user_id'    => $userId,
            'profile'    => $profile,
            'note'       => $note,
            'created_at' => $createdAt,
            'used_at'    => null,
            'used_ip'    => null,
        ];
        return true;
    });
}

function otp_insert(array $config, string $otp, int $ttlSeconds, string $profile): void
{
    $expiresAt = $ttlSeconds > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds) : null;
    otp_upsert($config, $otp, [
        'status'     => 'valid',
        'used'       => false,
        'user_id'    => $profile,
        'profile'    => $profile,
        'expires_at' => $expiresAt,
    ]);
}

function otp_filter_sql(string $filter): string
{
    switch ($filter) {
        case 'valid':
            return "used = 0 AND status = 'valid' AND (expired_at IS NULL OR expired_at >= NOW())";
        case 'used':
            return "used = 1 OR status = 'used'";
        case 'expired':
            return "used = 0 AND (status = 'expired' OR (status = 'valid' AND expired_at IS NOT NULL AND expired_at < NOW()))";
        case 'inactive':
            return "used = 0 AND status NOT IN ('valid','used','expired')";
        default:
            return '';
    }
}

function otp_list(array $config, string $filter = 'all', int $limit = 200): array
{
    $limit     = max(1, min(1000, $limit));
    $fileMeta  = file_store_read($config);

    if (use_mysql($config)) {
        $conn  = db($config);
        $table = db_table($config);
        $sql   = "SELECT otp, status, used, expired_at, user, created_at FROM `{$table}`";
        $where = otp_filter_sql($filter);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $rows   = [];
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $code = (string)$row['otp'];
            if (isset($fileMeta[$code]) && is_array($fileMeta[$code])) {
                $row = array_merge($fileMeta[$code], $row);
            }
            $rows[] = normalize_otp_entry($code, $row);
        }
        $result->free();
        $conn->close();

        return $rows;
    }

    $rows = [];
    foreach ($fileMeta as $code => $row) {
        if (is_array($row)) {
            $rows[] = normalize_otp_entry((string)$code, $row);
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

    $out = [];
    foreach ($rows as $row) {
        if ($filter !== 'all' && $row['state'] !== $filter) {
            continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function otp_list_for_user(array $config, string $username, string $filter = 'all', int $limit = 150): array
{
    $all = otp_list($config, $filter, 1000);
    $out = [];
    foreach ($all as $row) {
        if (strcasecmp($row['user_id'], $username) === 0) {
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

function otp_stats_for_user(array $config, string $username): array
{
    $all    = otp_list_for_user($config, $username, 'all', 1000);
    $counts = ['valid' => 0, 'used' => 0, 'expired' => 0, 'inactive' => 0, 'total' => 0];
    foreach ($all as $row) {
        $st = $row['state'];
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
        $counts['total']++;
    }
    return $counts;
}

function otp_stats(array $config): array
{
    if (use_mysql($config)) {
        $conn  = db($config);
        $table = db_table($config);
        $sql   = "SELECT
            SUM(used = 1 OR status = 'used') AS used_count,
            SUM(used = 0 AND status = 'valid' AND (expired_at IS NULL OR expired_at >= NOW())) AS valid_count,
            SUM(used = 0 AND (status = 'expired' OR (status = 'valid' AND expired_at IS NOT NULL AND expired_at < NOW()))) AS expired_count,
            SUM(used = 0 AND status NOT IN ('valid','used','expired')) AS inactive_count,
            COUNT(*) AS total_count
            FROM `{$table}`";
        $row = $conn->query($sql)->fetch_assoc();
        $conn->close();

        return [
            'valid'    => (int)($row['valid_count'] ?? 0),
            'used'     => (int)($row['used_count'] ?? 0),
            'expired'  => (int)($row['expired_count'] ?? 0),
            'inactive' => (int)($row['inactive_count'] ?? 0),
            'total'    => (int)($row['total_count'] ?? 0),
        ];
    }

    $counts = ['valid' => 0, 'used' => 0, 'expired' => 0, 'inactive' => 0, 'total' => 0];
    foreach (file_store_read($config) as $code => $row) {
        if (is_array($row)) {
            $counts[otp_verdict_of($row)]++;
            $counts['total']++;
        }
    }

    return $counts;
}

function otp_counts(array $config): array
{
    return otp_stats($config);
}

function otp_set_status(array $config, string $otp, string $status): bool
{
    if (!in_array($status, ['valid', 'used', 'expired', 'inactive'], true)) {
        return false;
    }

    if (use_mysql($config)) {
        $conn  = db($config);
        $table = db_table($config);
        $stmt  = $conn->prepare("UPDATE `{$table}` SET status = ? WHERE otp = ?");
        $stmt->bind_param('ss', $status, $otp);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0;
        $stmt->close();
        $conn->close();
    } else {
        $ok = true;
    }

    file_store_update($config, function (array &$rows) use ($otp, $status): bool {
        if (!isset($rows[$otp])) {
            return false;
        }
        $rows[$otp]['status'] = $status;
        return true;
    });

    return $ok;
}

function otp_revoke(array $config, string $otp): bool
{
    return otp_set_status($config, $otp, 'inactive');
}

function otp_delete(array $config, string $otp): bool
{
    $ok = false;
    if (use_mysql($config)) {
        $conn  = db($config);
        $table = db_table($config);
        $stmt  = $conn->prepare("DELETE FROM `{$table}` WHERE otp = ?");
        $stmt->bind_param('s', $otp);
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        $conn->close();
    }

    $fileOk = file_store_update($config, function (array &$rows) use ($otp): bool {
        if (!isset($rows[$otp])) {
            return false;
        }
        unset($rows[$otp]);
        return true;
    });

    return $ok || $fileOk;
}

function otp_purge_finished(array $config): int
{
    $removed = 0;
    if (use_mysql($config)) {
        $conn  = db($config);
        $table = db_table($config);
        $conn->query("DELETE FROM `{$table}` WHERE used = 1 OR status IN ('used','expired','inactive') OR (expired_at IS NOT NULL AND expired_at < NOW())");
        $removed = (int)$conn->affected_rows;
        $conn->close();
    }

    file_store_update($config, function (array &$rows) use (&$removed): bool {
        foreach ($rows as $code => $row) {
            if (!is_array($row) || otp_verdict_of($row) !== 'valid') {
                unset($rows[$code]);
                $removed++;
            }
        }
        return true;
    });

    return $removed;
}

function otp_purge(array $config): int
{
    return otp_purge_finished($config);
}

function mask_otp(string $otp): string
{
    $len = strlen($otp);
    if ($len <= 6) {
        return str_repeat('*', $len);
    }
    return substr($otp, 0, 3) . str_repeat('*', max(1, $len - 5)) . substr($otp, -2);
}

function verify_shared_api_token(array $config, string $provided): bool
{
    $configured = trim((string)($config['api_token'] ?? ''));
    $appToken   = trim((string)($config['app_api_token'] ?? ''));

    if ($configured === '') {
        return $provided === '' || ($appToken !== '' && hash_equals($appToken, $provided));
    }
    if (hash_equals($configured, $provided)) {
        return true;
    }
    return $appToken !== '' && hash_equals($appToken, $provided);
}

#endregion

#region DA Assets & Models

function assets_dir(array $config): string
{
    $custom = trim((string)($config['assets_dir'] ?? ''));
    return $custom !== '' ? rtrim($custom, '/\\') : __DIR__ . '/assets';
}

function detect_brand_from_model(string $display): string
{
    $lower = strtolower($display);
    if (str_contains($lower, 'oneplus') || str_contains($lower, 'one plus') || preg_match('/\b(cph2[456]\d\d|pj[a-z0-9]+|op\d+)\b/i', $display)) {
        if (str_contains($lower, 'oneplus') || str_contains($lower, 'one plus') || str_contains($lower, 'nord')) {
            return 'OnePlus';
        }
    }
    if (str_contains($lower, 'oppo') || str_contains($lower, 'reno') || str_contains($lower, 'find ') || preg_match('/\b(cph\d{4}|p[a-z]{2}\d{2})\b/i', $display)) {
        if (!str_contains($lower, 'realme') && !str_contains($lower, 'rmx')) {
            return 'Oppo';
        }
    }
    if (str_contains($lower, 'realme') || str_contains($lower, 'rmx') || str_contains($lower, 'narzo') || str_contains($lower, 'gt ')) {
        return 'Realme';
    }
    return 'Realme';
}

function normalize_brand_name(string $brand, string $fallbackModel = ''): string
{
    $b = strtolower(trim($brand));
    if ($b === 'oppo') {
        return 'Oppo';
    }
    if ($b === 'oneplus' || $b === 'one plus' || $b === '1+') {
        return 'OnePlus';
    }
    if ($b === 'realme') {
        return 'Realme';
    }
    return detect_brand_from_model($fallbackModel);
}

function da_variant_name(string $variant): ?string
{
    $base = trim(basename(str_replace('\\', '/', $variant)));
    if ($base === '') {
        return null;
    }
    if (str_ends_with(strtolower($base), '.qc')) {
        $base = substr($base, 0, -3);
    }
    if (!preg_match('/\.(bin|mib|mbn|elf|img|auth|da)$/i', $base)) {
        $base .= '.bin';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,96}\.(bin|mib|mbn|elf|img|auth|da)$/iD', $base) !== 1) {
        return null;
    }
    return $base;
}

function models_file_path(array $config): string
{
    return $config['models_file'] ?? (assets_dir($config) . '/server_models.json');
}

function models_meta(array $config): array
{
    $path = models_file_path($config);
    $raw  = is_file($path) ? (string)@file_get_contents($path) : '';
    $data = json_decode($raw, true);
    $syncAt = is_array($data) && !empty($data['synchronized_at'])
        ? (string)$data['synchronized_at']
        : (is_file($path) ? gmdate('Y-m-d\TH:i:s\Z', (int)@filemtime($path)) : '');

    return [
        'synchronized_at' => $syncAt,
        'file_mtime'      => is_file($path) ? (int)@filemtime($path) : 0,
    ];
}

function models_list(array $config): array
{
    $path = models_file_path($config);
    $raw  = is_file($path) ? (string)@file_get_contents($path) : '';
    $data = json_decode($raw, true);

    $models  = [];
    $entries = is_array($data['mtk_models'] ?? null) ? $data['mtk_models'] : [];
    foreach ($entries as $entry) {
        $display = trim((string)($entry['display'] ?? ''));
        $variant = da_variant_name((string)($entry['da_variant'] ?? ''));
        if ($display === '' || $variant === null) {
            continue;
        }
        $brand = normalize_brand_name((string)($entry['brand'] ?? ''), $display);
        $isNew = !empty($entry['is_new']);
        $ops   = trim((string)($entry['operations'] ?? ''));
        if ($ops === '') {
            $ops = 'Format Data · Erase FRP · Unlock';
        }
        $updatedAt = trim((string)($entry['updated_at'] ?? ''));

        $models[] = [
            'display'    => $display,
            'da_variant' => $variant,
            'brand'      => $brand,
            'is_new'     => $isNew,
            'operations' => $ops,
            'updated_at' => $updatedAt,
        ];
    }

    return $models;
}

function models_save(array $config, array $models): bool
{
    $path = models_file_path($config);
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $cleaned = [];
    foreach ($models as $m) {
        $display = trim((string)($m['display'] ?? ''));
        $variant = da_variant_name((string)($m['da_variant'] ?? ''));
        if ($display === '' || $variant === null) {
            continue;
        }
        $brand = normalize_brand_name((string)($m['brand'] ?? ''), $display);
        $isNew = !empty($m['is_new']);
        $ops   = trim((string)($m['operations'] ?? ''));
        if ($ops === '') {
            $ops = 'Format Data · Erase FRP · Unlock';
        }
        $cleaned[] = [
            'display'    => $display,
            'da_variant' => $variant,
            'brand'      => $brand,
            'is_new'     => $isNew,
            'operations' => $ops,
            'updated_at' => (string)($m['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        ];
    }

    $payload = [
        'synchronized_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'mtk_models'      => $cleaned,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function da_asset_file(array $config, string $variant): ?string
{
    $name = da_variant_name($variant);
    if ($name === null) {
        return null;
    }
    $path = assets_dir($config) . '/da/' . $name . '.qc';
    return is_file($path) ? $path : null;
}

function da_upload_save(array $config, string $uploadedFilename, string $rawContent, string $customVariant = ''): string
{
    if ($rawContent === '') {
        throw new RuntimeException('Empty DA file content.');
    }

    $targetName = trim($customVariant) !== '' ? trim($customVariant) : trim($uploadedFilename);
    $variant    = da_variant_name($targetName);
    if ($variant === null) {
        throw new RuntimeException('Invalid DA filename. Supported types: .bin, .bin.qc, .mib, .mbn, .elf, .img, .auth');
    }

    $daDir = assets_dir($config) . '/da';
    if (!is_dir($daDir)) {
        @mkdir($daDir, 0775, true);
    }

    // Check if the uploaded file is already GZIP (1F 8B) or ZLIB (78 9C / 78 DA / 78 01) or uploaded as .qc
    $isAlreadyQc = str_ends_with(strtolower(trim($uploadedFilename)), '.qc');
    $b0 = ord($rawContent[0] ?? "\x00");
    $b1 = ord($rawContent[1] ?? "\x00");
    $hasCompressionHeader = ($b0 === 0x1F && $b1 === 0x8B)
        || ($b0 === 0x78 && ($b1 === 0x9C || $b1 === 0xDA || $b1 === 0x01 || $b1 === 0x5E));

    $bytesToSave = $rawContent;
    if (!$isAlreadyQc && !$hasCompressionHeader && function_exists('gzcompress')) {
        $compressed = @gzcompress($rawContent, 6);
        if (is_string($compressed) && $compressed !== '') {
            $bytesToSave = $compressed;
        }
    }

    $destPath = $daDir . '/' . $variant . '.qc';
    if (@file_put_contents($destPath, $bytesToSave, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write DA file to ' . $destPath);
    }

    return $variant;
}

function da_file_delete(array $config, string $variant, bool $deleteModels = false): bool
{
    $cleanVariant = da_variant_name($variant);
    if ($cleanVariant === null) {
        return false;
    }

    $path = assets_dir($config) . '/da/' . $cleanVariant . '.qc';
    $removedFile = false;
    if (is_file($path)) {
        $removedFile = @unlink($path);
    }

    if ($deleteModels) {
        $models = models_list($config);
        $filtered = array_values(array_filter($models, static fn(array $m): bool => strcasecmp($m['da_variant'], $cleanVariant) !== 0));
        models_save($config, $filtered);
    }

    return $removedFile || $deleteModels;
}

function parse_models_input(string $rawText): array
{
    $lines = preg_split('/[\r\n,;]+/', $rawText) ?: [];
    $result = [];
    $seen = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $key = strtolower($trimmed);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $trimmed;
        }
    }
    return $result;
}

function models_upsert_batch(
    array $config,
    string $daVariant,
    string $brand,
    array $modelNames,
    bool $isNew,
    string $operations = 'Format Data · Erase FRP · Unlock'
): int {
    $cleanVariant = da_variant_name($daVariant);
    if ($cleanVariant === null) {
        throw new RuntimeException('Invalid DA variant.');
    }

    $models = models_list($config);
    $indexByLower = [];
    foreach ($models as $idx => $m) {
        $indexByLower[strtolower($m['display'])] = $idx;
    }

    $ops = trim($operations) !== '' ? trim($operations) : 'Format Data · Erase FRP · Unlock';
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $count = 0;

    foreach ($modelNames as $rawName) {
        $display = trim((string)$rawName);
        if ($display === '') {
            continue;
        }
        $resolvedBrand = ($brand === '' || strtolower($brand) === 'auto')
            ? detect_brand_from_model($display)
            : normalize_brand_name($brand, $display);

        $key = strtolower($display);
        if (isset($indexByLower[$key])) {
            $i = $indexByLower[$key];
            $models[$i]['display']    = $display;
            $models[$i]['da_variant'] = $cleanVariant;
            $models[$i]['brand']      = $resolvedBrand;
            $models[$i]['is_new']     = $isNew;
            $models[$i]['operations'] = $ops;
            $models[$i]['updated_at'] = $now;
        } else {
            // Insert new models at the top of the list so they appear first
            array_unshift($models, [
                'display'    => $display,
                'da_variant' => $cleanVariant,
                'brand'      => $resolvedBrand,
                'is_new'     => $isNew,
                'operations' => $ops,
                'updated_at' => $now,
            ]);
            // Rebuild index map after unshift
            $indexByLower = [];
            foreach ($models as $idx => $m) {
                $indexByLower[strtolower($m['display'])] = $idx;
            }
        }
        $count++;
    }

    models_save($config, $models);
    return $count;
}

function model_update_single(
    array $config,
    string $oldDisplay,
    string $newDisplay,
    string $brand,
    string $daVariant,
    bool $isNew,
    string $operations = 'Format Data · Erase FRP · Unlock'
): bool {
    $cleanVariant = da_variant_name($daVariant);
    $newDisplay   = trim($newDisplay);
    if ($cleanVariant === null || $newDisplay === '') {
        return false;
    }

    $models  = models_list($config);
    $updated = false;
    foreach ($models as &$m) {
        if (strcasecmp($m['display'], trim($oldDisplay)) === 0) {
            $m['display']    = $newDisplay;
            $m['da_variant'] = $cleanVariant;
            $m['brand']      = ($brand === '' || strtolower($brand) === 'auto')
                ? detect_brand_from_model($newDisplay)
                : normalize_brand_name($brand, $newDisplay);
            $m['is_new']     = $isNew;
            $m['operations'] = trim($operations) !== '' ? trim($operations) : 'Format Data · Erase FRP · Unlock';
            $m['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $updated = true;
            break;
        }
    }
    unset($m);

    if ($updated) {
        return models_save($config, $models);
    }
    return false;
}

function model_toggle_new(array $config, string $display, ?bool $forceState = null): bool
{
    $models  = models_list($config);
    $changed = false;
    foreach ($models as &$m) {
        if (strcasecmp($m['display'], trim($display)) === 0) {
            $m['is_new']     = $forceState !== null ? $forceState : !$m['is_new'];
            $m['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $changed = true;
            break;
        }
    }
    unset($m);

    return $changed ? models_save($config, $models) : false;
}

function model_delete_single(array $config, string $display): bool
{
    $models = models_list($config);
    $before = count($models);
    $models = array_values(array_filter(
        $models,
        static fn(array $m): bool => strcasecmp($m['display'], trim($display)) !== 0
    ));
    if (count($models) === $before) {
        return false;
    }
    return models_save($config, $models);
}

function variant_set_new_flag(array $config, string $daVariant, bool $isNew, string $brand = ''): int
{
    $cleanVariant = da_variant_name($daVariant);
    if ($cleanVariant === null) {
        return 0;
    }
    $models = models_list($config);
    $count  = 0;
    foreach ($models as &$m) {
        if (strcasecmp($m['da_variant'], $cleanVariant) === 0) {
            $m['is_new'] = $isNew;
            if ($brand !== '' && strtolower($brand) !== 'auto') {
                $m['brand'] = normalize_brand_name($brand, $m['display']);
            }
            $m['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $count++;
        }
    }
    unset($m);

    if ($count > 0) {
        models_save($config, $models);
    }
    return $count;
}

function assets_synchronize(array $config): array
{
    $models = models_list($config);
    models_save($config, $models);
    $summary = assets_summary($config);
    return [
        'models_count'    => $summary['models_count'],
        'da_files_count'  => count($summary['da_files']),
        'new_count'       => $summary['new_models_count'],
        'synchronized_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ];
}

function assets_summary(array $config): array
{
    $models          = models_list($config);
    $meta            = models_meta($config);
    $variantsInUse   = [];
    $variantBrands   = [];
    $variantNewCount = [];
    $newModelsCount  = 0;

    foreach ($models as $m) {
        $v = (string)$m['da_variant'];
        $variantsInUse[$v] = ($variantsInUse[$v] ?? 0) + 1;
        $b = (string)($m['brand'] ?? 'Realme');
        $variantBrands[$v][$b] = ($variantBrands[$v][$b] ?? 0) + 1;
        if (!empty($m['is_new'])) {
            $variantNewCount[$v] = ($variantNewCount[$v] ?? 0) + 1;
            $newModelsCount++;
        }
    }

    $daFiles    = [];
    $totalBytes = 0;
    foreach (glob(assets_dir($config) . '/da/*.qc') ?: [] as $path) {
        $variant = basename($path, '.qc');
        $size    = (int)@filesize($path);
        $mtime   = (int)@filemtime($path);
        $ext     = strtolower((string)pathinfo($variant, PATHINFO_EXTENSION));
        $totalBytes += $size;
        $daFiles[$variant] = [
            'file'      => basename($path),
            'variant'   => $variant,
            'ext'       => $ext !== '' ? ('.' . $ext) : '.bin',
            'size'      => $size,
            'mtime'     => $mtime,
            'brands'    => array_keys($variantBrands[$variant] ?? []),
            'new_count' => (int)($variantNewCount[$variant] ?? 0),
        ];
    }
    ksort($daFiles);

    $missing = [];
    foreach (array_keys($variantsInUse) as $v) {
        if (!isset($daFiles[$v])) {
            $missing[] = $v;
        }
    }

    return [
        'models_count'     => count($models),
        'new_models_count' => $newModelsCount,
        'synchronized_at'  => $meta['synchronized_at'],
        'variants_in_use'  => $variantsInUse,
        'da_files'         => $daFiles,
        'missing'          => $missing,
        'total_bytes'      => $totalBytes,
    ];
}

#endregion

#region Signature Log

function sign_log_path(array $config): string
{
    return store_state($config) . '/signatures.jsonl';
}

function signature_record(array $config, array $data): void
{
    $user    = (string)($data['user_id'] ?? $data['user'] ?? '');
    $profile = (string)($data['profile'] ?? 'default');
    $purpose = (string)($data['purpose'] ?? 'brom_sla');
    $bytes   = (int)($data['bytes'] ?? $data['payload_bytes'] ?? 0);
    $sha256  = (string)($data['sha256'] ?? $data['digest'] ?? '');
    $sigHex  = (string)($data['signature'] ?? '');
    $ip      = client_ip();
    $now     = gmdate('Y-m-d H:i:s');

    $row = [
        'user'          => $user,
        'user_id'       => $user,
        'profile'       => $profile,
        'purpose'       => $purpose,
        'digest'        => $sha256,
        'sha256'        => $sha256,
        'payload_bytes' => $bytes,
        'bytes'         => $bytes,
        'signature'     => $sigHex,
        'ip'            => $ip,
        'created_at'    => $now,
        'ts'            => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $stmt = $conn->prepare(
                "INSERT INTO `signature_log` (`user`, purpose, digest, payload_bytes, signature, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssisss', $user, $purpose, $sha256, $bytes, $sigHex, $ip, $now);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        } catch (Throwable $ex) {
        }
    }

    @file_put_contents(
        sign_log_path($config),
        json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );

    app_service_log_record($config, $user, '-', 'SLA_SIGNED', "Signed $purpose ($bytes bytes)");
}

function signatures_recent(array $config, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));

    if (use_mysql($config)) {
        try {
            $conn   = db($config);
            $sql    = "SELECT `user`, purpose, digest, payload_bytes, signature, ip, created_at
                       FROM `signature_log` ORDER BY id DESC LIMIT {$limit}";
            $result = $conn->query($sql);
            $rows   = [];
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    'ts'            => (string)($r['created_at'] ?? ''),
                    'created_at'    => (string)($r['created_at'] ?? ''),
                    'user'          => (string)($r['user'] ?? ''),
                    'user_id'       => (string)($r['user'] ?? ''),
                    'profile'       => 'default',
                    'purpose'       => (string)($r['purpose'] ?? ''),
                    'sha256'        => (string)($r['digest'] ?? ''),
                    'digest'        => (string)($r['digest'] ?? ''),
                    'bytes'         => (int)($r['payload_bytes'] ?? 0),
                    'payload_bytes' => (int)($r['payload_bytes'] ?? 0),
                    'signature'     => (string)($r['signature'] ?? ''),
                    'ip'            => (string)($r['ip'] ?? ''),
                ];
            }
            $result->free();
            $conn->close();
            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $ex) {
        }
    }

    $path = sign_log_path($config);
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows  = [];
    foreach (array_reverse($lines) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) {
            $rows[] = [
                'ts'        => (string)($r['ts'] ?? $r['created_at'] ?? ''),
                'user_id'   => (string)($r['user_id'] ?? $r['user'] ?? ''),
                'profile'   => (string)($r['profile'] ?? 'default'),
                'purpose'   => (string)($r['purpose'] ?? ''),
                'sha256'    => (string)($r['sha256'] ?? $r['digest'] ?? ''),
                'bytes'     => (int)($r['bytes'] ?? $r['payload_bytes'] ?? 0),
                'signature' => (string)($r['signature'] ?? ''),
                'ip'        => (string)($r['ip'] ?? ''),
            ];
        }
        if (count($rows) >= $limit) {
            break;
        }
    }
    return $rows;
}

function signatures_clear(array $config): void
{
    if (use_mysql($config)) {
        try {
            $conn = db($config);
            $conn->query('TRUNCATE TABLE `signature_log`');
            $conn->close();
        } catch (Throwable $ex) {
        }
    }
    @unlink(sign_log_path($config));
}

#endregion
