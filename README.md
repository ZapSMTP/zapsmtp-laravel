# ZapSMTP for Laravel

The official Laravel integration for [ZapSMTP](https://zapsmtp.com): a mail driver for Mailables,
notifications and queued mail, webhooks as Laravel events, suppression sync, artisan commands, a
test fake, and the full API client behind a facade.

Laravel 12.60+ and 13 (the releases with security support); PHP 8.2+ (8.3+ for Laravel 13); Octane-safe.

## Install

```bash
composer require zapsmtp/zapsmtp-laravel
php artisan zapsmtp:install
```

`zapsmtp:install` publishes `config/zapsmtp.php` and adds `ZAPSMTP_API_KEY=`, `MAIL_MAILER=zapsmtp`
and `ZAPSMTP_WEBHOOK_SECRET=` to `.env` and `.env.example` where they are missing (a value you have
is never changed: a new Laravel app already has `MAIL_MAILER=log`, which stays, and the command says
so). Then set the mailer and put your key in `.env`:

```dotenv
MAIL_MAILER=zapsmtp
ZAPSMTP_API_KEY=zap_...
```

and check it:

```bash
php artisan zapsmtp:status                 # the key, your sending domains and their verification
php artisan zapsmtp:test you@example.com   # sends a test email, prints its id
```

The `zapsmtp` mailer is registered for you. To give it options, or a second mailer its own key, add
it to `config/mail.php`:

```php
'mailers' => [
    'zapsmtp' => ['transport' => 'zapsmtp'],
    'zapsmtp_marketing' => ['transport' => 'zapsmtp', 'key' => env('ZAPSMTP_MARKETING_KEY')],
],
```

## Sending mail

Mailables, `Mail::raw()`, notifications and queued mail go through the ZapSMTP API unchanged: From,
To, Cc, Bcc, Reply-To, subject, HTML and text, custom headers, attachments and inline images
(`$message->embed()`). Laravel's `tag()` and `metadata()` become ZapSMTP tags and metadata
(`tag('category:receipt')` is the tag `category` = `receipt`; a tag without a value is `name` = `true`).
When a Symfony `Envelope` names other recipients than the message's headers (a staging sink, a
redirect), the email goes to exactly the envelope's recipients, as To, and never to the header
recipients.

### ZapSMTP options on a Mailable

```php
use ZapSMTP\Laravel\Mail\InteractsWithZapSMTP;

class OrderShipped extends Mailable implements ShouldQueue
{
    use InteractsWithZapSMTP, Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->zapsmtpTags(['category' => 'shipping'])
            ->zapsmtpMetadata(['order_id' => $order->id])
            ->zapsmtpStream('transactional')            // or broadcast
            ->zapsmtpTracking(opens: true, clicks: true)
            ->zapsmtpScheduleAt(now()->addHour())        // or 'in 2 hours'
            ->zapsmtpIdempotencyKey("shipped-{$order->id}");
    }
}
```

Send a template stored in ZapSMTP instead of a view:

```php
class Receipt extends Mailable
{
    use InteractsWithZapSMTP;

    public function __construct(Order $order)
    {
        $this->zapsmtpTemplate('receipt', ['name' => $order->customer_name, 'total' => $order->total]);
    }
}
```

The template's subject and body are used (pass a third argument to set the subject); From, the
recipients, attachments and the other options still come from the Mailable. The options survive
queueing.

### Queued mail is sent once

Mail sent from a queued job (queued Mailables, queued notifications, or `Mail::send()` inside your own
job) gets an `Idempotency-Key` derived from the job's uuid, the send's place in the job, its
recipients and its subject. The key stays the same when the job is retried, so a retry after a
timeout or a failure never sends the email twice; an email an earlier attempt already sent is
skipped. `zapsmtpIdempotencyKey()` replaces the derived key; an empty one is refused.

### Notifications

```php
use ZapSMTP\Laravel\Notifications\ZapSMTPMailMessage;

public function toMail(object $notifiable): ZapSMTPMailMessage
{
    return (new ZapSMTPMailMessage())
        ->subject('Invoice paid')
        ->line('Thanks for your payment.')
        ->zapsmtpTags(['category' => 'invoice'])
        ->zapsmtpMetadata(['invoice_id' => $this->invoice->id]);
}
```

### The email id

After sending, the ZapSMTP email id is the sent message's id:

```php
$sent = Mail::to($user)->send(new OrderShipped($order));
$id = $sent->getMessageId();  // also ZapSMTP\Laravel\Mail\ZapSMTPTransport::emailId($sent)

Event::listen(function (\Illuminate\Mail\Events\MessageSent $e) {
    $id = $e->sent->getMessageId();
});
```

A failed send throws Symfony's `TransportException` wrapping the SDK's exception
(`$e->getPrevious()`), so Laravel's failover mailer works.

## Webhooks

Add the route (routes/web.php or routes/api.php; CSRF protection is turned off for it, the signature
check replaces it):

```php
Route::zapsmtpWebhooks();                    // POST /zapsmtp/webhooks
Route::zapsmtpWebhooks('/hooks/zapsmtp');    // or another path
```

then create the webhook in ZapSMTP and store its secret in `.env`:

```bash
php artisan zapsmtp:webhook                              # for APP_URL + the route
php artisan zapsmtp:webhook --event=email.bounced --event=email.complained
php artisan zapsmtp:webhook --rotate                     # a new secret for the existing webhook
```

