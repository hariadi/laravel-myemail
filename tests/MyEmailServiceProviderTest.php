<?php

namespace GovTech\MyEmail\Tests;

use GovTech\MyEmail\MyEmailTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class MyEmailServiceProviderTest extends TestCase
{
    #[Test]
    public function it_registers_the_mailer_without_touching_config_mail(): void
    {
        $this->assertSame(
            ['transport' => 'myemail'],
            config('mail.mailers.myemail')
        );
    }

    #[Test]
    public function it_resolves_the_mailer_to_the_package_transport(): void
    {
        $transport = app('mail.manager')->mailer('myemail')->getSymfonyTransport();

        $this->assertInstanceOf(MyEmailTransport::class, $transport);
        $this->assertSame('myemail', (string) $transport);
    }

    #[Test]
    public function it_can_be_selected_as_the_default_mailer(): void
    {
        config()->set('mail.default', 'myemail');

        $this->assertInstanceOf(
            MyEmailTransport::class,
            app('mail.manager')->mailer()->getSymfonyTransport()
        );
    }

    #[Test]
    public function a_per_mailer_override_in_config_mail_wins_over_package_config(): void
    {
        config()->set('mail.mailers.myemail', [
            'transport' => 'myemail',
            'endpoint' => 'https://override.test/api/v1/emails',
            'token' => 'em_live_override',
        ]);

        Http::fake([
            '*' => Http::response(['id' => 'override-id', 'status' => 'QUEUED', 'createdAt' => 'now'], 202),
        ]);

        app('mail.manager')->mailer('myemail')->raw('body', function ($message): void {
            $message->to('booker@example.com')->subject('Override');
        });

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://override.test/api/v1/emails'
                && $request->hasHeader('Authorization', 'Bearer em_live_override');
        });
    }

    #[Test]
    public function it_sends_through_the_package_config_when_no_override_is_present(): void
    {
        Http::fake([
            '*' => Http::response(['id' => 'default-id', 'status' => 'QUEUED', 'createdAt' => 'now'], 202),
        ]);

        app('mail.manager')->mailer('myemail')->raw('body', function ($message): void {
            $message->to('booker@example.com')->subject('Default');
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT);
    }
}
