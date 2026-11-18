<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025-2026 Shomy
 *
 * Settings for the Penumbra SLA & Let's Fire v2.0.1 auth server.
 *
 * Authentication is OTP-only: a code is verified by POST /otp/otp_eu.php
 * (ServiceEncryptedOtpV4) and burned on completion (ServiceEncryptedDeletOtp),
 * or verified and burned atomically by POST /api/auth/otp to hand out bearer
 * tokens that unlock /api/v1/sign. The verification rules mirror otp.php
 * (status/used/expired_at, single atomic consume, per-IP throttle).
 */

return [
    // Signs the access/refresh tokens; rotating it invalidates every session.
    'hmac_secret' => 'bf207a6821b97c76264d508f501c401a7d60dd17b1585bf33aeaa51b411c6f82',

    'access_ttl'  => 3600,        // seconds an access token stays valid
    'refresh_ttl' => 2592000,     // 30 days

    /*
     * Login for admin panel (/admin/login.php).
     * Stored in MySQL table `admins` (schema.sql) with fallback to config.php:
     *   User:     admin
     *   Password: multitool@
     */
    'admin' => [
        'user'          => 'admin',
        'password'      => 'multitool@',
        'password_hash' => 'multitool@',
    ],

    /*
     * Where logs, the throttle counters and settings.json live.
     * Empty = ./state. In production point this outside the document root,
     * for example '/home/USER/otp_state' on cPanel.
     */
    'state_dir' => '',

    /*
     * DA images and the model list the tool downloads. Empty = ./assets.
     * Point it outside the document root if the host serves dotfiles badly;
     * the API is the only thing that should hand these files out.
     */
    'assets_dir' => '',

    /*
     * Optional shared token. It ships inside the client binary, so it only
     * rejects junk traffic; the OTP row is the real credential. Leave it as
     * the empty string to accept both empty and the built-in Let's Fire token.
     */
    'api_token'     => '',
    'app_api_token' => '4386f3c199f950f1b59ee195332e986bf5f05b6333444d05',

    /*
     * OTP storage (matches schema.sql).
     *
     * Fill in db.user/db.pass/db.name to use the MySQL table otp.php uses.
     * If MySQL is unreachable locally, store.php automatically falls back to
     * state/otps.json so local testing works seamlessly.
     */
    'db' => [
        'host'  => 'localhost',
        'user'  => 'u879807497_multi_tool',
        'pass'  => '875425107@Tool',
        'name'  => 'u879807497_multi_tool',
        'table' => 'otp',
    ],

    // Used when the OTP row has no profile of its own.
    'default_user' => 'default',

    /*
     * Permissions granted for a verified OTP. The `user` column of the OTP row
     * selects one of the profiles below; unknown users get 'default'.
     */
    'profiles' => [
        'default' => [
            'is_admin'    => false,
            'permissions' => ['brom_sla', 'pl_sla', 'meta_sla', 'da_sla'],
        ],
        'admin' => [
            'is_admin'    => true,
            'permissions' => ['brom_sla', 'pl_sla', 'meta_sla', 'da_sla'],
        ],
    ],

    // RSA key used to answer SLA challenges. Its modulus must match the
    // pubk_mod the tool sends, otherwise can-sign answers false.
    'private_key' => __DIR__ . '/keys/sla_private.pem',
];
