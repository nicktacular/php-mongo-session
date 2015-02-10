<?php

$me = dirname(__FILE__);
$root = dirname($me);

if (!file_exists($autoloader = $root . '/vendor/autoload.php')) {
    echo "Please run `composer install` first.\n";
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $autoloader */
$autoloader = require_once $autoloader;

