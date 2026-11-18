<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra — OTP Verification & Expiration Service (/otp/service.php)
 *
 * Handles communication with the Let's Fire desktop client (net.cpp / net.rs):
 *   1. Step 2 — OTP Validation (`ServiceEncryptedOtpV4`):
 *      Validates `api_token`, checks server kill-switch and IP rate limits,
 *      verifies OTP state (`valid`, `used`, `expired`, `inactive`, `invalid`)
 *      against MySQL (`otp` table in schema.sql) or file-store fallback,
 *      records the app service start in `app_service_logs`, and returns a
 *      Bearer access token for `/api/v1/sign` hardware SLA calls.
 *   2. Step 8 — OTP Expiration (`ServiceEncryptedDeletOtp`):
 *      Atomically marks the OTP as `used` (`used = 1, status = 'used'`) after
 *      the phone service completes and records the completed operation, model,
 *      and chip in `app_service_logs`.
 */

require_once dirname(__DIR__) . '/store.php';
require_once dirname(__DIR__) . '/api/tokens.php';

function otp_service_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function otp_service_reply(int $httpStatus, string $verdict, bool $ok): void
{
    $verdict = OTP_VERDICTS[$verdict] ?? OTP_VERDICTS['invalid'];
    otp_service_json($httpStatus, [
        'success' => $ok,
        'status'  => $ok ? 'valid' : 'error',
        'message' => $verdict,
    ]);
}

function otp_service_parse_input(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($_GET, $_POST, $decoded);
        }
        $form = [];
        parse_str($raw, $form);
        if (is_array($form) && !empty($form)) {
            return array_merge($_GET, $_POST, $form);
        }
    }
    if (!empty($_POST)) {
        return array_merge($_GET, $_POST);
    }
    return $_GET;
}

function otp_service_context_summary(array $body): string
{
    $parts = [];
    if (!empty($body['operation']) && is_string($body['operation'])) {
        $parts[] = 'Op: ' . trim($body['operation']);
    }
    if (!empty($body['model']) && is_string($body['model'])) {
        $parts[] = 'Model: ' . trim($body['model']);
    }
    if (!empty($body['chip']) && is_string($body['chip'])) {
        $parts[] = 'Chip: ' . trim($body['chip']);
    }
    return implode(' · ', $parts);
}

