<?php
/** PWA manifest (dynamic so the app name follows app_brand()). */
require_once __DIR__ . '/includes/app_config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$name = app_brand();
echo json_encode([
    'id' => './',
    'name' => $name,
    'short_name' => mb_strlen($name) > 12 ? 'Krishna' : $name,
    'description' => 'Attendance, salary and staff management',
    'lang' => 'gu',
    'start_url' => './login.php?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'portrait',
    'background_color' => '#0b1d4a',
    'theme_color' => '#0b1d4a',
    'categories' => ['business', 'productivity'],
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Dashboard', 'url' => './login.php?source=pwa', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Upad & Jama', 'url' => './admin/admin_advance.php', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => "Today's Attendance", 'url' => './admin/admin_live_tracking.php', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
