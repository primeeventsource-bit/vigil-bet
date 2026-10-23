<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /*
     * Laravel's scaffolded test, and the only PHPUnit-style one in the
     * suite. Pest applies RefreshDatabase through pest()->extend(), which
     * does not reach a plain TestCase subclass - so the trait is here
     * explicitly. Without it this test ran against whatever schema the
     * last migration happened to leave behind, which is how it survived
     * until the home page first touched a table.
     */
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
