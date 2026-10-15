<?php

declare(strict_types=1);

use CutiDeployment\ReleasePackage;

require __DIR__.'/ReleasePackage.php';

$commit = $argv[1] ?? '';
$repository = getenv('GITHUB_REPOSITORY') ?: 'Rex4Red/project-magang-BNI-SistemCuti-laravel';
if (! preg_match('~^[\w.-]+/[\w.-]+$~', $repository)) {
    throw new RuntimeException('Invalid repository.');
}
$destination = dirname(__DIR__).'/.runtime/release';
$archive = ReleasePackage::build(dirname(__DIR__), $destination, $commit);
ReleasePackage::writeJson($destination.'/release.json', [
    'format' => 1,
    'commit' => $commit,
    'sha256' => hash_file('sha256', $archive),
    'url' => 'https://github.com/'.$repository.'/releases/download/deploy-'.$commit.'/bni-cuti-release.zip',
]);
echo 'Release packaged: '.$commit.PHP_EOL;
