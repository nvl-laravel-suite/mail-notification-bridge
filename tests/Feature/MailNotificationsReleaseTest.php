<?php

declare(strict_types=1);

use Composer\Semver\Semver;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

test('bridge installations exclude affected direct Symfony dependencies', function (string $dependency, string $version, bool $accepted): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(Semver::satisfies($version, $manifest['require'][$dependency]))->toBe($accepted);
})->with([
    'Foundation previous Symfony 7 floor' => ['symfony/http-foundation', '7.4.19', false],
    'Foundation last affected Symfony 7 patch' => ['symfony/http-foundation', '7.4.22', false],
    'Foundation patched Symfony 7 floor' => ['symfony/http-foundation', '7.4.23', true],
    'Foundation later Symfony 7 patch' => ['symfony/http-foundation', '7.4.24', true],
    'Foundation previous Symfony 8 floor' => ['symfony/http-foundation', '8.1.7', false],
    'Foundation last affected Symfony 8 patch' => ['symfony/http-foundation', '8.1.10', false],
    'Foundation patched Symfony 8 floor' => ['symfony/http-foundation', '8.1.11', true],
    'Foundation later Symfony 8 minor' => ['symfony/http-foundation', '8.2.0', true],
    'Mime original Symfony 8 floor' => ['symfony/mime', '8.0.0', false],
    'Mime last affected patch' => ['symfony/mime', '8.1.9', false],
    'Mime patched floor' => ['symfony/mime', '8.1.10', true],
    'Mime later patch' => ['symfony/mime', '8.1.11', true],
    'Mime earlier Symfony 7 minor' => ['symfony/mime', '7.3.9', false],
    'Mime last affected Symfony 7 patch' => ['symfony/mime', '7.4.21', false],
    'Mime patched Symfony 7 floor' => ['symfony/mime', '7.4.22', true],
    'Mailer last affected Symfony 7 patch' => ['symfony/mailer', '7.4.11', false],
    'Mailer patched Symfony 7 floor' => ['symfony/mailer', '7.4.12', true],
    'Mailer Symfony 8' => ['symfony/mailer', '8.0.12', true],
]);

function releaseFixture(): string
{
    $root = sys_get_temp_dir().'/mail-notifications-release-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/package');
    foreach (['src', 'config', 'routes', 'database', 'resources', 'docs'] as $directory) {
        $files->copyDirectory(dirname(__DIR__, 2).'/'.$directory, $root.'/package/'.$directory);
    }
    foreach (['LICENSE', 'README.md', 'CHANGELOG.md', 'UPGRADING.md', 'SECURITY.md', 'CONTRIBUTING.md', 'composer.json', 'build-release.php'] as $file) {
        $files->copy(dirname(__DIR__, 2).'/'.$file, $root.'/package/'.$file);
    }

    return $root;
}

function buildRelease(string $root, string $version = '0.1.0', string $destination = 'release', string $timezone = 'UTC'): Process
{
    $process = new Process([PHP_BINARY, '-d', 'date.timezone='.$timezone, $root.'/package/build-release.php', $version, $root.'/'.$destination]);
    $process->run();

    return $process;
}

