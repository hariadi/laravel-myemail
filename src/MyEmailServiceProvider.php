<?php

namespace GovTech\MyEmail;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class MyEmailServiceProvider extends ServiceProvider
{
    /**
     * The mailer name registered automatically so that consuming applications
     * only need MAIL_MAILER=myemail, without editing config/mail.php.
     */
    public const MAILER = 'myemail';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/myemail.php', 'myemail');

        $config = $this->app['config'];

        if ($config->get('mail.mailers.'.self::MAILER) === null) {
            $config->set('mail.mailers.'.self::MAILER, ['transport' => self::MAILER]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/myemail.php' => $this->app->configPath('myemail.php'),
            ], 'myemail-config');
        }

        Mail::extend(self::MAILER, function (array $config): MyEmailTransport {
            return new MyEmailTransport(
                (string) ($config['endpoint'] ?? $this->config('endpoint', '')),
                (string) ($config['token'] ?? $this->config('token', '')),
                (int) ($config['connect_timeout'] ?? $this->config('connect_timeout', 5)),
                (int) ($config['timeout'] ?? $this->config('timeout', 15)),
            );
        });
    }

    /**
     * Read a package config value, allowing a per-mailer override in
     * config/mail.php to take precedence when present.
     */
    private function config(string $key, mixed $default): mixed
    {
        return $this->app['config']->get('myemail.'.$key, $default);
    }
}
