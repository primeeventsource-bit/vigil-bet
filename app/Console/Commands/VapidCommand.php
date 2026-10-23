<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Generates the VAPID key pair web push needs.
 *
 * A one-off. The keys go in the environment and the private one must
 * never be committed or stored in the database - it is the only thing
 * standing between this application and anybody else pushing
 * notifications to its subscribers.
 *
 * Needs an OpenSSL build that can generate prime256v1 keys, which rules
 * out some Windows installs; run it where the application is deployed.
 */
class VapidCommand extends Command
{
    protected $signature = 'vigil:vapid';

    protected $description = 'Generate a VAPID key pair for web push';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->components->error('Could not generate a key pair: '.$e->getMessage());
            $this->components->warn(
                'This usually means OpenSSL cannot create a prime256v1 key here. '
                .'Run the command on the deployed environment instead.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Put these in the environment. The private key is a secret.');
        $this->newLine();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();

        $this->components->warn('Changing these invalidates every existing push subscription.');

        return self::SUCCESS;
    }
}
