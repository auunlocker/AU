<?php
declare(strict_types=1);

/**
 * Root Legacy OTP Manager Endpoint (/OtpMng.php)
 */

require_once __DIR__ . '/otp/service.php';
handle_otp_service_request();
