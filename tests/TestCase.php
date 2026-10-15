<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $driver = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$driver.'.database');
        if ($driver !== 'sqlite' && ! str_ends_with((string) $database, '_test')) {
            throw new \LogicException('Database pengujian harus memakai nama berakhiran _test.');
        }

        return $app;
    }
}
