<?php

$loader = require getenv('DOCKRAY_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
// Override an optimized consumer classmap when testing with its dependencies.
$source = realpath(__DIR__.'/../src');
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
    if ($file->getExtension() === 'php') {
        $relative = substr($file->getPathname(), strlen($source) + 1, -4);
        $classes['Dock\\Ray\\Laravel\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative)] = $file->getPathname();
    }
}
$loader->addClassMap($classes);
