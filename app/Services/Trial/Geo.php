<?php

declare(strict_types=1);

namespace App\Services\Trial;

use GeoIp2\Database\Reader;
use Throwable;

/**
 * Approximate location, as a signal for a human to read.
 *
 * It is never a reason to refuse anything. Every path through this class
 * that cannot produce an answer produces nulls instead - missing
 * database, missing licence key, a private address, a corrupt file,
 * MaxMind changing a URL, an exception from a library this code does not
 * control. A trial activates regardless.
 *
 * That is a deliberate design choice and not laziness. The alternative
 * is a product whose signups stop working on a morning when a third
 * party has an outage, in exchange for a control that a three-dollar VPN
 * walks straight past. Location here answers "is this the fifth trial
 * from one network this hour", for somebody to look at. The thing that
 * actually prevents a second trial is the email redemption record.
 *
 * The database is not in the repository. GeoLite2 has redistribution
 * terms and the repository is public, so `vigil:geoip-update` fetches it
 * into storage with a licence key and the deploy runs that.
 */
class Geo
{
    /** Two readers, because MaxMind ships country and ASN separately. */
    private ?Reader $city = null;

    private ?Reader $asn = null;

    private bool $attempted = false;

    /**
     * Country, region and ASN for an address, any of which may be null.
     *
     * @return array{country: ?string, region: ?string, asn: ?string}
     */
    public function locate(?string $ip): array
    {
        $empty = ['country' => null, 'region' => null, 'asn' => null];

        if ($ip === null || $ip === '' || ! $this->isPublic($ip)) {
            return $empty;
        }

        $this->open();

        $out = $empty;

        try {
            if ($this->city !== null) {
                $record = $this->city->city($ip);
                $out['country'] = $record->country->isoCode;
                // The most specific subdivision, which is a state in the
                // US and something else almost everywhere, hence a
                // generic column name.
                $out['region'] = $record->mostSpecificSubdivision->isoCode
                    ?? $record->mostSpecificSubdivision->name;
            }
        } catch (Throwable) {
            // Address not in the database, or the file is not what it
            // claims. Neither is worth an exception reaching a signup.
        }

        try {
            if ($this->asn !== null) {
                $record = $this->asn->asn($ip);
                $out['asn'] = $record->autonomousSystemNumber === null
                    ? null
                    : 'AS'.$record->autonomousSystemNumber;
            }
        } catch (Throwable) {
            // As above.
        }

        return $out;
    }

    /** Whether a database is loaded at all, for vigil:doctor to report. */
    public function available(): bool
    {
        $this->open();

        return $this->city !== null || $this->asn !== null;
    }

    public static function path(string $name): string
    {
        return storage_path('app/geoip/'.$name);
    }

    /**
     * Open the readers once per instance, and never throw.
     *
     * Memoised on `attempted` rather than on the readers themselves, so
     * a missing file is not re-opened on every lookup in a loop.
     */
    private function open(): void
    {
        if ($this->attempted) {
            return;
        }

        $this->attempted = true;

        foreach ([['city', 'GeoLite2-City.mmdb'], ['asn', 'GeoLite2-ASN.mmdb']] as [$property, $file]) {
            $path = self::path($file);

            if (! is_file($path)) {
                continue;
            }

            try {
                $this->{$property} = new Reader($path);
            } catch (Throwable) {
                // A truncated download is the common case. Leave it
                // null and carry on.
            }
        }
    }

    /**
     * Private, loopback and reserved ranges are not worth a lookup.
     *
     * They are also what a request arrives from in development and
     * behind some proxies, where a failed lookup would otherwise be
     * logged as an error on every signup.
     */
    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
