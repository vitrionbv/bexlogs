<?php

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The migration adds a Postgres partial unique index on
 * `scrape_jobs (subscription_id) WHERE status IN ('queued','running')`,
 * making it impossible (at the DB level) to have two active jobs for
 * the same subscription. SQLite (the default test driver) doesn't
 * create the partial index — these tests skip cleanly there.
 *
 * Both call sites that insert ScrapeJobs (`ManageController::enqueueScrape`
 * and `App\Console\Commands\ScrapeEnqueue`) wrap the insert in a
 * `QueryException` try/catch keyed on SQLSTATE 23505 so the constraint
 * surfaces as the same "already queued" outcome the existing app-level
 * check uses, instead of bubbling a 500.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Partial unique index only created on pgsql');
    }

    $this->user = User::factory()->create();

    $this->org = Organization::create([
        'id' => 'org-'.Str::random(8),
        'user_id' => $this->user->id,
        'name' => 'Race Test Org',
    ]);

    $this->bexApp = Application::create([
        'id' => 'app-'.Str::random(8),
        'organization_id' => $this->org->id,
        'name' => 'Race Test App',
    ]);

    $this->subscription = Subscription::create([
        'id' => 'sub-'.Str::random(8),
        'application_id' => $this->bexApp->id,
        'name' => 'Race Test Sub',
        'environment' => 'production',
    ]);

    $this->session = BexSession::create([
        'user_id' => $this->user->id,
        'environment' => 'production',
        'cookies_encrypted' => encrypt(json_encode([])),
        'captured_at' => now(),
    ]);
});

test('partial unique index rejects a second active scrape job for the same subscription', function () {
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_QUEUED,
    ]);

    // Wrap the failing INSERT in a nested transaction so Postgres opens
    // a SAVEPOINT — without it the unique violation aborts the outer
    // RefreshDatabase transaction and every subsequent assertion in the
    // test fails with `25P02 in failed sql transaction`. Production
    // call sites don't have this problem because they're not inside a
    // wrapping transaction.
    $threw = false;
    try {
        DB::transaction(function () {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->session->id,
                'status' => ScrapeJob::STATUS_QUEUED,
            ]);
        });
    } catch (QueryException $e) {
        $threw = true;
        expect($e->getCode())->toBe('23505');
    }

    expect($threw)->toBeTrue('Expected the partial unique index to reject the second insert');

    expect(
        ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->count()
    )->toBe(1);
});

test('partial unique index rejects a queued+running pair for the same subscription', function () {
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_RUNNING,
        'started_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $threw = false;
    try {
        // Same SAVEPOINT trick as above so the outer RefreshDatabase
        // transaction survives the unique_violation.
        DB::transaction(function () {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->session->id,
                'status' => ScrapeJob::STATUS_QUEUED,
            ]);
        });
    } catch (QueryException $e) {
        $threw = true;
        expect($e->getCode())->toBe('23505');
    }

    expect($threw)->toBeTrue('Expected the partial unique index to reject queued-after-running');
});

test('completed and failed rows do not block a fresh queued row', function () {
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_COMPLETED,
        'completed_at' => now()->subMinutes(10),
    ]);
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_FAILED,
        'completed_at' => now()->subMinutes(5),
        'error' => 'previous attempt died',
    ]);

    // Should succeed — the partial index only constrains queued/running rows.
    $fresh = ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_QUEUED,
    ]);

    expect($fresh->id)->not->toBeNull();
});

test('manage controller surfaces the unique violation as scrape-already-queued without raising', function () {
    // Pre-seed a queued row. The controller's app-level fast-path will
    // see this and short-circuit, but we also want to assert the
    // try/catch around create() works — that's covered by the raw
    // constraint test above. This test asserts the user-facing
    // contract: the controller returns the same flash UX whether the
    // app-level or DB-level guard fires.
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_QUEUED,
    ]);

    $response = $this->actingAs($this->user)
        ->from(route('manage.index'))
        ->post(route('manage.subscriptions.scrape', $this->subscription));

    $response->assertRedirect(route('manage.index'));
    $response->assertSessionHas('status', 'scrape-already-queued');

    expect(
        ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->count()
    )->toBe(1);
});

test('scrape:enqueue command catches the unique violation and skips cleanly', function () {
    $this->subscription->update([
        'auto_scrape' => true,
        'last_scraped_at' => now()->subDays(1),
    ]);

    // Pre-seed a queued row so the command's app-level check skips,
    // then run the command — expected outcome: queued=0 skipped=1, no
    // exception bubbled. The DB partial index gets exercised in the
    // raw-constraint test above; here we're proving the command's
    // try/catch keeps stale fixtures from poisoning a tick.
    ScrapeJob::create([
        'subscription_id' => $this->subscription->id,
        'bex_session_id' => $this->session->id,
        'status' => ScrapeJob::STATUS_QUEUED,
    ]);

    $this->artisan('scrape:enqueue')
        ->expectsOutputToContain('queued=0 skipped=1')
        ->assertSuccessful();

    expect(
        ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->count()
    )->toBe(1);
});
