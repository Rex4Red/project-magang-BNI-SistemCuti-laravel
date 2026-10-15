<?php

namespace Tests\Unit;

use CutiDeployment\ReleasePackage;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

require_once dirname(__DIR__, 2).'/scripts/ReleasePackage.php';

class DeploymentPackageTest extends TestCase
{
    private string $root;

    private string $commit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cuti-deploy-test-'.bin2hex(random_bytes(6));
        $this->commit = str_repeat('a', 40);
        foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'database/data', 'public/build', 'resources', 'routes', 'vendor', 'storage/logs'] as $path) {
            mkdir($this->root.'/'.$path, 0755, true);
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'vendor/autoload.php', 'bootstrap/app.php', 'public/index.php', 'public/build/index.html'] as $file) {
            file_put_contents($this->root.'/'.$file, '{}');
        }
        file_put_contents($this->root.'/.env', 'PRIVATE_DATABASE_PASSWORD');
        file_put_contents($this->root.'/database/private.sqlite', 'PRIVATE_EMPLOYEE_DATA');
        file_put_contents($this->root.'/bootstrap/cache/config.php', 'PRIVATE_CACHED_PASSWORD');
        file_put_contents($this->root.'/storage/logs/laravel.log', 'PRIVATE_LOG_DATA');
        copy(dirname(__DIR__, 2).'/database/data/cuti-defaults.json', $this->root.'/database/data/cuti-defaults.json');
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function archive(): string
    {
        return ReleasePackage::build($this->root, $this->root.'/package', $this->commit);
    }

    public function test_package_excludes_private_environment_data_and_cached_configuration(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->archive());
        foreach (['.env', 'storage/logs/laravel.log', 'database/private.sqlite', 'bootstrap/cache/config.php'] as $private) {
            $this->assertFalse($zip->locateName($private));
        }
        $this->assertSame($this->commit, json_decode($zip->getFromName('public/deployment.json'), true)['commit']);
        $this->assertIsArray(json_decode($zip->getFromName('database/data/cuti-defaults.json'), true)['calendar']);
        $zip->getExternalAttributesName('vendor/', $system, $attributes);
        $this->assertSame(0755, ($attributes >> 16) & 0777);
        $zip->close();
    }

    public function test_extraction_rejects_path_traversal_before_writing_files(): void
    {
        $archive = $this->archive();
        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString('../outside.php', 'bad');
        $zip->close();
        $this->expectExceptionMessage('Unsafe release archive entry.');
        ReleasePackage::extract($archive, $this->root.'/extracted', $this->commit);
    }

    public function test_extraction_rejects_symlinks_and_incomplete_packages(): void
    {
        $archive = $this->archive();
        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString('app/link', '/etc/passwd');
        $zip->setExternalAttributesName('app/link', ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16);
        $zip->close();
        $this->expectExceptionMessage('Unsafe release archive entry.');
        ReleasePackage::extract($archive, $this->root.'/extracted', $this->commit);
    }

    public function test_extraction_rejects_missing_dependencies_and_commit_mismatch(): void
    {
        $archive = $this->archive();
        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->deleteName('vendor/autoload.php');
        $zip->close();
        $this->expectExceptionMessage('Incomplete release: vendor/autoload.php');
        ReleasePackage::extract($archive, $this->root.'/extracted', $this->commit);
    }

    public function test_extraction_requires_the_expected_commit(): void
    {
        $this->expectExceptionMessage('Release commit does not match its manifest.');
        ReleasePackage::extract($this->archive(), $this->root.'/extracted', str_repeat('b', 40));
    }

    public function test_build_rejects_a_missing_frontend_before_a_release_can_be_published(): void
    {
        unlink($this->root.'/public/build/index.html');
        $this->expectExceptionMessage('Incomplete release: public/build/index.html');
        $this->archive();
    }

    private function initializeCurrent(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Atomic POSIX symlink replacement is tested on the Linux CI runner.');
        }
        $base = $this->root.'/deployment';
        mkdir($base);
        symlink($this->root, $base.'/current');

        return $base;
    }

    public function test_activation_preserves_environment_and_storage(): void
    {
        $base = $this->initializeCurrent();
        ReleasePackage::install($this->archive(), $base, $this->root.'/.env', $this->root.'/storage', $this->commit,
            function (string $release): void {
                $this->assertSame('PRIVATE_DATABASE_PASSWORD', file_get_contents($release.'/.env'));
                $this->assertSame(realpath($this->root.'/storage'), realpath($release.'/storage'));
                $this->assertSame(realpath($this->root), realpath($this->root.'/deployment/current'));
            },
            function (string $commit): void {
                $this->assertSame($this->commit, $commit);
                $this->assertNotSame(realpath($this->root), realpath($this->root.'/deployment/current'));
            });
        $this->assertSame('PRIVATE_LOG_DATA', file_get_contents($base.'/current/storage/logs/laravel.log'));
        $this->assertSame($this->commit, json_decode(file_get_contents($base.'/state.json'), true)['commit']);
    }

    public function test_failed_health_check_restores_the_previous_release(): void
    {
        $base = $this->initializeCurrent();
        try {
            ReleasePackage::install($this->archive(), $base, $this->root.'/.env', $this->root.'/storage', $this->commit,
                function (string $release): void {},
                function (string $commit): void {
                    throw new RuntimeException('Simulated HTTP 500.');
                });
            $this->fail('A broken release must not remain active.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('previous application restored', $error->getMessage());
        }
        $this->assertSame(realpath($this->root), realpath($base.'/current'));
        $this->assertFileDoesNotExist($base.'/state.json');
    }
}
