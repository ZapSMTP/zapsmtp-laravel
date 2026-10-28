<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use ZapSMTP\Client;
use ZapSMTP\Exception\AuthenticationException;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Laravel\Config;

/** php artisan zapsmtp:status: the key, the sending domains, the mailer and webhooks. */
final class StatusCommand extends Command
{
    protected $signature = 'zapsmtp:status';

    protected $description = 'Check the ZapSMTP API key and list your sending domains';

    public function handle(Repository $repository): int
    {
        try {
            $config = Config::from($repository);
            $client = $this->laravel->make(Client::class);
            \assert($client instanceof Client);
            $this->components->twoColumnDetail('API', $client->getBaseUrl());
            $rows = [];
            foreach ($client->domains->list() as $d) {
                $rows[] = [$d->name ?? '', $d->status ?? '', self::yes($d->dkim_verified), self::yes($d->spf_verified), self::yes($d->dmarc_verified), $d->verified_at ?? ''];
            }
        } catch (AuthenticationException $e) {
            $this->components->error('The API key was refused: ' . $e->getMessage() . ' (set ZAPSMTP_API_KEY)');

            return self::FAILURE;
        } catch (ZapSMTPException|\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('API key', '<fg=green>valid</>');
        $mailer = $repository->get('mail.default');
        $this->components->twoColumnDetail('Default mailer', \is_string($mailer) ? $mailer . ($mailer === 'zapsmtp' ? '' : ' <fg=yellow>(not zapsmtp)</>') : '-');
        try {
            $config->webhookSecret();
            $this->components->twoColumnDetail('Webhook secret', 'set');
        } catch (\InvalidArgumentException) {
            $this->components->twoColumnDetail('Webhook secret', '<fg=yellow>not set</> (php artisan zapsmtp:webhook)');
        }
        if ($rows === []) {
            $this->components->warn('No sending domain yet: add one in the dashboard (Domains) and verify its DNS records');

            return self::SUCCESS;
        }
        $this->table(['Domain', 'Status', 'DKIM', 'SPF', 'DMARC', 'Verified at'], $rows);
        if (!\in_array('verified', array_column($rows, 1), true)) {
            $this->components->warn('No domain is verified yet: live keys can only send from a verified domain');
        }

        return self::SUCCESS;
    }

    private static function yes(?bool $v): string
    {
        return $v === null ? '-' : ($v ? 'yes' : 'no');
    }
}
