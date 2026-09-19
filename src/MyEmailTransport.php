<?php

namespace Hariadi\MyEmail;

use Hariadi\MyEmail\Exceptions\MyEmailConfigurationException;
use Hariadi\MyEmail\Exceptions\MyEmailRequestException;
use Hariadi\MyEmail\Exceptions\MyEmailTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Delivers mail through the MYEmail HTTP API instead of SMTP.
 *
 * The service pins the sender mailbox platform-wide, so only the display name
 * travels with the request. Every call carries an Idempotency-Key derived from
 * the message ID, so a retried queue job replays the original submission
 * instead of sending a second copy.
 *
 * @see https://resend.miniapp.malaysia.gov.my/docs
 */
class MyEmailTransport extends AbstractTransport
{
    /**
     * Statuses the API uses for failures that will never succeed on retry.
     */
    private const PERMANENT_STATUSES = [400, 401, 403, 404, 409, 413, 422];

    public function __construct(
        private readonly string $endpoint,
        private readonly string $token,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'myemail';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new MyEmailConfigurationException('The MYEmail transport only supports Symfony Email messages.');
        }

        if (trim($this->endpoint) === '' || trim($this->token) === '') {
            throw new MyEmailConfigurationException('MYEMAIL_URL and MYEMAIL_TOKEN must be configured before sending email.');
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Idempotency-Key' => $this->idempotencyKey($message),
                ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->endpoint, $this->payload($email));
        } catch (ConnectionException $exception) {
            throw new MyEmailTransientException('The MYEmail API could not be reached.', 0, $exception);
        }

        if (! $response->successful()) {
            throw $this->failureFor($response);
        }

        $emailId = $response->json('id');

        if (is_string($emailId) && $emailId !== '') {
            $message->setMessageId($emailId);
        }
    }

    /**
     * Derive a stable key from the message ID so retries replay rather than
     * duplicate. The API caps the header at 200 characters.
     */
    private function idempotencyKey(SentMessage $message): string
    {
        return hash('sha256', $message->getMessageId());
    }

    private function failureFor(Response $response): MyEmailRequestException
    {
        $status = $response->status();
        $code = (string) ($response->json('error.code') ?? 'unknown');
        $detail = (string) ($response->json('error.message') ?? 'no message returned');

        $message = sprintf(
            'The MYEmail API rejected the email (HTTP %d, code %s): %s',
            $status,
            $code,
            $detail,
        );

        if (in_array($status, self::PERMANENT_STATUSES, true)) {
            return new MyEmailRequestException($message, $status, $code);
        }

        return new MyEmailTransientException($message, $status, $code);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email): array
    {
        $payload = [
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'subject' => $email->getSubject() ?? '',
            'attachments' => $this->attachments($email),
        ];

        $html = $this->bodyToString($email->getHtmlBody());
        $text = $this->bodyToString($email->getTextBody());

        if ($html !== null) {
            $payload['html'] = $html;
        }

        if ($text !== null) {
            $payload['text'] = $text;
        }

        $fromName = $email->getFrom()[0]?->getName();

        if ($fromName !== null && $fromName !== '') {
            $payload['fromName'] = $fromName;
        }

        $replyTo = $this->addresses($email->getReplyTo());

        if ($replyTo !== []) {
            $payload['headers'] = [
                'Reply-To' => implode(', ', $replyTo),
            ];
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== [] && $value !== null
        );
    }

    /**
     * Map Symfony data parts onto the API's base64 attachment objects.
     *
     * Inline parts fall back to the part name as the content ID, so that a
     * `cid:` reference written by `embed()` still resolves once the HTML body
     * is sent as-is rather than as assembled MIME.
     *
     * @return array<int, array<string, string>>
     */
    private function attachments(Email $email): array
    {
        return array_values(array_map(function (DataPart $part): array {
            $inline = $part->getDisposition() === 'inline';

            $attachment = [
                'filename' => $part->getFilename() ?? $part->getName() ?? 'attachment',
                'content' => base64_encode($part->getBody()),
                'contentType' => $part->getContentType(),
                'disposition' => $inline ? 'inline' : 'attachment',
            ];

            if ($inline) {
                $contentId = $part->hasContentId()
                    ? $part->getContentId()
                    : ($part->getName() ?? $part->getFilename());

                if ($contentId !== null && $contentId !== '') {
                    $attachment['contentId'] = $contentId;
                }
            }

            return $attachment;
        }, $email->getAttachments()));
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            static fn (Address $address): string => $address->getAddress(),
            $addresses
        );
    }

    /**
     * @param  resource|string|null  $body
     */
    private function bodyToString(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (! is_resource($body)) {
            return $body;
        }

        $contents = stream_get_contents($body);

        if ($contents === false) {
            throw new MyEmailConfigurationException('The email body could not be read.');
        }

        return $contents;
    }
}
