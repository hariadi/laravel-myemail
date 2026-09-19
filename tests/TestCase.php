<?php

namespace Hariadi\MyEmail\Tests;

use Hariadi\MyEmail\MyEmailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const ENDPOINT = 'https://email.test/api/v1/emails';

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MyEmailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('myemail.endpoint', self::ENDPOINT);
        $app['config']->set('myemail.token', 'em_test_token');
    }
}
