<?php

namespace Hariadi\MyEmail\Tests;

use Hariadi\MyEmail\Exceptions\MyEmailConfigurationException;
use Hariadi\MyEmail\Exceptions\MyEmailRequestException;
use Hariadi\MyEmail\Exceptions\MyEmailTransientException;
use Hariadi\MyEmail\MyEmailTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;

class MyEmailTransportTest extends TestCase
{
    private function transport(): MyEmailTransport
    {
        return new MyEmailTransport(self::ENDPOINT, 'em_test_token');
    }

    private function email(): Email
    {
        return (new Email)
            ->from('MyFaSA <no-reply@malaysia.gov.my>')
            ->to('booker@example.com')
            ->cc('officer@example.com')
            ->bcc('audit@example.com')
            ->subject('Tempahan anda')
            ->text('Selamat Sejahtera!')
            ->html('<p>Selamat Sejahtera!</p>');
    }

    private function fakeAccepted(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'id' => 'a54e97b0-02f5-4c69-8ed6-5fabaa4cbb37',
                'status' => 'QUEUED',
                'createdAt' => '2026-09-18T15:47:42.932Z',
            ], 202),
        ]);
    }

    #[Test]
    public function it_maps_recipients_sender_name_and_bodies_to_the_api_payload(): void
    {
        $this->fakeAccepted();

        $this->transport()->send($this->email());

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer em_test_token')
                && $payload['to'] === ['booker@example.com']
                && $payload['cc'] === ['officer@example.com']
                && $payload['bcc'] === ['audit@example.com']
                && $payload['subject'] === 'Tempahan anda'
                && $payload['fromName'] === 'MyFaSA'
                && $payload['text'] === 'Selamat Sejahtera!'
                && $payload['html'] === '<p>Selamat Sejahtera!</p>'
                && ! array_key_exists('attachments', $payload);
        });
    }

    #[Test]
    public function it_forwards_reply_to_as_a_header(): void
    {
        $this->fakeAccepted();

        $email = $this->email()->replyTo('support@example.com');

        $this->transport()->send($email);

        Http::assertSent(function (Request $request): bool {
            return $request->data()['headers'] === ['Reply-To' => 'support@example.com'];
        });
    }

    #[Test]
    public function it_sends_a_stable_idempotency_key_per_message(): void
    {
        $this->fakeAccepted();

        $email = $this->email();
        $email->getHeaders()->addIdHeader('Message-ID', 'fixed-message-id@myfasa.test');

        $this->transport()->send($email);
        $this->transport()->send($email);

        $keys = [];

        Http::assertSent(function (Request $request) use (&$keys): bool {
            $keys[] = $request->header('Idempotency-Key')[0];

            return true;
        });

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame(hash('sha256', 'fixed-message-id@myfasa.test'), $keys[0]);
    }

    #[Test]
    public function it_encodes_regular_and_inline_attachments(): void
    {
        $this->fakeAccepted();

        $email = $this->email();
        $email->attach('invoice-bytes', 'invoice.pdf', 'application/pdf');
        $email->embed('logo-bytes', 'logo', 'image/png');

        $this->transport()->send($email);

        Http::assertSent(function (Request $request): bool {
            $attachments = $request->data()['attachments'];

            $invoice = $attachments[0];
            $logo = $attachments[1];

            return count($attachments) === 2
                && $invoice['filename'] === 'invoice.pdf'
                && $invoice['content'] === base64_encode('invoice-bytes')
                && $invoice['contentType'] === 'application/pdf'
                && $invoice['disposition'] === 'attachment'
                && ! array_key_exists('contentId', $invoice)
                && $logo['filename'] === 'logo'
                && $logo['content'] === base64_encode('logo-bytes')
                && $logo['contentType'] === 'image/png'
                && $logo['disposition'] === 'inline'
                && $logo['contentId'] === 'logo';
        });
    }

    #[Test]
    public function it_records_the_api_email_id_as_the_message_id(): void
    {
        $this->fakeAccepted();

        $sent = $this->transport()->send($this->email());

        $this->assertSame('a54e97b0-02f5-4c69-8ed6-5fabaa4cbb37', $sent->getMessageId());
    }

    #[Test]
    public function it_treats_an_idempotent_replay_as_success(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'id' => 'replayed-id',
                'status' => 'QUEUED',
                'createdAt' => '2026-09-18T15:47:42.932Z',
            ], 200, ['Idempotency-Replayed' => 'true']),
        ]);

        $sent = $this->transport()->send($this->email());

        $this->assertSame('replayed-id', $sent->getMessageId());
    }

    #[Test]
    public function it_raises_a_permanent_failure_for_a_validation_error(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => ['code' => 'validation_error', 'message' => 'html or text is required'],
            ], 422),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('Expected a permanent failure.');
        } catch (MyEmailRequestException $exception) {
            $this->assertNotInstanceOf(MyEmailTransientException::class, $exception);
            $this->assertSame(422, $exception->status());
            $this->assertSame('validation_error', $exception->errorCode());
            $this->assertStringContainsString('html or text is required', $exception->getMessage());
        }
    }

    #[Test]
    public function it_raises_a_permanent_failure_when_the_sender_mailbox_is_rejected(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => ['code' => 'invalid_from', 'message' => 'The sender address is fixed'],
            ], 422),
        ]);

        $this->expectException(MyEmailRequestException::class);

        $this->transport()->send($this->email());
    }

    #[Test]
    public function it_raises_a_transient_failure_for_a_quota_error(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => ['code' => 'quota_exceeded', 'message' => 'Monthly quota reached'],
            ], 429),
        ]);

        $this->expectException(MyEmailTransientException::class);

        $this->transport()->send($this->email());
    }

    #[Test]
    public function it_raises_a_transient_failure_for_a_server_error(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500),
        ]);

        $this->expectException(MyEmailTransientException::class);

        $this->transport()->send($this->email());
    }

    #[Test]
    public function it_raises_a_transient_failure_when_the_api_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->expectException(MyEmailTransientException::class);

        $this->transport()->send($this->email());
    }

    #[Test]
    public function it_fails_when_the_url_or_token_is_missing(): void
    {
        Http::fake();

        $this->expectException(MyEmailConfigurationException::class);
        $this->expectExceptionMessage('MYEMAIL_URL and MYEMAIL_TOKEN must be configured');

        (new MyEmailTransport('', ''))->send($this->email());
    }
}
