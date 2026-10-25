<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `/health` was documented as the platform's health check path for days
 * while no such route existed - it returned 404 in production, so the
 * one signal that was supposed to say "this container cannot read its
 * database" said nothing at all. These tests exist so the route cannot
 * quietly go missing again.
 */
it('answers without a login, because a health check cannot hold a session', function (): void {
    $this->get('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'database' => true, 'migrations' => true]);
});

it('reports no failures on a clean install', function (): void {
    $this->get('/health')->assertJson(['jobs_failing' => false]);
});

it('reports a failing job without saying how many', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now()->subHour(),
    ]);

    $body = $this->get('/health')->assertOk()->json();

    expect($body['jobs_failing'])->toBeTrue()
        ->and($body)->toHaveKeys(['status', 'database', 'migrations', 'jobs_failing'])
        ->and($body)->toHaveCount(4);
});

it('forgets a failure once it is old enough to not be the current problem', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now()->subHours(7),
    ]);

    $this->get('/health')->assertJson(['jobs_failing' => false]);
});

it('returns 503 when the migrations table is gone, which is a database it cannot use', function (): void {
    Schema::drop('migrations');

    $this->get('/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'degraded', 'migrations' => false]);
});

/*
 * The thing that makes this endpoint worth having over `/up`: a booted
 * framework with no database behind it.
 */
it('returns 503 when the database will not answer at all', function (): void {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('no route to host'));

    $this->get('/health')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'database' => false,
            'migrations' => false,
            'jobs_failing' => null,
        ]);
});

it('says nothing about job failures while it cannot read them', function (): void {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('no route to host'));

    expect($this->get('/health')->json('jobs_failing'))->toBeNull();
});
