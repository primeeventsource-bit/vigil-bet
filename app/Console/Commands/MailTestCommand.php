<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves whether this environment can actually deliver mail.
 *
 * Mail is the one subsystem that fails without complaining. The `log`
 * driver accepts every message, writes it to a file and returns success,
 * so the application, the tests and the health endpoint all agree that
 * everything is fine while nobody receives anything. Production ran that
 * way: a customer registered, the verification notice went to the log,
 * and they were left outside the `verified` route group with no way back
 * in and nothing in the logs that read as an error.
 *
 * So this command does the two things a config dump cannot. It reports
 * what the mailer would do, loudly, including refusing to pretend that
 * `log` is working delivery. And it sends a real message and lets the
 * transport's own exception through, because "535 username and password
 * not accepted" is the whole answer and summarising it as "failed" is
 * throwing the answer away.
 */
class MailTestCommand extends Command
{
    protected $signature = 'vigil:mail-test
                            {to? : Address to send to, default the configured from address}
                            {--check : Report the configuration and reachability, send nothing}';

    protected $description = 'Send a test email and report exactly how the transport responded';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $smtp = (array) config('mail.mailers.'.$mailer);
        $from = (string) config('mail.from.address');
        $to = (string) ($this->argument('to') ?? $from);

        $this->components->twoColumnDetail('Mailer', $mailer);
        $this->components->twoColumnDetail('From', $from.' ('.config('mail.from.name').')');
        $this->components->twoColumnDetail('To', $to);

        if ($mailer === 'smtp') {
            $this->components->twoColumnDetail('Host', ($smtp['host'] ?? '?').':'.($smtp['port'] ?? '?'));
            $this->components->twoColumnDetail('Scheme', (string) ($smtp['scheme'] ?? 'auto'));
            $this->components->twoColumnDetail('Username', ($smtp['username'] ?? null) ? 'set' : '<fg=red>missing</>');
            // Length only. A password that is the wrong length is a
            // different mistake from one that is absent, and neither
            // needs the value printed into a terminal or a log.
            $this->components->twoColumnDetail(
                'Password',
                ($smtp['password'] ?? null)
                    ? 'set ('.strlen((string) $smtp['password']).' chars)'
                    : '<fg=red>missing</>'
            );

            $this->reachability((string) ($smtp['host'] ?? ''), (int) ($smtp['port'] ?? 0), $smtp['scheme'] ?? null);
        }

        $this->newLine();

        if ($mailer === 'log' || $mailer === 'array') {
            $this->components->error(
                'MAIL_MAILER is "'.$mailer.'", so nothing is delivered. Mail is written '
                .'to the log and every send reports success.'
            );

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->components->info('Configuration only. Drop --check to send.');

            return self::SUCCESS;
        }

        try {
            Mail::raw($this->body(), function ($message) use ($to): void {
                $message->to($to)->subject('['.config('brand.name').'] Mail delivery test');
            });
        } catch (Throwable $e) {
            // The transport's own words, not a summary of them.
            $this->components->error(get_class($e));
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Accepted by '.($smtp['host'] ?? $mailer).' for delivery to '.$to.'.');
        $this->line('  Accepted is not delivered. Check the inbox, and the spam folder.');

        return self::SUCCESS;
    }

    /**
     * An egress block and a bad password look identical from the outside
     * and have nothing in common as fixes, so the socket is tested
     * separately from the credentials.
     */
    private function reachability(string $host, int $port, ?string $scheme): void
    {
        if ($host === '' || $port <= 0) {
            return;
        }

        $target = $scheme === 'smtps' ? 'ssl://'.$host : $host;
        $socket = @fsockopen($target, $port, $errno, $errstr, 8);

        if ($socket === false) {
            $this->components->twoColumnDetail('Socket', '<fg=red>unreachable: '.$errstr.' ('.$errno.')</>');

            return;
        }

        stream_set_timeout($socket, 8);
        $banner = trim((string) fgets($socket, 1024));
        fclose($socket);

        $this->components->twoColumnDetail('Socket', '<fg=green>open</>');
        $this->components->twoColumnDetail('Banner', $banner !== '' ? $banner : '(silent)');
    }

    private function body(): string
    {
        return implode("\n", [
            'This is a delivery test from '.config('brand.name').'.',
            '',
            'Environment: '.app()->environment(),
            'Mailer:      '.config('mail.default'),
            'Sent:        '.now()->toDateTimeString().' UTC',
            '',
            'If you are reading this, transactional mail works: email verification,',
            'password resets, payment failure notices and alerts can all reach people.',
        ]);
    }
}
