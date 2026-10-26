<?php
declare(strict_types=1);

// PSR-4 автозагрузка без Composer: ShortsFactory\Foo => src/Foo.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'ShortsFactory\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
