<?php

$appRoot = realpath(__DIR__ . '/../../../../..'); // from data/upload/extensions/<id>/scripts to project root
$logFile = $appRoot ? $appRoot . '/data/logs/prtg_install.log' : null;

$log = function (string $message) use ($logFile): void {
    if (!$logFile) {
        return;
    }
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
};

if (!$appRoot) {
    $log('ERROR: Cannot resolve appRoot for uninstall.');
    return;
}

$paths = [
    $appRoot . '/custom/Espo/Modules/PrtgIntegration',
    $appRoot . '/client/custom/src/views/prtg'
];

$deleteDir = function ($path) use (&$deleteDir, $log): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $deleteDir($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};

foreach ($paths as $path) {
    if (file_exists($path)) {
        $log("Removing $path");
        $deleteDir($path);
    }
}

$log('Uninstall script finished.');
