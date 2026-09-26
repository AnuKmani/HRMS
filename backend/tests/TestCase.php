<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Swap the bearer token by forgetting the memoized guards first.
     *
     * Illuminate\Auth\RequestGuard — the driver behind `auth:sanctum` — caches
     * the user it resolved, on purpose: "we do not want to fetch the user data
     * on every call to this method". In production that cache dies with the
     * request, because each request is a fresh process. In a test the same
     * application instance serves every $this->getJson() call, so the first
     * token to authenticate would keep authenticating for the rest of the test
     * — a token that had been signed out or revoked would still be accepted,
     * and "expect 401 after revoking" assertions would silently pass for the
     * wrong reason or fail for the right one.
     *
     * AuthManager::forgetGuards() is the framework's own escape hatch for
     * exactly this. Calling it whenever the caller swaps tokens restores
     * production semantics: each request resolves its Authorization header
     * from scratch.
     *
     * Safe for every existing test: the Phase 2 RBAC tests authenticate with
     * Sanctum::actingAs() and never call withToken(), so nothing they rely on
     * is discarded here.
     */
    public function withToken(string $token, string $type = 'Bearer')
    {
        Auth::forgetGuards();

        return parent::withToken($token, $type);
    }
}
