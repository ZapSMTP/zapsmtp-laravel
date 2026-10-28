<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use ZapSMTP\Laravel\Mail\ZapSMTPTransport;

/** php artisan zapsmtp:test {to}: sends a test email through a mailer. */
final class TestCommand extends Command
{
    protected $signature = 'zapsmtp:test {to : The recipient}
        {--mailer= : The mailer (default: the default mailer)}
        {--subject=ZapSMTP test : The subject}';

    protected $description = 'Send a test email through ZapSMTP and show its id';

    public function handle(MailFactory $mail): int
    {
        $to = $this->argument('to');
        $to = \is_string($to) ? $to : '';
        if (filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("\"$to\" is not an email address");

            return self::INVALID;
        }
        $mailerName = $this->option('mailer');
        $mailer = $mail->mailer(\is_string($mailerName) && $mailerName !== '' ? $mailerName : null);
        if ($mailer instanceof Mailer && !$mailer->getSymfonyTransport() instanceof ZapSMTPTransport) {
            $this->components->warn('This mailer does not use the zapsmtp transport (set MAIL_MAILER=zapsmtp)');
        }
        $app = config('app.name');
        $subject = $this->option('subject');
        $subject = \is_string($subject) && $subject !== '' ? $subject : 'ZapSMTP test';
        try {
            $sent = $mailer->raw(
                'This test email was sent from ' . (\is_string($app) ? $app : 'Laravel') . ' through ZapSMTP.',
                static fn (Message $m) => $m->to($to)->subject($subject),
            );
        } catch (\Throwable $e) {
            $this->components->error('Not sent: ' . $e->getMessage());

            return self::FAILURE;
        }
        $id = $sent === null ? null : ZapSMTPTransport::emailId($sent);
        $this->components->info($id === null ? "Sent to $to" : "Sent to $to: email id $id");

        return self::SUCCESS;
    }
}
