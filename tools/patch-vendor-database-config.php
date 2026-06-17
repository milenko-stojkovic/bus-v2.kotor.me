<?php

/**
 * Patches vendor/laravel/framework/config/database.php on PHP 8.3 hosts where
 * Pdo\Mysql is referenced but the class is unavailable. Runs before
 * artisan package:discover during composer install.
 */

$path = __DIR__.'/../vendor/laravel/framework/config/database.php';

if (! is_file($path)) {
    exit(0);
}

$contents = file_get_contents($path);

if ($contents === false || ! preg_match('/Pdo\\\\?Mysql/i', $contents)) {
    exit(0);
}

$replacement = <<<'PHP'
'options' => (extension_loaded('pdo_mysql') && env('MYSQL_ATTR_SSL_CA'))
                ? [1009 => env('MYSQL_ATTR_SSL_CA')]
                : [],
PHP;

$patterns = [
    // Laravel 12.52
    "/'options'\\s*=>\\s*extension_loaded\\('pdo_mysql'\\)\\s*\\?\\s*array_filter\\(\\[\\s*\\n\\s*\\(PHP_VERSION_ID[^\\]]+\\]\\)\\s*:\\s*\\[\\],/m",
    // defined() / constant() variants
    "/'options'\\s*=>\\s*extension_loaded\\('pdo_mysql'\\)\\s*\\?\\s*array_filter\\(\\[\\s*\\n\\s*\\([^\\]]+Pdo\\\\Mysql[^\\]]+\\]\\)\\s*:\\s*\\[\\],/m",
    // Laravel 12.62+ (use Mysql::ATTR_SSL_CA with import)
    "/'options'\\s*=>\\s*extension_loaded\\('pdo_mysql'\\)\\s*\\?\\s*array_filter\\(\\[\\s*\\n\\s*Mysql::ATTR_SSL_CA\\s*=>\\s*env\\('MYSQL_ATTR_SSL_CA'\\),\\s*\\n\\s*\\]\\)\\s*:\\s*\\[\\],/m",
];

$patched = $contents;

foreach ($patterns as $pattern) {
    $result = preg_replace($pattern, $replacement, $patched);
    if (is_string($result)) {
        $patched = $result;
    }
}

if ($patched === $contents) {
    // Fallback: replace any options block that still mentions Pdo/Mysql in the mysql/mariadb area
    $patched = preg_replace(
        "/('options'\\s*=>\\s*)(?:extension_loaded\\('pdo_mysql'\\)[^,]+,)/m",
        '$1(extension_loaded(\'pdo_mysql\') && env(\'MYSQL_ATTR_SSL_CA\'))
                ? [1009 => env(\'MYSQL_ATTR_SSL_CA\')]
                : [],',
        $patched,
        -1,
        $count
    );

    if ($count === 0) {
        fwrite(STDERR, "patch-vendor-database-config: could not patch {$path}\n");
        exit(0);
    }
}

if ($patched !== $contents) {
    file_put_contents($path, $patched);
    fwrite(STDOUT, "patch-vendor-database-config: patched {$path}\n");
}

exit(0);
