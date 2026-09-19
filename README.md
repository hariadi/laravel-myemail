# Laravel MYEmail

Laravel mail transport for the MYEmail government email delivery service. Sends over the HTTP API — no SMTP host, port, or credentials.

Drop-in: your existing `Mailable`s, `Notification`s, and `Mail::` calls keep working unchanged. Only the transport underneath changes.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require hariadi/laravel-myemail
```

The service provider is auto-discovered and registers a `myemail` mailer for you, so there is nothing to add to `config/mail.php`.

## Configuration

Set four variables:

```dotenv
MAIL_MAILER=myemail
MYEMAIL_URL=https://resend.miniapp.malaysia.gov.my/api/v1/emails
MYEMAIL_TOKEN=your-api-key

# Must match the mailbox the MYEmail service is configured to send from.
MAIL_FROM_ADDRESS=no-reply@malaysia.gov.my
MAIL_FROM_NAME="Your Agency"
```

Optional tuning, with defaults shown:

```dotenv
MYEMAIL_CONNECT_TIMEOUT=5
MYEMAIL_TIMEOUT=15
```

Create the API key in the MYEmail dashboard under **API keys**. The secret is shown once — put it in your secret store, never in version control.

To publish the config file:

```bash
php artisan vendor:publish --tag=myemail-config
```

### The sender address is fixed

This is the single most common integration failure. The service pins the sender mailbox platform-wide. Only the display name is yours to choose.

`MAIL_FROM_ADDRESS` must exactly equal the service's configured `SMTP_FROM_ADDRESS`. Anything else is rejected with `422 invalid_from`, every time. `MAIL_FROM_NAME` is free-form and becomes the `fromName` on the request.

## Usage

Nothing package-specific. Send mail the way you already do:

```php
Mail::to($user)->send(new BookingConfirmed($booking));

$user->notify(new BookingMade($booking));

Notification::route('mail', 'someone@example.com')->notify(new BookingMade($booking));
```

To use it for one mailer while another stays the default:

```php
Mail::mailer('myemail')->to($user)->send(new BookingConfirmed($booking));
```

### Attachments

Regular and inline attachments both work through the normal Laravel API:

```php
public function toMail(object $notifiable): MailMessage
{
    return (new MailMessage)
        ->subject('Your invoice')
        ->line('Invoice attached.')
        ->attach(storage_path('app/invoices/INV-1.pdf'));
}
```

Files are base64-encoded into the JSON request. Service limits are 20 files and 25MB combined decoded size. Base64 inflates payloads by about a third, so 25MB of files is roughly 33MB of request body — if a reverse proxy sits in front of the API, its body limit needs to accommodate that.

For inline images, `embed()` works and the `cid:` reference is preserved:

```php
$message->embed(storage_path('app/logo.png'), 'logo');
// reference it in HTML as <img src="cid:logo">
```

## Error handling and retries

Failures are split by whether retrying can help:

| Exception | Raised for | Retry |
| --- | --- | --- |
| `MyEmailTransientException` | 429, 5xx, connection failure | Yes, worth retrying |
| `MyEmailRequestException` | 400, 401, 403, 404, 409, 413, 422 | No, will fail identically |
| `MyEmailConfigurationException` | Missing endpoint or token | No |

All three extend Symfony's `TransportException`, so existing error handling keeps working. Both request exceptions expose `status()` and `errorCode()`, letting you skip pointless retries:

```php
use Hariadi\MyEmail\Exceptions\MyEmailRequestException;
use Hariadi\MyEmail\Exceptions\MyEmailTransientException;

public function failed(\Throwable $e): void
{
    if ($e instanceof MyEmailRequestException && ! $e instanceof MyEmailTransientException) {
        Log::error('MYEmail rejected permanently', ['code' => $e->errorCode()]);
    }
}
```

Common `errorCode()` values: `validation_error`, `invalid_from`, `unauthorized`, `organization_suspended`, `idempotency_conflict`, `quota_exceeded`.

## Duplicate protection

The API requires an `Idempotency-Key` on every request. This package derives it from the message ID rather than generating a fresh UUID per attempt, so a queue job that retries after a network timeout replays the original submission instead of sending a second copy.

Delivery is still at-least-once overall: a `2xx` means the service durably accepted the message, not that the recipient received it. The API reports `SMTP_ACCEPTED`, never `DELIVERED`.

## Queue your mail

Recommended, though not required. Because delivery is now an outbound HTTP call, a slow API becomes a slow request unless mail is queued:

```php
class BookingMade extends Notification implements ShouldQueue
{
    use Queueable;
}
```

## A note on the `endpoint` key

If you override settings per-mailer in `config/mail.php`, the key is `endpoint`, not `url`:

```php
'mailers' => [
    'myemail' => [
        'transport' => 'myemail',
        'endpoint' => env('MYEMAIL_URL'),
        'token' => env('MYEMAIL_TOKEN'),
    ],
],
```

Laravel's `MailManager` treats a `url` key in a mailer block as a DSN and overwrites the transport with the URL scheme, which fails with `Unsupported mail transport [https]`. Hence `endpoint`.

## Local development

Point at Mailpit over SMTP instead of installing anything extra — set `MAIL_MAILER=smtp` in local and Docker environments and keep `myemail` for staging and production. Mailpit will not catch a wrong `MAIL_FROM_ADDRESS` or a key scope problem, so exercise the real API in staging at least once before going live.

## Testing

```bash
composer install
composer test
```

## License

MIT.
