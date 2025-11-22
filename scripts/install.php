<?php

$packageRoot = realpath(__DIR__ . '/..');
$appRoot = realpath(__DIR__ . '/../../../../..'); // from data/upload/extensions/<id>/scripts to project root
$logFile = $appRoot ? $appRoot . '/data/logs/prtg_install.log' : null;

$log = function (string $message) use ($logFile): void {
    if (!$logFile) {
        return;
    }
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
};

if (!$packageRoot || !$appRoot) {
    $log('ERROR: Cannot resolve paths. packageRoot=' . var_export($packageRoot, true) . ' appRoot=' . var_export($appRoot, true));
    throw new RuntimeException('Cannot resolve paths to install package.');
}

$paths = [
    $packageRoot . '/files/custom' => $appRoot . '/custom',
    $packageRoot . '/files/client' => $appRoot . '/client',
];

foreach ($paths as $src => $dst) {
    if (!is_dir($src)) {
        $log("Skip missing source path: $src");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $targetPath = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                if (@mkdir($targetPath, 0775, true)) {
                    $log("mkdir $targetPath");
                }
            }
        } else {
            if (!is_dir(dirname($targetPath))) {
                if (@mkdir(dirname($targetPath), 0775, true)) {
                    $log("mkdir " . dirname($targetPath));
                }
            }
            if (@copy($item->getPathname(), $targetPath)) {
                $log("copy {$item->getPathname()} -> $targetPath");
            } else {
                $log("ERROR copy {$item->getPathname()} -> $targetPath");
            }
        }
    }
}

$log('Install script finished.');
