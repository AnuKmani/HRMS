<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
    | AuthorizesRequests gives every controller `$this->authorize(...)`, which
    | is how Phase 4's policies are reached: `authorize('view', $employee)`
    | resolves EmployeePolicy and turns a refusal into the API's normal 403
    | envelope through bootstrap/app.php.
    |
    | Routes additionally carry the coarse `permission:` middleware. Both run,
    | on purpose — the middleware answers "may this role open the module",
    | the policy answers "may this person touch this row". Either alone would
    | leave a hole: middleware cannot see rows, and a policy a route forgot to
    | call is a policy that is not enforcing anything.
    */
    use AuthorizesRequests;
}
