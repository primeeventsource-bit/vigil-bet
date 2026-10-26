<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Writes the database is supposed to refuse
|--------------------------------------------------------------------------
|
| Several tests prove a constraint or a trigger rejects something, and
| then go on to assert the row was not written. On SQLite that works.
| On Postgres the rejected statement aborts the surrounding transaction,
| so every assertion after it fails with "current transaction is aborted"
| - for a reason with nothing to do with what it was checking.
|
| RefreshDatabase wraps each test in a transaction, so there is always a
| surrounding one to poison. Running the attempt in a nested transaction
| puts it behind a savepoint: the failure rolls back to the savepoint and
| the test can keep querying.
|
| This was invisible until CI first ran the suite against Postgres, which
| is the environment the product actually runs on.
|
*/

/**
 * Wraps an attempt the database should reject, for `expect(...)->toThrow()`.
 *
 * The exception still propagates, so the assertion is unchanged. Only the
 * transaction survives it.
 */
function rejectedByDatabase(callable $attempt): Closure
{
    return fn () => DB::transaction($attempt);
}
