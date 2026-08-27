<?php

/**
 * Send baseline security headers for HTML/API responses.
 * Safe to call multiple times; skips if headers already sent.
 */
function send_security_headers($context = 'app') {
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Frame-Options: SAMEORIGIN');

    // CSP: allow self assets + small inline bootstrap (CSRF tokens, login helpers).
    // Share pages use an iframe to same-origin /s/* files.
    if ($context === 'api') {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    } elseif ($context === 'share') {
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'none'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "frame-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'"
        );
    } else {
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "frame-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'"
        );
    }

    if (function_exists('is_request_https') ? is_request_https() : (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    )) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Cache-busting query for local static assets.
 */
function asset_version($relativePath) {
    $full = __DIR__ . '/' . ltrim($relativePath, '/');
    if (!is_file($full)) {
        return '1';
    }
    return (string)filemtime($full);
}
