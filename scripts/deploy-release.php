<?php

declare(strict_types=1);

use CutiDeployment\ReleasePackage;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/ReleasePackage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function downloadReleaseFile(string $url, string $path, int $maximum): void
{
    $file = fopen($path, 'wb');
    if ($file === false) {
        throw new RuntimeException('Cannot open deployment download.');
    }
    $curl = curl_init($url);
    $bytes = 0;
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'BNI-Cuti-AutoDeploy/1',
        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($file, $maximum, &$bytes): int {
            $bytes += strlen($chunk);
            if ($bytes > $maximum) {
                return 0;
            }

            return fwrite($file, $chunk) ?: 0;
        },
    ]);
    try {
        $success = curl_exec($curl);
        if ($success === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException('Release download failed (HTTP '.curl_getinfo($curl, CURLINFO_RESPONSE_CODE).').');
        }
    } finally {
        curl_close($curl);
        fclose($file);
    }
}

function backupBeforeMigrations(PDO $connection, string $path): void
{
    $file = fopen($path, 'xb');
    if ($file === false) {
        throw new RuntimeException('Cannot create pre-migration database backup.');
    }
    chmod($path, 0600);
    $quoteTable = fn (string $table): string => '`'.str_replace('`', '``', $table).'`';
    try {
        $connection->beginTransaction();
        fwrite($file, "SET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $connection->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $name = $quoteTable($table);
            $schema = $connection->query('SHOW CREATE TABLE '.$name)->fetch(PDO::FETCH_NUM);
            fwrite($file, 'DROP TABLE IF EXISTS '.$name.";\n".$schema[1].";\n");
            $rows = $connection->query('SELECT * FROM '.$name);
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map($quoteTable, array_keys($row)));
                $values = implode(',', array_map(fn ($value): string => $value === null ? 'NULL' : $connection->quote((string) $value), array_values($row)));
                if (fwrite($file, 'INSERT INTO '.$name.' ('.$columns.') VALUES ('.$values.");\n") === false) {
                    throw new RuntimeException('Database backup could not be written.');
                }
            }
        }
        fwrite($file, "SET FOREIGN_KEY_CHECKS=1;\n");
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    } finally {
        fclose($file);
    }
}

$configuration = json_decode(file_get_contents($argv[1] ?? __DIR__.'/config.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
$base = rtrim($configuration['base'], '/');
$repository = $configuration['repository'];
if (! preg_match('~^[\w.-]+/[\w.-]+$~', $repository) || realpath($base) === false) {
    throw new RuntimeException('Invalid deployment configuration.');
}
if (($argv[2] ?? '') === '--initialize') {
    $initial = $configuration['initial'] ?? '';
    if (! is_file($initial.'/public/index.php') || ! is_file($initial.'/vendor/autoload.php')) {
        throw new RuntimeException('The initial application is not ready.');
    }
    if (! is_link($base.'/current') && ! file_exists($base.'/current') && ! symlink($initial, $base.'/current')) {
        throw new RuntimeException('This hosting does not permit deployment symlinks.');
    }
    echo '['.gmdate(DATE_ATOM).'] Deployment initialized; cURL='.(extension_loaded('curl') ? 'yes' : 'no').'; zip='.(extension_loaded('zip') ? 'yes' : 'no').PHP_EOL;
    exit;
}
$lock = fopen($base.'/deployment.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}
$commit = null;
$installing = false;
try {
    downloadReleaseFile('https://github.com/'.$repository.'/releases/latest/download/release.json', $base.'/release.download.json', 16000);
    $manifest = json_decode(file_get_contents($base.'/release.download.json'), true, 512, JSON_THROW_ON_ERROR);
    $commit = $manifest['commit'] ?? '';
    if (($manifest['format'] ?? null) !== 1 || ! preg_match('/^[a-f0-9]{40}$/', $commit) || ! preg_match('/^[a-f0-9]{64}$/', $manifest['sha256'] ?? '') || ($manifest['url'] ?? '') !== 'https://github.com/'.$repository.'/releases/download/deploy-'.$commit.'/bni-cuti-release.zip') {
        throw new RuntimeException('Invalid release manifest.');
    }
    $state = is_file($base.'/state.json') ? json_decode(file_get_contents($base.'/state.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    $failed = is_file($base.'/failed.json') ? json_decode(file_get_contents($base.'/failed.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    if (($state['commit'] ?? null) === $commit || ($failed['commit'] ?? null) === $commit) {
        exit;
    }
    $archive = $base.'/release.download.zip';
    downloadReleaseFile($manifest['url'], $archive, 100_000_000);
    if (! hash_equals($manifest['sha256'], hash_file('sha256', $archive))) {
        throw new RuntimeException('Release checksum mismatch.');
    }
    $installing = true;
    ReleasePackage::install($archive, $base, $configuration['environment'], $configuration['storage'], $commit,
        function (string $release) use ($base, $commit): void {
            require $release.'/vendor/autoload.php';
            $app = require $release.'/bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
            $migrator = $app->make('migrator');
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $pending = array_diff(array_keys($migrator->getMigrationFiles($release.'/database/migrations')), $ran);
            if ($pending !== []) {
                if (! is_dir($base.'/backups')) {
                    mkdir($base.'/backups', 0700);
                }
                backupBeforeMigrations($app->make('db')->connection()->getPdo(), $base.'/backups/'.gmdate('Ymd-His').'-'.$commit.'.sql');
            }
            foreach (['package:discover', 'migrate', 'config:cache', 'route:cache', 'view:cache'] as $command) {
                $arguments = ['--no-interaction' => true];
                if ($command === 'migrate') {
                    $arguments['--force'] = true;
                }
                if ($kernel->call($command, $arguments) !== 0) {
                    throw new RuntimeException('Release preparation failed at '.$command.'.');
                }
            }
        },
        function (string $expected) use ($configuration, $base): void {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $url = rtrim($configuration['url'], '/');
                    downloadReleaseFile($url.'/deployment.json?release='.$expected, $base.'/version.download.json', 16000);
                    $version = json_decode(file_get_contents($base.'/version.download.json'), true, 512, JSON_THROW_ON_ERROR);
                    downloadReleaseFile($url.'/api/health?release='.$expected, $base.'/health.download.json', 16000);
                    $health = json_decode(file_get_contents($base.'/health.download.json'), true, 512, JSON_THROW_ON_ERROR);
                    if (($version['commit'] ?? '') === $expected && ($health['ok'] ?? false) === true) {
                        return;
                    }
                } catch (Throwable) {
                    // LiteSpeed may still have the old symlink target cached immediately after activation.
                }
                usleep(1_000_000);
            }
            throw new RuntimeException('The published version did not pass its health check.');
        });
    echo '['.gmdate(DATE_ATOM).'] Deployed '.$commit.PHP_EOL;
} catch (Throwable $error) {
    if ($installing && $commit !== null) {
        ReleasePackage::writeJson($base.'/failed.json', ['commit' => $commit, 'failed_at' => gmdate(DATE_ATOM), 'message' => $error->getMessage()]);
    }
    fwrite(STDERR, '['.gmdate(DATE_ATOM).'] Deployment failed: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