function handle_otp_service_request(?array $config = null): void
{
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($method !== 'POST' && $method !== 'GET') {
        otp_service_reply(405, 'invalid', false);
    }

    if (!signing_enabled($config)) {
        $ip = client_ip();
        otp_log($config, $ip, '-', 'SERVER_OFF');
        otp_service_reply(503, 'offline', false);
    }

    $ip = client_ip();
    [$total, $rejected] = throttle_read($config, $ip);
    if ($total >= MAX_TOTAL || $rejected >= MAX_REJECTED) {
        otp_log($config, $ip, '-', 'THROTTLED');
        otp_service_reply(429, 'throttled', false);
    }

    $body = otp_service_parse_input();
    $ctxSummary = otp_service_context_summary($body);

    // Check if this is Step 8: OTP expiration / burn request (ServiceEncryptedDeletOtp)
    $deleteOtp = '';
    foreach (['ServiceEncryptedDeletOtp', 'delete_otp', 'expire_otp'] as $k) {
        if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') {
            $deleteOtp = trim($body[$k]);
            break;
        }
    }

    if ($deleteOtp !== '') {
        $len = strlen($deleteOtp);
        if ($len < MIN_OTP_LEN || $len > MAX_OTP_LEN || preg_match('/[\x00-\x1f\x7f]/', $deleteOtp)) {
            throttle_record($config, $ip, true);
            otp_log($config, $ip, $deleteOtp, 'EXPIRE_INVALID_FORMAT');
            otp_service_reply(200, 'invalid', false);
        }

        try {
            $entry = otp_consume($config, $deleteOtp);
        } catch (Throwable $e) {
            otp_log($config, $ip, $deleteOtp, 'DB_ERROR ' . $e->getMessage());
            otp_service_reply(503, 'offline', false);
        }

        if ($entry !== null) {
            $owner = (string)($entry['user_id'] ?? $entry['user'] ?? 'user');
            throttle_record($config, $ip, false);
            $logLine = 'CONSUMED_STEP8 user=' . $owner . ($ctxSummary !== '' ? " ($ctxSummary)" : '');
            otp_log($config, $ip, $deleteOtp, $logLine);

            $details = 'Step 8: Service completed & OTP consumed';
            if ($ctxSummary !== '') {
                $details .= ' — ' . $ctxSummary;
            }
            app_service_log_record($config, $owner, $deleteOtp, 'APP_OTP_CONSUMED', $details);

            otp_service_json(200, [
                'success' => true,
                'status'  => 'used',
                'message' => 'OTP expired',
            ]);
        }

        $state = otp_classify($config, $deleteOtp);
        throttle_record($config, $ip, false);
        otp_log($config, $ip, $deleteOtp, 'EXPIRE_ALREADY_' . strtoupper($state));
        otp_service_json(200, [
            'success' => ($state === 'used'),
            'status'  => $state,
            'message' => OTP_VERDICTS[$state] ?? OTP_VERDICTS['invalid'],
        ]);
    }

    // Step 2: OTP verification request (ServiceEncryptedOtpV4 / otp / code)
    $otp = '';
    foreach (['ServiceEncryptedOtpV4', 'otp', 'code', 'password'] as $k) {
        if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') {
            $otp = trim($body[$k]);
            break;
        }
    }

    $len = strlen($otp);
    if ($len < MIN_OTP_LEN || $len > MAX_OTP_LEN || preg_match('/[\x00-\x1f\x7f]/', $otp)) {
        throttle_record($config, $ip, true);
        otp_log($config, $ip, $otp, 'INVALID_FORMAT');
        usleep(random_int(80000, 180000));
        otp_service_reply(200, 'invalid', false);
    }

    try {
        $state = otp_classify($config, $otp);
    } catch (Throwable $e) {
        otp_log($config, $ip, $otp, 'DB_ERROR ' . $e->getMessage());
        otp_service_reply(503, 'offline', false);
    }

    if ($state !== 'valid') {
        throttle_record($config, $ip, true);
        otp_log($config, $ip, $otp, strtoupper($state));
        usleep(random_int(80000, 180000));
        otp_service_reply(200, $state, false);
    }

    if (!empty($config['otp_consume_on_verify'])) {
        $entry = otp_consume($config, $otp);
        if ($entry === null) {
            throttle_record($config, $ip, true);
            otp_log($config, $ip, $otp, 'RACE_LOST');
            otp_service_reply(200, 'used', false);
        }
    } else {
        $entry = otp_find($config, $otp);
    }

    throttle_record($config, $ip, false);
    $userId  = (string)($entry['user_id'] ?? $entry['user'] ?? 'user');
    $profile = (string)($entry['profile'] ?? 'default');
    $logLine = "VERIFIED user=$userId profile=$profile" . ($ctxSummary !== '' ? " ($ctxSummary)" : '');
    otp_log($config, $ip, $otp, $logLine);

    $details = "Step 2: OTP validated by Let's Fire App (profile: $profile)";
    if ($ctxSummary !== '') {
        $details .= ' — ' . $ctxSummary;
    }
    app_service_log_record($config, $userId, $otp, 'APP_OTP_VERIFIED', $details);

    // Issue Bearer token pair so the app can also call /api/v1/can-sign and /api/v1/sign
    $tokens = token_pair($config, $userId, $profile);

    otp_service_json(200, array_merge([
        'success' => true,
        'status'  => 'valid',
        'message' => OTP_VERDICTS['valid'],
        'profile' => $profile,
        'user'    => $userId,
    ], $tokens));
}
