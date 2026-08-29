<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Laravel 12 ships this class empty. AuthorizesRequests is added back deliberately: every
 * controller in this application authorizes, and a trait that has to be remembered per file is a
 * trait that will be forgotten in one of them.
 *
 * Route-level `permission:` middleware answers "may this kind of user reach this area". The
 * authorize() calls below it answer "may they touch THIS record". Both are required — §84 is
 * explicit that a URL id is never to be trusted, and route middleware cannot see the id.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
