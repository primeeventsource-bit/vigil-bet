<?php

declare(strict_types=1);

use App\Models\Concerns\AppendOnly;
use App\Support\Audit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §12: tests for the append-only guards.
|--------------------------------------------------------------------------
|
| Derived from the models rather than from a list in this file, which is
| the whole point. A hand-kept list of append-only tables is a list
| somebody forgets to extend, and this sweep found six tables - including
| `audit_log` - whose trait was in place and whose database trigger had
| never been written, seven build steps after the first one was.
|
*/

/**
 * Every model that declares itself append-only.
 *
 * @return array<int, class-string>
 */
function appendOnlyModels(): array
{
    $models = [];

    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App'.chr(92).'Models'.chr(92).$file->getFilenameWithoutExtension();

        if (! class_exists($class)) {
            continue;
        }

        if (in_array(AppendOnly::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }

    return $models;
}

it('finds the append-only models rather than trusting a list', function () {
    // If this drops below what the product actually has, the sweep below
    // is silently testing nothing.
    expect(appendOnlyModels())->toHaveCount(14);
});

it('refuses an update on every append-only model', function () {
    foreach (appendOnlyModels() as $class) {
        $model = new $class;

        /*
         * Instantiated as existing rather than saved: these tables have
         * different required columns and seeding fourteen of them would
         * make this a test of the fixtures. The guard fires on the
         * `updating` event, which does not care whether the row is real.
         */
        $model->exists = true;
        $model->syncOriginal();

        expect(fn () => $model->update(['id' => 999_999]))
            ->toThrow(RuntimeException::class, 'append-only');
    }
});

it('refuses a delete on every append-only model', function () {
    foreach (appendOnlyModels() as $class) {
        $model = new $class;
        $model->exists = true;
        $model->{$model->getKeyName()} = 1;
        $model->syncOriginal();

        expect(fn () => $model->delete())
            ->toThrow(RuntimeException::class, 'append-only');
    }
});

it('has a postgres trigger behind every append-only model', function () {
    /*
     * The guard that matters. A trait fires on an Eloquent event and is
     * bypassed by anything that does not go through Eloquent: a raw
     * query, a seeder, a tinker one-liner, an import script written next
     * year. For `audit_log` in particular, the thing it protects against
     * is precisely somebody wanting to change it.
     *
     * Checked by reading the migrations as text, because the suite runs
     * on SQLite and these triggers are Postgres-only. That is weaker
     * than executing them - it proves the SQL was written, not that it
     * runs - and it is what caught six tables whose trait was in place
     * and whose trigger had never been written.
     *
     * A migration is credited with protecting a table when it both
     * mentions that table and creates an append-only trigger. That
     * covers the files which name the trigger literally and the one
     * that builds the name in a loop; a stricter string match would
     * fail a table that is in fact protected.
     */
    $protected = [];

    foreach (File::files(database_path('migrations')) as $file) {
        $sql = File::get($file->getPathname());

        if (! str_contains($sql, 'append_only')) {
            continue;
        }

        foreach (appendOnlyModels() as $class) {
            $table = (new $class)->getTable();

            if (str_contains($sql, $table)) {
                $protected[$table] = true;
            }
        }
    }

    $missing = collect(appendOnlyModels())
        ->map(fn (string $class) => (new $class)->getTable())
        ->reject(fn (string $table) => isset($protected[$table]))
        ->values();

    expect($missing->all())->toBe([]);
});

it('names a trigger for every table it creates one for', function () {
    /*
     * The inverse, and the reason it is here: a trigger created for a
     * table that no longer exists, or misspelled, would pass the check
     * above while protecting nothing.
     */
    $tables = collect(appendOnlyModels())->map(fn (string $class) => (new $class)->getTable());

    $migrations = collect(File::files(database_path('migrations')))
        ->map(fn ($file) => File::get($file->getPathname()))
        ->implode("\n");

    preg_match_all('/CREATE TRIGGER (\w+)_append_only/', $migrations, $matches);

    $orphans = collect($matches[1])->unique()->reject(fn (string $table) => $tables->contains($table));

    expect($orphans->values()->all())->toBe([]);
});

it('keeps the audit log unwritable, which is the one that matters most', function () {
    $event = Audit::write('test.action', null, ['a' => 1], ['a' => 2], 'A reason worth reading');

    // The table whose entire purpose is to be evidence of what somebody
    // did. Guarded in the model, in Postgres, and asserted here.
    expect(fn () => $event->update(['reason' => 'a different reason']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');

    expect(Str::contains(
        File::get(database_path('migrations/2026_10_24_100000_add_missing_append_only_triggers.php')),
        'audit_log',
    ))->toBeTrue();
});
