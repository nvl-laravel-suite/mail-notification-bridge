<?php

declare(strict_types=1);

$temporaryArchive = null;
$outputLock = null;
$exitCode = 0;

try {
    $version = $argv[1] ?? '';
    $destination = $argv[2] ?? '';
    if ($argc !== 3 || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1 || $destination === '') {
        throw new InvalidArgumentException('Expected a stable semantic version (for example 0.1.0). Usage: php build-release.php <version> <output-directory>');
    }
    if (! extension_loaded('zip')) {
        throw new RuntimeException('Building a release requires the PHP zip extension.');
    }

    date_default_timezone_set('UTC');
    $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    $sourceComposer = file_get_contents(__DIR__.'/composer.json');
    $package = json_decode($sourceComposer, true, flags: JSON_THROW_ON_ERROR);
    if (isset($package['version'])) {
        throw new RuntimeException('Keep the source composer.json versionless; the archive receives its version during this build.');
    }
    $package['version'] = $version;

    foreach (['src', 'config', 'routes', 'database', 'database/migrations', 'database/migrations/webhooks', 'resources', 'docs'] as $directory) {
        if (is_link(__DIR__.'/'.$directory)) {
            throw new RuntimeException('Release source must not contain symbolic links.');
        }
    }
    $paths = ['LICENSE', 'README.md', 'CHANGELOG.md', 'UPGRADING.md', 'SECURITY.md', 'CONTRIBUTING.md', 'composer.json'];
    foreach (['src', 'config', 'routes', 'database/migrations', 'resources', 'docs'] as $directory) {
        $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($sourceFiles as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Release source must not contain symbolic links.');
            }
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'json', 'md', 'yaml', 'yml'], true)) {
                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(__DIR__) + 1));
            }
        }
    }
    sort($paths, SORT_STRING);

    $contents = [];
    foreach ($paths as $path) {
        if (is_link(__DIR__.'/'.$path)) {
            throw new RuntimeException('Release source must not contain symbolic links.');
        }
        $contents[$path] = $path === 'composer.json' ? json_encode($package, $jsonFlags)."\n" : file_get_contents(__DIR__.'/'.$path);
        if ($contents[$path] === false) {
            throw new RuntimeException('Cannot read release source: '.$path);
        }
    }

    if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
        throw new RuntimeException('Cannot create the release output directory.');
    }
    $temporaryArchive = tempnam($destination, '.mail-notifications-release-');
    if ($temporaryArchive === false) {
        throw new RuntimeException('Cannot create the temporary archive.');
    }
    $zip = new ZipArchive;
    if ($zip->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open the release archive.');
    }

    $fileManifest = [];
    foreach ($contents as $path => $bytes) {
        if (! $zip->addFromString($path, $bytes)
            || ! $zip->setCompressionName($path, ZipArchive::CM_STORE)
            || ! $zip->setMtimeName($path, 315532800)
            || ! $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
            throw new RuntimeException('Cannot add release source: '.$path);
        }
        $fileManifest[$path] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
    if (! $zip->close()) {
        throw new RuntimeException('Cannot finish the release archive.');
    }

    $basename = 'nvl-mail-notification-bridge-'.$version;
    $archiveHash = hash_file('sha256', $temporaryArchive);
    $manifest = json_encode([
        'name' => $package['name'],
        'version' => $version,
        'status' => 'local-release-candidate',
        'published' => false,
        'source_composer_sha256' => hash('sha256', $sourceComposer),
        'builder_sha256' => hash_file('sha256', __FILE__),
        'archive' => [
            'file' => $basename.'.zip',
            'bytes' => filesize($temporaryArchive),
            'sha256' => $archiveHash,
            'compression' => 'store',
            'entry_timestamp' => '1980-01-01T00:00:00Z',
        ],
        'files' => $fileManifest,
    ], $jsonFlags)."\n";
    $checksums = $archiveHash.'  '.$basename.".zip\n".hash('sha256', $manifest).'  '.$basename.".manifest.json\n";
    $outputs = [
        $basename.'.zip' => file_get_contents($temporaryArchive),
        $basename.'.manifest.json' => $manifest,
        $basename.'.sha256' => $checksums,
    ];

    $outputLock = fopen($destination.'/.mail-notifications-release.lock', 'c');
    if ($outputLock === false) {
        throw new RuntimeException('Cannot open the release output lock.');
    }
    fwrite(STDOUT, "Acquiring release output lock.\n");
    if (! flock($outputLock, LOCK_EX)) {
        throw new RuntimeException('Cannot acquire the release output lock.');
    }

    foreach ($outputs as $filename => $bytes) {
        if (file_exists($destination.'/'.$filename) && file_get_contents($destination.'/'.$filename) !== $bytes) {
            throw new RuntimeException('Refusing to replace an existing release with changed bytes: '.$filename);
        }
    }
    foreach ($outputs as $filename => $bytes) {
        if (file_put_contents($destination.'/'.$filename, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write release output: '.$filename);
        }
    }

    fwrite(STDOUT, $destination.'/'.$basename.'.zip'."\nSHA256: ".$archiveHash."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    $exitCode = 1;
} finally {
    if (is_resource($outputLock)) {
        flock($outputLock, LOCK_UN);
        fclose($outputLock);
    }
    if (is_string($temporaryArchive) && is_file($temporaryArchive)) {
        unlink($temporaryArchive);
    }
}

exit($exitCode);
