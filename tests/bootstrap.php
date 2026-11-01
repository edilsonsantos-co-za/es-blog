<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    $env = dirname(__DIR__).'/.env';
    (new Dotenv())->bootEnv(is_file($env) ? $env : $env.'.example');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
