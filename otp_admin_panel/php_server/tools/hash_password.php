<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025-2026 Shomy
 *
 * Prints a bcrypt hash for the admin panel password.
 *
 *   php tools/hash_password.php 'new password'
 *
 * Paste the output into config.php -> admin.password_hash.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = (string) ($argv[1] ?? '');
if ($password === '') {
    fwrite(STDERR, "usage: php tools/hash_password.php 'new password'\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), "\n";
echo "Paste this into config.php -> admin.password_hash\n";
