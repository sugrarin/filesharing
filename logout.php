<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/auth.php';
send_security_headers('app');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

require_valid_csrf_token('form', 'logout_admin');
logout();
header('Location: login.php');
exit;
