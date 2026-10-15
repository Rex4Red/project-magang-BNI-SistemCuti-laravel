<?php

declare(strict_types=1);

namespace CutiDeployment;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ReleasePackage
{
    private const DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'];

    private const FILES = ['artisan', 'composer.json', 'composer.lock'];

    public static function allowed(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }
        $parts = explode('/', rtrim($path, '/'));
        if (array_intersect($parts, ['', '.', '..', '.git']) !== []) {
            return false;
        }
        foreach ($parts as $part) {
            if (str_starts_with($part, '.env')) {
                return false;
            }
        }
        if ($parts[0] === 'database' && count($parts) > 1 && ! in_array($parts[1], ['migrations', 'seeders', 'factories', '.gitignore'], true)) {
            return false;
        }
        if (str_starts_with($path, 'bootstrap/cache/')) {
            return $path === 'bootstrap/cache/';
        }

        return in_array($parts[0], self::DIRECTORIES, true) || (count($parts) === 1 && in_array($path, self::FILES, true));
    }

    public static function build(string $root, string $destination, string $commit): string
    {
        self::commit($commit);
        self::directory($destination);
        $target = $destination.'/bni-cuti-release.zip';
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create release archive.');
        }
        foreach (self::DIRECTORIES as $directory) {
            $zip->addEmptyDir($directory);
            $zip->setExternalAttributesName($directory.'/', ZipArchive::OPSYS_UNIX, (0040000 | 0755) << 16);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                $path = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                if (! self::allowed($path.($item->isDir() ? '/' : ''))) {
                    continue;
                }
                if ($item->isLink()) {
                    throw new RuntimeException('Symlinks must not be packaged.');
                }
                if ($item->isDir()) {
                    $zip->addEmptyDir($path);
                    $zip->setExternalAttributesName($path.'/', ZipArchive::OPSYS_UNIX, (0040000 | 0755) << 16);
                } else {
                    $zip->addFile($item->getPathname(), $path);
                    $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, (0100000 | 0644) << 16);
                }
            }
        }
        foreach (self::FILES as $file) {
            $zip->addFile($root.'/'.$file, $file);
            $zip->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, (0100000 | 0644) << 16);
        }
        $zip->addFromString('public/deployment.json', json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)."\n");
        $zip->setExternalAttributesName('public/deployment.json', ZipArchive::OPSYS_UNIX, (0100000 | 0644) << 16);
        if (! $zip->close()) {
            throw new RuntimeException('Cannot finish release archive.');
        }

        return $target;
    }

    public static function extract(string $archive, string $destination, string $commit): void
    {
        self::commit($commit);
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Invalid release archive.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 20000) {
                throw new RuntimeException('Release contains too many files.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $total += $entry['size'];
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (! self::allowed($entry['name']) || (($attributes >> 16) & 0170000) === 0120000 || $total > 300_000_000) {
                    throw new RuntimeException('Unsafe release archive entry.');
                }
            }
            foreach (['vendor/autoload.php', 'bootstrap/app.php', 'artisan', 'composer.lock', 'public/index.php', 'public/build/manifest.json'] as $required) {
                if ($zip->locateName($required) === false) {
                    throw new RuntimeException('Incomplete release: '.$required);
                }
            }
            $version = json_decode($zip->getFromName('public/deployment.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (($version['commit'] ?? null) !== $commit) {
                throw new RuntimeException('Release commit does not match its manifest.');
            }
            self::directory($destination);
            if (! $zip->extractTo($destination)) {
                throw new RuntimeException('Cannot extract release.');
            }
        } finally {
            $zip->close();
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            chmod($item->getPathname(), $item->isDir() ? 0755 : 0644);
        }
    }

    public static function install(string $archive, string $base, string $environment, string $storage, string $commit, Closure $prepare, Closure $health): void
    {
        self::commit($commit);
        if (! is_file($environment) || ! is_dir($storage)) {
            throw new RuntimeException('Shared environment or storage is missing.');
        }
        self::directory($base.'/releases');
        $release = $base.'/releases/'.$commit.'-'.bin2hex(random_bytes(4));
        self::extract($archive, $release, $commit);
        if (! symlink($environment, $release.'/.env') || ! symlink($storage, $release.'/storage')) {
            throw new RuntimeException('Cannot link shared application data.');
        }
        self::directory($release.'/bootstrap/cache');
        $prepare($release);
        $previous = realpath($base.'/current');
        if ($previous === false || ! is_link($base.'/current')) {
            throw new RuntimeException('Current release must be initialized as a symlink.');
        }
        self::switch($base, $release);
        try {
            $health($commit);
        } catch (Throwable $error) {
            self::switch($base, $previous);
            throw new RuntimeException('Health check failed; previous application restored.', 0, $error);
        }
        self::writeJson($base.'/state.json', ['commit' => $commit, 'current' => $release, 'previous' => $previous, 'deployed_at' => gmdate(DATE_ATOM)]);
        self::prune($base, [$release, $previous]);
    }

    private static function switch(string $base, string $target): void
    {
        $next = $base.'/.current-'.bin2hex(random_bytes(4));
        if (! symlink($target, $next) || ! rename($next, $base.'/current')) {
            throw new RuntimeException('Cannot activate release.');
        }
        clearstatcache(true, $base.'/current');
    }

    private static function prune(string $base, array $keep): void
    {
        $root = realpath($base.'/releases');
        $releases = glob($base.'/releases/*', GLOB_ONLYDIR) ?: [];
        usort($releases, fn (string $first, string $second): int => filemtime($second) <=> filemtime($first));
        $keep = array_merge($keep, array_slice($releases, 0, 3));
        foreach ($releases as $release) {
            if (is_link($release) || in_array($release, $keep, true) || ! preg_match('/^[a-f0-9]{40}-[a-f0-9]{8}$/', basename($release))) {
                continue;
            }
            $resolved = realpath($release);
            if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Cleanup target escapes the releases directory.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($resolved);
        }
    }

    public static function writeJson(string $path, array $data): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n") === false) {
            throw new RuntimeException('Cannot write deployment state.');
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Cannot persist deployment state.');
        }
    }

    private static function commit(string $commit): void
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $commit)) {
            throw new RuntimeException('Invalid release commit.');
        }
    }

    private static function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create release directory.');
        }
    }
}