test('release archives contain only runtime files with a versioned Composer manifest and verifiable checksums', function () {
    $root = releaseFixture();
    $files = new Filesystem;
    foreach (['.env', 'auth.json', '.git/config', 'vendor/autoload.php', 'tests/Pest.php', 'phpunit.xml.dist', 'qa/screenshot.png', 'src/debug.log'] as $path) {
        $files->ensureDirectoryExists(dirname($root.'/package/'.$path));
        $files->put($root.'/package/'.$path, 'private-release-fixture');
    }

    try {
        $process = buildRelease($root);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $archive = $root.'/release/nvl-mail-notification-bridge-0.1.0.zip';
        $zip = new ZipArchive;
        expect($zip->open($archive))->toBeTrue();
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = $zip->getNameIndex($index);
        }
        $expected = ['LICENSE', 'README.md', 'CHANGELOG.md', 'UPGRADING.md', 'SECURITY.md', 'CONTRIBUTING.md', 'composer.json'];
        foreach (['src', 'config', 'routes', 'database', 'resources', 'docs'] as $directory) {
            foreach ($files->allFiles($root.'/package/'.$directory) as $file) {
                if (in_array($file->getExtension(), ['php', 'json', 'md', 'yaml', 'yml'], true)) {
                    $expected[] = substr($file->getPathname(), strlen($root.'/package/'));
                }
            }
        }
        sort($expected);
        expect($entries)->toBe($expected);
        $package = json_decode($zip->getFromName('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($package['version'])->toBe('0.1.0');
        expect($package['extra']['laravel']['providers'])->toContain('Nvl\\MailNotificationBridge\\Providers\\MailNotificationsServiceProvider');
        $sourcePackage = json_decode($files->get($root.'/package/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($sourcePackage)->not->toHaveKey('version');

        $manifestPath = $root.'/release/nvl-mail-notification-bridge-0.1.0.manifest.json';
        $manifest = json_decode($files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['status'])->toBe('local-release-candidate');
        expect($manifest['archive']['sha256'])->toBe(hash_file('sha256', $archive));
        expect(array_keys($manifest['files']))->toBe($entries);
        foreach ($manifest['files'] as $path => $metadata) {
            expect($metadata['sha256'])->toBe(hash('sha256', $zip->getFromName($path)));
            expect($metadata['bytes'])->toBe(strlen($zip->getFromName($path)));
        }
        $zip->close();
        expect($files->get($root.'/release/nvl-mail-notification-bridge-0.1.0.sha256'))
            ->toContain(hash_file('sha256', $archive).'  '.basename($archive))
            ->toContain(hash_file('sha256', $manifestPath).'  '.basename($manifestPath));
    } finally {
        $files->deleteDirectory($root);
    }
});

test('release bytes are reproducible across source timestamps and local timezones', function () {
    $root = releaseFixture();
    $files = new Filesystem;

    try {
        $first = buildRelease($root, destination: 'first', timezone: 'Pacific/Auckland');
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());
        foreach ($files->allFiles($root.'/package') as $file) {
            touch($file->getPathname(), 946684800);
        }

        $second = buildRelease($root, destination: 'second', timezone: 'America/New_York');

        expect($second->getExitCode())->toBe(0, $second->getErrorOutput().$second->getOutput());
        foreach ($files->files($root.'/first') as $file) {
            expect($files->get($root.'/second/'.$file->getFilename()))->toBe($files->get($file->getPathname()));
        }
    } finally {
        $files->deleteDirectory($root);
    }
});

test('release tooling rejects invalid versions before creating output', function (string $version) {
    $root = releaseFixture();

    try {
        $process = buildRelease($root, $version);

        expect($process->getExitCode())->toBe(1);
        expect($process->getErrorOutput())->toContain('Expected a stable semantic version');
        expect(is_dir($root.'/release'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with(['dev-main', '0.1', '../0.1.0']);

test('release tooling rejects source directories linked outside the package', function (string $directory) {
    $root = releaseFixture();
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/outside');
    $files->put($root.'/outside/mail-notifications.php', '<?php return ["private" => true];');
    $files->deleteDirectory($root.'/package/'.$directory);
    symlink($root.'/outside', $root.'/package/'.$directory);

    try {
        $process = buildRelease($root);

        expect($process->getExitCode())->toBe(1);
        expect($process->getErrorOutput())->toContain('Release source must not contain symbolic links');
        expect(is_dir($root.'/release'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
})->with(['src', 'config', 'resources', 'docs']);

test('release tooling waits for the destination lock before checking or writing release outputs', function () {
    $root = releaseFixture();
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/release');
    $lock = fopen($root.'/release/.mail-notifications-release.lock', 'c');
    expect(flock($lock, LOCK_EX))->toBeTrue();
    $process = new Process([PHP_BINARY, $root.'/package/build-release.php', '0.1.0', $root.'/release']);
    $process->setTimeout(10);

    try {
        $process->start();
        $reachedLock = $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'Acquiring release output lock.'));

        expect($reachedLock)->toBeTrue();
        expect($process->isRunning())->toBeTrue();
        expect($files->files($root.'/release'))->toBeEmpty();
        expect(flock($lock, LOCK_UN))->toBeTrue();
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $basename = 'nvl-mail-notification-bridge-0.1.0';
        $manifest = json_decode($files->get($root.'/release/'.$basename.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['archive']['sha256'])->toBe(hash_file('sha256', $root.'/release/'.$basename.'.zip'));
        expect($files->get($root.'/release/'.$basename.'.sha256'))
            ->toContain($manifest['archive']['sha256'].'  '.$basename.'.zip')
            ->toContain(hash_file('sha256', $root.'/release/'.$basename.'.manifest.json').'  '.$basename.'.manifest.json');
        expect($files->files($root.'/release'))->toHaveCount(3);
        expect(fileinode($root.'/release/.mail-notifications-release.lock'))->toBe(fstat($lock)['ino']);
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if ($process->isRunning()) {
            $process->stop(0);
        }
        $files->deleteDirectory($root);
    }
});

test('concurrent builds with different source bytes preserve one complete immutable release', function () {
    $root = releaseFixture();
    $files = new Filesystem;
    $files->copyDirectory($root.'/package', $root.'/other-package');
    $files->append($root.'/other-package/README.md', "\nDifferent candidate.\n");
    $files->ensureDirectoryExists($root.'/release');
    $lock = fopen($root.'/release/.mail-notifications-release.lock', 'c');
    expect(flock($lock, LOCK_EX))->toBeTrue();
    $processes = [];

    try {
        foreach (['package', 'other-package'] as $packageDirectory) {
            $process = new Process([PHP_BINARY, $root.'/'.$packageDirectory.'/build-release.php', '0.1.0', $root.'/release']);
            $process->setTimeout(10);
            $process->start();
            $processes[$packageDirectory] = $process;
            expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'Acquiring release output lock.')))->toBeTrue();
            expect($process->isRunning())->toBeTrue();
        }
        expect($files->files($root.'/release'))->toBeEmpty();
        flock($lock, LOCK_UN);
        foreach ($processes as $process) {
            $process->wait();
        }

        $exitCodes = array_map(fn (Process $process): ?int => $process->getExitCode(), $processes);
        $sortedExitCodes = array_values($exitCodes);
        sort($sortedExitCodes);
        expect($sortedExitCodes)->toBe([0, 1]);
        $winner = array_search(0, $exitCodes, true);
        $loser = array_search(1, $exitCodes, true);
        expect($processes[$loser]->getErrorOutput())->toContain('Refusing to replace an existing release');
        $basename = 'nvl-mail-notification-bridge-0.1.0';
        $archive = $root.'/release/'.$basename.'.zip';
        $manifestPath = $root.'/release/'.$basename.'.manifest.json';
        $manifest = json_decode($files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['archive']['sha256'])->toBe(hash_file('sha256', $archive));
        $zip = new ZipArchive;
        expect($zip->open($archive))->toBeTrue();
        expect($zip->getFromName('README.md'))->toBe($files->get($root.'/'.$winner.'/README.md'));
        expect($manifest['files']['README.md']['sha256'])->toBe(hash('sha256', $zip->getFromName('README.md')));
        $zip->close();
        expect($files->get($root.'/release/'.$basename.'.sha256'))
            ->toContain(hash_file('sha256', $archive).'  '.$basename.'.zip')
            ->toContain(hash_file('sha256', $manifestPath).'  '.$basename.'.manifest.json');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        $files->deleteDirectory($root);
    }
});

test('release tooling refuses to replace a version with changed source bytes', function () {
    $root = releaseFixture();
    $files = new Filesystem;

    try {
        $first = buildRelease($root);
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());
        $archive = $root.'/release/nvl-mail-notification-bridge-0.1.0.zip';
        $originalHash = hash_file('sha256', $archive);
        $files->append($root.'/package/README.md', "\nChanged release contents.\n");

        $second = buildRelease($root);

        expect($second->getExitCode())->toBe(1);
        expect($second->getErrorOutput())->toContain('Refusing to replace an existing release');
        expect(hash_file('sha256', $archive))->toBe($originalHash);
        expect($files->files($root.'/release', hidden: true))->toHaveCount(4);
    } finally {
        $files->deleteDirectory($root);
    }
});
