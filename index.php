<?php
declare(strict_types=1);

// Web entry point. Every request is rewritten here (.htaccess for Apache,
// web.config for IIS) and routed by QuickDRY to pages/<path>/<name>.php.
// The homepage is the dashboard in pages/home.

use QuickDRY\Web\Web;

// PHP's built-in server (php -S localhost:8000 index.php): let it serve
// static files itself, and nothing else directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (preg_match('/\.(js|css|png|gif|jpg|ico|svg|woff2?|ttf|eot)$/i', $path) && is_file(__DIR__ . $path)) {
        return false;
    }
}

require_once 'modules.php';

// Session cookie: not readable by JavaScript, not sent on cross-site POSTs, HTTPS-only over HTTPS
// (X-Forwarded-Proto covers a TLS-terminating proxy or tunnel in front of the site).
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name(SESSION_NAME);
session_start();

global $web;

$web = new Web();

$web->Init(
    '/home',
    '/home',
    __DIR__
);

// The dashboard is read-only and has no sign-in, so anyone who can reach the
// site can see it. Put it behind your own access control (Cloudflare Access,
// IIS authentication, a VPN) before exposing it to the internet.

$web->Exec();
