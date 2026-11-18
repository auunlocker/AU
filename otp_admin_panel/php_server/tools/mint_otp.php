<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025-2026 Shomy
 *
 * CLI helper that issues an OTP the auth server will accept.
 *
 *   php tools/mint_otp.php                 # random 10-character code, 10 min
 *   php tools/mint_otp.php CODE            # fixed code
 *   php tools/mint_otp.php CODE 3600 admin # code, TTL seconds, profile
 *
 * Uses the same storage and rules as admin.php, so a code minted here shows up
 * in the panel immediately. A production issuer should generate codes with
 * random_int() and deliver them out of band.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../store.php';

$config = require __DIR__ . '/../config.php';

$otp = $argv[1] ?? strtoupper(bin2hex(random_bytes(5)));
$ttl = (int) ($argv[2] ?? 600);
$user = (string) ($argv[3] ?? $config['default_user'] ?? 'otp');

if (preg_match('/^[!-~]{4,64}$/D', $otp) !== 1) {
    fwrite(STDERR, "code must be 4-64 printable characters, no spaces\n");
    exit(1);
}

$profiles = effective_profiles($config);
if (!isset($profiles[$user])) {
    fwrite(STDERR, "unknown profile '{$user}'; known: " . implode(', ', array_keys($profiles)) . "\n");
    exit(1);
}

if (otp_find($config, $otp) !== null) {
    fwrite(STDERR, "code '{$otp}' already exists\n");
    exit(1);
}

otp_insert($config, $otp, max(1, $ttl), $user);

$expiry = date('Y-m-d H:i:s', time() + max(1, $ttl));
echo "OTP {$otp} for '{$user}', expires {$expiry}\n";
