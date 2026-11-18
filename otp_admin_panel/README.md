# OTP Admin Panel — Let's Fire / Penumbra

Extracted from the original server package.

## Structure

```
php_server/
├── admin/                 ← ADMIN PANEL (login required)
│   ├── login.php
│   ├── otps.php           ← Create / Revoke / Delete OTP codes
│   ├── users.php
│   ├── profiles.php
│   ├── assets.php
│   ├── signatures.php
│   └── ...
├── otp/                   ← OTP verification API endpoints
│   ├── otp_eu.php
│   ├── service.php
│   └── ...
├── tools/
│   ├── mint_otp.php       ← CLI: generate OTP from command line
│   └── hash_password.php
├── user/                  ← User dashboard (credits, logs)
├── restaurar-admin/       ← Password restore helper
├── config.php             ← Settings (CHANGE DEFAULT PASSWORD!)
├── schema.sql             ← MySQL schema
├── store.php              ← OTP storage layer
└── index.php
```

## Default Admin Login (from config.php)

```
URL:      /admin/login.php
User:     admin
Password: multitool@
```

**Change this immediately in production.**

## Create OTP (Admin Panel)

1. Login → **OTPs** page (`/admin/otps.php`)
2. Create new code (random or custom)
3. Set minutes / profile / note

## Create OTP from CLI

```bash
cd php_server
php tools/mint_otp.php                  # random 10-char, 10 min
php tools/mint_otp.php MYCODE 3600      # fixed code, 1 hour
php tools/mint_otp.php MYCODE 600 admin # code, TTL, profile
```

## Requirements

- PHP 8.0+
- MySQL / MariaDB (see `schema.sql`)
- Or file-based fallback (see `store.php` / `config.php`)

## Security note

`config.php` contains the default HMAC secret and admin password.
Rotate both before exposing the panel to the internet.