Each request's `Zap-Signature` is checked with `ZAPSMTP_WEBHOOK_SECRET` (a bad one is answered 400),
then every event (batched deliveries included) is dispatched as `WebhookReceived` and as its own
class:

| Event | Extra accessors |
|---|---|
| `EmailSent`, `EmailDelivered`, `EmailDeliveryDelayed`, `EmailFailed`, `EmailComplained`, `EmailSuppressed` | |
| `EmailBounced` | `bounceType()`, `bounceMessage()`, `isSuppressed()` |
| `EmailOpened` | `isMachineOpen()` |
| `EmailClicked` | `link()`, `action()` (the click action), `isUnique()` |
| `EmailReplied` | `reply()`, `replyType()` |
| `EmailReceived`, `ThreadCreated`, `ThreadReplied` | (inbound mail; read it with `ZapSMTP::emails()->receiving->get($e->emailId())`) |

All of them (namespace `ZapSMTP\Laravel\Events`) have `type()`, `data()`, `emailId()`, `recipient()`,
`tags()`, `metadata()`, the event's `id` and the raw `event`.

```php
use ZapSMTP\Laravel\Events\EmailClicked;

Event::listen(function (EmailClicked $e) {
    if ($e->action() === 'confirm_order' && $e->isUnique()) {
        Order::where('number', $e->metadata()['order_id'] ?? null)->update(['confirmed' => true]);
    }
});
```

ZapSMTP retries a delivery until it gets a 2xx, so an event can arrive twice: processed event ids are
kept in the cache (3 days) and repeats are skipped. If a listener throws, the id is forgotten and
ZapSMTP's retry delivers the event again. To answer at once and dispatch from your queue, set
`ZAPSMTP_WEBHOOK_QUEUE=true` (and optionally `ZAPSMTP_WEBHOOK_QUEUE_CONNECTION` /
`ZAPSMTP_WEBHOOK_QUEUE_NAME`); listeners can also implement `ShouldQueue` as usual.

## Suppression sync

Stop emailing addresses that bounce or complain: publish the migration (it adds a nullable
`users.email_suppressed_at`), migrate, and turn the sync on.

```bash
php artisan vendor:publish --tag=zapsmtp-migrations
php artisan migrate
```

```dotenv
ZAPSMTP_SUPPRESSION_SYNC=true
```

On `email.bounced`, `email.complained` and `email.suppressed` webhooks, the user whose `email`
matches the recipient (case-insensitive) gets `email_suppressed_at = now()`. The model, the columns
and the event types are set in `config/zapsmtp.php` (`suppression`). It needs the webhook route.

## The API client

The `ZapSMTP` facade (or `ZapSMTP\Client` from the container) is the full
[PHP SDK](../php) client, configured from `config/zapsmtp.php`:

```php
use ZapSMTP\Laravel\Facades\ZapSMTP;

ZapSMTP::emails()->send([...]);
foreach (ZapSMTP::emails()->list(['status' => 'bounced']) as $email) { ... }
ZapSMTP::domains()->verify($id);
ZapSMTP::suppressions()->create(['email' => 'old@example.org']);

public function __construct(private \ZapSMTP\Client $zap) {}
```

## Testing your app

`ZapSMTP::fake()` stops real sends (by the mailer and by the client) and records them:

```php
use ZapSMTP\Laravel\Facades\ZapSMTP;

ZapSMTP::fake();

$this->post('/checkout', [...]);

ZapSMTP::assertSentTo('jane@example.org');
ZapSMTP::assertSent(fn (array $email) => $email['subject'] === 'Your receipt');
ZapSMTP::assertSentTemplate('receipt', fn (array $variables) => $variables['total'] === 42);
ZapSMTP::assertSentCount(1);
ZapSMTP::assertNothingSent();
```

Every `zapsmtp` mailer uses the fake, mailers with their own `key` included. An email is the `emails->send` parameters the API would get (`to` is a list when the mailer sent
it). Other API calls answer with empty results. Laravel's own `Mail::fake()` still works for
asserting Mailables.

## Configuration

`config/zapsmtp.php` (publish it with `php artisan zapsmtp:install`):

| Key | Environment | Default |
|---|---|---|
| `api_key` | `ZAPSMTP_API_KEY`, else `ZAP_API_KEY` | required |
| `base_url` | `ZAPSMTP_BASE_URL`, else `ZAP_BASE_URL` | `https://api.zapsmtp.com/v1` |
| `timeout` | `ZAPSMTP_TIMEOUT` | 60 s per attempt |
| `max_retries` | `ZAPSMTP_MAX_RETRIES` | 2 |
| `webhook.secret` | `ZAPSMTP_WEBHOOK_SECRET` | |
| `webhook.tolerance` | `ZAPSMTP_WEBHOOK_TOLERANCE` | 300 s |
| `webhook.dedupe` | `ZAPSMTP_WEBHOOK_CACHE_STORE` | on, the default cache store, 3 days |
| `webhook.queue` | `ZAPSMTP_WEBHOOK_QUEUE`, `..._CONNECTION`, `..._NAME` | off |
| `suppression` | `ZAPSMTP_SUPPRESSION_SYNC` | off; `App\Models\User`, `email`, `email_suppressed_at` |

A wrong value fails with `ZapSMTP\Laravel\Exceptions\InvalidConfigurationException` naming the key
(for example `config zapsmtp.timeout must be a number above 0, string given`).

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The package takes `zapsmtp/zapsmtp-php` from `../php` (a path repository) while both live in this
repository.
