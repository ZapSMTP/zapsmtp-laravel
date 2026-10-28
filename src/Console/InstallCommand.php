<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Console;

use Illuminate\Console\Command;

/** php artisan zapsmtp:install: config, .env entries and the next steps. */
final class InstallCommand extends Command
{
    protected $signature = 'zapsmtp:install {--force : Overwrite config/zapsmtp.php when it exists}';

    protected $description = 'Set up ZapSMTP: publish config/zapsmtp.php and add its .env entries';

    private const ENV = ['ZAPSMTP_API_KEY' => '', 'MAIL_MAILER' => 'zapsmtp', 'ZAPSMTP_WEBHOOK_SECRET' => ''];

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'zapsmtp-config', '--force' => (bool) $this->option('force')]);

        $app = $this->laravel;
        $dir = $app instanceof \Illuminate\Foundation\Application ? $app->environmentPath() : $app->basePath();
        foreach (['.env', '.env.example'] as $file) {
            $env = new EnvFile($dir . \DIRECTORY_SEPARATOR . $file);
            foreach (self::ENV as $name => $value) {
                $result = $env->add($name, $value);
                if ($result === EnvFile::NO_FILE) {
                    $this->components->warn("$file not found: add " . implode(', ', array_keys(self::ENV)) . ' yourself');
                    break;
                }
                if ($result === EnvFile::ADDED) {
                    $this->components->info("$file: added $name=$value");
                } elseif ($result === EnvFile::DIFFERENT && $name === 'MAIL_MAILER') {
                    $this->components->warn("$file: MAIL_MAILER is " . $env->get($name) . '; set it to zapsmtp to send mail through ZapSMTP');
                }
            }
        }

        $this->newLine();
        $this->line('The <comment>zapsmtp</comment> mailer is registered for you. To change it, add to config/mail.php:');
        $this->line("    'mailers' => [ ..., 'zapsmtp' => ['transport' => 'zapsmtp'] ],");
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Put your API key in ZAPSMTP_API_KEY (https://zapsmtp.com, Dashboard -> API keys).');
        $this->line('  2. php artisan zapsmtp:status       checks the key and your sending domains');
        $this->line('  3. php artisan zapsmtp:test you@example.com   sends a test email');
        $this->line('  4. Webhooks: add Route::zapsmtpWebhooks(); to routes/web.php, then php artisan zapsmtp:webhook');

        return self::SUCCESS;
    }
}
