<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A clean checkout must not depend on a developer's private APP_KEY.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }
}
