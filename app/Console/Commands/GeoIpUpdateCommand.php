<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Trial\Geo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Throwable;

/**
 * Fetches the GeoLite2 databases.
 *
 * Not committed to the repository, for two reasons that both matter:
 * the repository is public and GeoLite2 carries redistribution terms,
 * and the City database is tens of megabytes of data that changes
 * weekly, which is the worst possible thing to keep in git history.
 *
 * Never fails the deploy. A missing licence key, a changed URL, a
 * MaxMind outage or a truncated download all leave the product exactly
 * as it was: Geo returns nulls, the trial activates, and the admin page
 * shows no location. The alternative is signups that stop working on a
 * morning when somebody else has a bad day, in exchange for a signal
 * that is advisory anyway.
 */
class GeoIpUpdateCommand extends Command
{
    protected $signature = 'vigil:geoip-update {--force : Download even if the files look current}';

    protected $description = 'Download the GeoLite2 databases used for the trial location signal';

    /** @var array<string, string> */
    private const EDITIONS = [
        'GeoLite2-City' => 'GeoLite2-City.mmdb',
        'GeoLite2-ASN' => 'GeoLite2-ASN.mmdb',
    ];

    /** Older than this and it is worth refreshing. MaxMind publish weekly. */
    private const STALE_DAYS = 7;

    public function handle(): int
    {
        $key = (string) config('services.maxmind.license_key');

        if ($key === '') {
            // Not an error. The product runs without this.
            $this->components->warn('MAXMIND_LICENSE_KEY is not set. Location will not be recorded.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(storage_path('app/geoip'));

        foreach (self::EDITIONS as $edition => $filename) {
            $this->fetch($edition, $filename, $key);
        }

        return self::SUCCESS;
    }

    private function fetch(string $edition, string $filename, string $key): void
    {
        $target = Geo::path($filename);

        if (! $this->option('force') && is_file($target)
            && filemtime($target) > now()->subDays(self::STALE_DAYS)->getTimestamp()) {
            $this->components->twoColumnDetail($edition, 'current');

            return;
        }

        try {
            $response = Http::timeout(120)->get('https://download.maxmind.com/app/geoip_download', [
                'edition_id' => $edition,
                'license_key' => $key,
                'suffix' => 'tar.gz',
            ]);

            if (! $response->successful()) {
                $this->components->twoColumnDetail($edition, '<fg=red>HTTP '.$response->status().'</>');

                return;
            }

            /*
             * MaxMind ship a tarball with the .mmdb nested under a
             * dated directory, so the archive is extracted to a
             * scratch path and the one file lifted out of it.
             */
            $scratch = storage_path('app/geoip/.tmp-'.$edition);
            File::ensureDirectoryExists($scratch);

            $archive = $scratch.'/download.tar.gz';
            File::put($archive, $response->body());

            (new PharData($archive))->decompress();
            (new PharData(str_replace('.tar.gz', '.tar', $archive)))->extractTo($scratch, null, true);

            $found = collect(File::allFiles($scratch))
                ->first(fn ($file) => $file->getFilename() === $filename);

            if ($found === null) {
                $this->components->twoColumnDetail($edition, '<fg=red>no '.$filename.' in the archive</>');
                File::deleteDirectory($scratch);

                return;
            }

            // Moved into place last, so a failure part-way through
            // leaves the previous database working rather than a
            // half-written one.
            File::move($found->getPathname(), $target);
            File::deleteDirectory($scratch);

            $this->components->twoColumnDetail($edition, 'updated ('.number_format(filesize($target) / 1048576, 1).' MB)');
        } catch (Throwable $e) {
            // Reported, never thrown. The deploy continues.
            $this->components->twoColumnDetail($edition, '<fg=red>'.$e->getMessage().'</>');
        }
    }
}
