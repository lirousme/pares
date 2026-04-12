<?php

declare(strict_types=1);

function startLongSession(): void
{
    $lifetime = 60 * 60 * 24 * 365 * 10; // 10 anos

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.gc_maxlifetime', (string) $lifetime);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}
