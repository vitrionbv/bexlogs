<?php

namespace Tests\Feature;

use App\Events\BexSessionDeleted;
use App\Models\BexSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Cover the prune flow end-to-end:
 *
 *   1. CLI dry-run lists the orphan plan but mutates nothing.
 *   2. CLI --force removes the orphan, leaves the fresh anchor + the
 *      different-account row alone, and dispatches BexSessionDeleted.
 *   3. The /authenticate/stale-sessions endpoint is the same logic
 *      scoped to the current user; another user's rows are untouched.
 *   4. Buckets without a fresh anchor are left untouched (we'd have
 *      no authoritative row to compare emails against).
 *   5. Multiple empty-email rows in one bucket: warned, not pruned.
 *
 * Together these pin the safety properties the operator relies on:
 * "different account" history is preserved, the prune never runs in
 * the absence of an obvious anchor, and the action is observable
 * over the realtime channel.
 */
class BexPruneStaleSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_plan_without_deleting(): void
    {
        Event::fake([BexSessionDeleted::class]);

        $user = User::factory()->create();
        $orphan = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
            'last_validated_at' => now()->subMinutes(30),
        ]);
        $fresh = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('bex:prune-stale-sessions')
            ->expectsOutputToContain('Inspected 2 BexSession row(s)')
            ->expectsOutputToContain('Would delete 1 row(s)')
            ->expectsOutputToContain('Dry-run only. Re-run with --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('bex_sessions', ['id' => $orphan->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $fresh->id]);
        Event::assertNotDispatched(BexSessionDeleted::class);
    }

    public function test_force_deletes_orphan_and_dispatches_event(): void
    {
        Event::fake([BexSessionDeleted::class]);

        $user = User::factory()->create();
        $orphan = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
            'last_validated_at' => now()->subMinutes(30),
        ]);
        $fresh = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('bex:prune-stale-sessions --force')
            ->expectsOutputToContain('Deleted 1 row(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('bex_sessions', ['id' => $orphan->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $fresh->id]);

        Event::assertDispatched(
            BexSessionDeleted::class,
            fn (BexSessionDeleted $e) => $e->bexSessionId === $orphan->id
                && $e->userId === $user->id
                && $e->reason === 'orphan_pruned',
        );
    }

    public function test_does_not_touch_different_account_email(): void
    {
        Event::fake([BexSessionDeleted::class]);

        $user = User::factory()->create();
        $otherAccountStale = $this->makeBexSession($user, [
            'account_email' => 'colleague@verbleif.com',
            'expired_at' => now()->subDay(),
            'last_validated_at' => now()->subDay(),
        ]);
        $orphan = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
        ]);
        $fresh = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);

        $this->artisan('bex:prune-stale-sessions --force')->assertSuccessful();

        $this->assertDatabaseMissing('bex_sessions', ['id' => $orphan->id]);
        $this->assertDatabaseHas('bex_sessions', [
            'id' => $otherAccountStale->id,
            'account_email' => 'colleague@verbleif.com',
        ]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $fresh->id]);
    }

    public function test_no_fresh_anchor_leaves_bucket_untouched(): void
    {
        $user = User::factory()->create();
        // Both rows expired — no anchor, so the prune can't decide.
        $a = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
        ]);
        $b = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => now()->subMinutes(5),
            'last_validated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('bex:prune-stale-sessions --force')
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();

        $this->assertDatabaseHas('bex_sessions', ['id' => $a->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $b->id]);
    }

    public function test_multiple_empty_email_rows_are_skipped_with_a_warning(): void
    {
        // Fresh anchor exists — but the bucket also has TWO empty-email
        // expired rows. We should still prune them (they're orphans),
        // and they're prunable individually because email='' matches
        // the "absorb" rule. (The opposite scenario — two fresh rows
        // with conflicting emails — is what the warning guard is for;
        // exercise that explicitly below.)
        $user = User::factory()->create();
        $orphanA = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subDays(2),
        ]);
        $orphanB = $this->makeBexSession($user, [
            'account_email' => null,
            'expired_at' => now()->subDay(),
        ]);
        $fresh = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);

        $this->artisan('bex:prune-stale-sessions --force')->assertSuccessful();

        $this->assertDatabaseMissing('bex_sessions', ['id' => $orphanA->id]);
        $this->assertDatabaseMissing('bex_sessions', ['id' => $orphanB->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $fresh->id]);
    }

    public function test_conflicting_fresh_rows_are_left_untouched_with_warning(): void
    {
        // Two fresh rows in one bucket with different emails — the
        // service has no authoritative anchor and bails out. Without
        // this guard a stale empty-email row could get attributed to
        // the wrong active account.
        $user = User::factory()->create();
        $freshA = $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);
        $freshB = $this->makeBexSession($user, [
            'account_email' => 'colleague@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(1),
        ]);
        $orphan = $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subDay(),
        ]);

        $this->artisan('bex:prune-stale-sessions --force')->assertSuccessful();

        $this->assertDatabaseHas('bex_sessions', ['id' => $freshA->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $freshB->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $orphan->id]);
    }

    public function test_endpoint_only_prunes_authed_users_rows(): void
    {
        Event::fake([BexSessionDeleted::class]);

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceOrphan = $this->makeBexSession($alice, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
        ]);
        $aliceFresh = $this->makeBexSession($alice, [
            'account_email' => 'alice@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);
        $bobOrphan = $this->makeBexSession($bob, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
        ]);
        $bobFresh = $this->makeBexSession($bob, [
            'account_email' => 'bob@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($alice)
            ->deleteJson('/authenticate/stale-sessions');

        $response->assertOk();
        $response->assertJson(['deleted_count' => 1]);

        $this->assertDatabaseMissing('bex_sessions', ['id' => $aliceOrphan->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $aliceFresh->id]);
        // Bob's rows must be untouched.
        $this->assertDatabaseHas('bex_sessions', ['id' => $bobOrphan->id]);
        $this->assertDatabaseHas('bex_sessions', ['id' => $bobFresh->id]);

        Event::assertDispatched(
            BexSessionDeleted::class,
            fn (BexSessionDeleted $e) => $e->bexSessionId === $aliceOrphan->id && $e->userId === $alice->id,
        );
    }

    public function test_authenticate_index_props_include_prunable_count(): void
    {
        $user = User::factory()->create();
        $this->makeBexSession($user, [
            'account_email' => 'sherin@verbleif.com',
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(2),
        ]);
        $this->makeBexSession($user, [
            'account_email' => '',
            'expired_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get('/authenticate')
            ->assertInertia(fn ($page) => $page->component('Authenticate/Index')
                ->where('prunable_count', 1));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBexSession(User $user, array $overrides = []): BexSession
    {
        $session = BexSession::create(array_merge([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_BookingExperts_session', 'value' => 'sample'],
            ])),
            'account_email' => 'placeholder@example.com',
            'account_name' => null,
            'captured_at' => now()->subMinutes(10),
            'last_validated_at' => null,
            'expired_at' => null,
        ], $overrides));

        // Eloquent's mass-assignment guard occasionally drops nullable
        // values (e.g. account_email='' vs null distinction) — restore
        // them explicitly so the test bucket reflects what the
        // operator actually has on prod.
        if (array_key_exists('account_email', $overrides)) {
            $session->account_email = $overrides['account_email'];
            $session->save();
        }
        if (array_key_exists('expired_at', $overrides)) {
            $session->expired_at = $overrides['expired_at'];
            $session->save();
        }
        if (array_key_exists('last_validated_at', $overrides)) {
            $session->last_validated_at = $overrides['last_validated_at'];
            $session->save();
        }

        return $session->fresh();
    }
}
