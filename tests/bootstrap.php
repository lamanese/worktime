<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap für WorkTime Tests
 *
 * Lädt die Nextcloud Autoloader und Test-Umgebung.
 */

// Nextcloud Server Root (per NC_ROOT überschreibbar; Docker-Dev nutzt /var/www/html)
$ncRoot = getenv('NC_ROOT') ?: '/var/www/html';

// Nextcloud Server-Umgebung laden. Stellt die echten OCP-Klassen (Entity,
// QBMapper, Http\Response …) bereit, die die Unit-Tests instanziieren.
require_once $ncRoot . '/lib/base.php';

// App-Namespace OCA\WorkTime autoloadbar machen (PSR-4 → lib/). Bewusst ohne
// internes App-Loader-API, damit ausschliesslich OCP/PSR-4 verwendet wird.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\WorkTime\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/../lib/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
