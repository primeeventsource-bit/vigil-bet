<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * The base controller.
 *
 * Carries AuthorizesRequests so `$this->authorize()` works, which it did
 * not until policies existed - the application had no app/Policies at
 * all, and every authorization decision was a middleware check or an
 * inline if. Those are fine for "may you see this area"; they are not
 * fine for "may you do this to this person", which is the question the
 * administrative writes actually ask.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
