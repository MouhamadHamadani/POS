<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CSRF tokens are a browser concern. Feature tests don't carry cookies
        // across requests (SESSION_DRIVER=array in phpunit.xml), so the token
        // comparison always fails. Bypassing the middleware keeps test intent
        // on route/controller behaviour rather than session-token plumbing.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
