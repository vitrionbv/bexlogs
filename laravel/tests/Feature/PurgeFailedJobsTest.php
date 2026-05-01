<?php

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('guests cannot purge failed jobs', function () {
    $this->delete(route('jobs.purge_failed'))->assertRedirect(route('login'));
});

test('purge failed jobs removes only failed scrape jobs for the user orgs', function () {
    $user = User::factory()->create();

    $org = Organization::create([
        'id' => 'org-'.Str::random(8),
        'user_id' => $user->id,
        'name' => 'Purge Failed Org',
    ]);

    $bexApp = Application::create([
        'id' => 'app-'.Str::random(8),
        'organization_id' => $org->id,
        'name' => 'Purge Failed App',
    ]);

    $subscription = Subscription::create([
        'id' => 'sub-'.Str::random(8),
        'application_id' => $bexApp->id,
        'name' => 'Purge Failed Sub',
        'environment' => 'production',
    ]);

    $session = BexSession::create([
        'user_id' => $user->id,
        'environment' => 'production',
        'cookies_encrypted' => encrypt(json_encode([])),
        'captured_at' => now(),
    ]);

    $failedA = ScrapeJob::create([
        'subscription_id' => $subscription->id,
        'bex_session_id' => $session->id,
        'status' => ScrapeJob::STATUS_FAILED,
    ]);

    $failedB = ScrapeJob::create([
        'subscription_id' => $subscription->id,
        'bex_session_id' => $session->id,
        'status' => ScrapeJob::STATUS_FAILED,
    ]);

    $completed = ScrapeJob::create([
        'subscription_id' => $subscription->id,
        'bex_session_id' => $session->id,
        'status' => ScrapeJob::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->from(route('jobs.index'))->delete(route('jobs.purge_failed'));

    $response->assertRedirect(route('jobs.index'));
    $response->assertSessionHas('status', 'failed-jobs-purged');
    $response->assertSessionHas('failed_purged_count', 2);

    expect(ScrapeJob::query()->whereKey($completed->id)->exists())->toBeTrue();
    expect(ScrapeJob::query()->whereKey($failedA->id)->exists())->toBeFalse();
    expect(ScrapeJob::query()->whereKey($failedB->id)->exists())->toBeFalse();
});

test('purge failed jobs does not delete another users failed jobs', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $org = Organization::create([
        'id' => 'org-'.Str::random(8),
        'user_id' => $other->id,
        'name' => 'Other Org',
    ]);

    $bexApp = Application::create([
        'id' => 'app-'.Str::random(8),
        'organization_id' => $org->id,
        'name' => 'Other App',
    ]);

    $subscription = Subscription::create([
        'id' => 'sub-'.Str::random(8),
        'application_id' => $bexApp->id,
        'name' => 'Other Sub',
        'environment' => 'production',
    ]);

    $session = BexSession::create([
        'user_id' => $other->id,
        'environment' => 'production',
        'cookies_encrypted' => encrypt(json_encode([])),
        'captured_at' => now(),
    ]);

    $otherFailed = ScrapeJob::create([
        'subscription_id' => $subscription->id,
        'bex_session_id' => $session->id,
        'status' => ScrapeJob::STATUS_FAILED,
    ]);

    $this->actingAs($user)->delete(route('jobs.purge_failed'))->assertRedirect();

    expect(ScrapeJob::query()->whereKey($otherFailed->id)->exists())->toBeTrue();
});
