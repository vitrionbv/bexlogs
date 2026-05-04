<?php

namespace App\Services;

use App\Events\BexSessionDeleted;
use App\Models\BexSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Prune redundant BexSession rows left behind by older relink semantics.
 *
 * Background — and the only reason this service exists:
 *   The previous relink path (BexSessionController::store before
 *   commit 2a2d201) always INSERTed a new row, leaving the original
 *   stale row sitting next to the fresh one. Even after that fix,
 *   pre-existing rows captured before the email-extractor worked have
 *   `account_email = ''`, and the new relink path's
 *   (user_id, environment, account_email) match key skips them. Those
 *   rows show up in the operator's Sessions list as ghost cards with
 *   no email, no obvious owner, and a confusingly fresh
 *   last_validated_at (because the hourly validator keeps probing
 *   them).
 *
 * This service identifies and (optionally) deletes the orphans, with a
 * conservative match key:
 *
 *   For each (user_id, environment) bucket:
 *     - Find the "fresh" row: expired_at IS NULL, account_email
 *       non-empty, last_validated_at within the last 24h. Plural fresh
 *       rows are tolerated as long as they share the same email.
 *     - A "stale" row in that bucket is prunable iff:
 *         expired_at IS NOT NULL
 *         AND (
 *           account_email IS NULL OR account_email = ''
 *             OR account_email = <fresh row's email>
 *         )
 *
 * What is NOT prunable:
 *   - Stale rows with a *different* non-empty account_email — that's a
 *     legitimate "user logged into BookingExperts as someone else"
 *     scenario and the operator may want to retain that history.
 *   - Buckets with multiple fresh rows that disagree on email — leave
 *     them alone and log a warning so the operator can investigate.
 *   - Buckets with no fresh row — without an authoritative anchor we
 *     can't decide which (if any) of the stale rows is the orphan.
 *
 * The dispatched BexSessionDeleted event lets any open Authenticate
 * page refresh live without polling.
 */
class BexSessionPruner
{
    /**
     * @param  bool  $dryRun  When true, report the plan but don't delete.
     * @param  bool  $broadcast  When true and not dry-run, dispatch a
     *                           BexSessionDeleted event for each removed
     *                           row so open clients refresh.
     * @return array{
     *   plans: list<array{
     *     user_id:int, environment:string,
     *     fresh_row:?array{id:int,account_email:?string,last_validated_at:?string},
     *     deleted:list<array{id:int,account_email:?string,expired_at:?string}>,
     *     skipped:list<array{id:int,account_email:?string,reason:string}>,
     *   }>,
     *   deleted_count:int,
     *   inspected_count:int,
     * }
     */
    public function prune(bool $dryRun = true, bool $broadcast = false, ?int $userId = null): array
    {
        $now = Carbon::now();

        $query = BexSession::query()->orderBy('user_id')->orderBy('environment')->orderBy('id');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $rows = $query->get();
        $byBucket = $rows->groupBy(fn (BexSession $s) => $s->user_id.'|'.$s->environment);

        $plans = [];
        $deletedCount = 0;
        $inspectedCount = $rows->count();

        foreach ($byBucket as $bucketKey => $bucket) {
            [$userIdStr, $environment] = explode('|', $bucketKey, 2);
            $bucketUserId = (int) $userIdStr;

            $plan = $this->planForBucket($bucket, $now);
            if ($plan === null) {
                continue;
            }

            $plan = [
                'user_id' => $bucketUserId,
                'environment' => $environment,
            ] + $plan;

            $plans[] = $plan;

            if ($dryRun) {
                continue;
            }

            foreach ($plan['deleted'] as $row) {
                $session = BexSession::find($row['id']);
                if (! $session) {
                    continue;
                }
                $deletedId = $session->id;
                $deletedUserId = (int) $session->user_id;
                $deletedEnv = (string) $session->environment;
                $deletedEmail = $session->account_email;
                $session->delete();
                $deletedCount++;

                if ($broadcast) {
                    broadcast(new BexSessionDeleted(
                        userId: $deletedUserId,
                        bexSessionId: $deletedId,
                        environment: $deletedEnv,
                        accountEmail: $deletedEmail,
                        reason: 'orphan_pruned',
                    ));
                }
            }
        }

        return [
            'plans' => $plans,
            'deleted_count' => $deletedCount,
            'inspected_count' => $inspectedCount,
        ];
    }

    /**
     * Convenience entry point used by the Authenticate page's "Delete
     * expired sessions" button. Always non-dry-run, always broadcasts,
     * scoped to one user.
     *
     * @return array{
     *   deleted_count:int,
     *   plans:list<array<string,mixed>>,
     * }
     */
    public function pruneForUser(User $user): array
    {
        $result = $this->prune(dryRun: false, broadcast: true, userId: $user->id);

        return [
            'deleted_count' => $result['deleted_count'],
            'plans' => $result['plans'],
        ];
    }

    /**
     * Build the plan for a single (user_id, environment) bucket.
     * Returns null when nothing is prunable in this bucket (no fresh
     * anchor, or no stale rows that match the orphan signature).
     *
     * @param  Collection<int, BexSession>  $bucket
     * @return null|array{
     *   fresh_row:?array{id:int,account_email:?string,last_validated_at:?string},
     *   deleted:list<array{id:int,account_email:?string,expired_at:?string}>,
     *   skipped:list<array{id:int,account_email:?string,reason:string}>,
     * }
     */
    private function planForBucket(Collection $bucket, Carbon $now): ?array
    {
        $freshAnchor = $this->resolveFreshAnchor($bucket, $now);

        $skipped = [];
        $deleted = [];

        if ($freshAnchor === null) {
            return null;
        }

        if ($freshAnchor['conflict']) {
            Log::warning('bex prune: ambiguous fresh rows in bucket; leaving stale rows untouched', [
                'user_id' => $freshAnchor['fresh_row']['user_id'] ?? null,
                'environment' => $freshAnchor['fresh_row']['environment'] ?? null,
                'fresh_emails' => $freshAnchor['conflict_emails'],
            ]);

            return null;
        }

        $anchorEmail = $freshAnchor['fresh_row']['account_email'];
        $anchorIds = $freshAnchor['fresh_ids'];

        foreach ($bucket as $row) {
            if (in_array($row->id, $anchorIds, true)) {
                continue;
            }
            if ($row->expired_at === null) {
                $skipped[] = [
                    'id' => (int) $row->id,
                    'account_email' => $row->account_email,
                    'reason' => 'fresh_row_not_anchor',
                ];

                continue;
            }

            $rowEmail = (string) ($row->account_email ?? '');

            if ($rowEmail === '' || $rowEmail === (string) $anchorEmail) {
                $deleted[] = [
                    'id' => (int) $row->id,
                    'account_email' => $row->account_email,
                    'expired_at' => $row->expired_at?->toIso8601String(),
                ];

                continue;
            }

            $skipped[] = [
                'id' => (int) $row->id,
                'account_email' => $row->account_email,
                'reason' => 'different_account_email',
            ];
        }

        if ($deleted === [] && $skipped === []) {
            return null;
        }

        return [
            'fresh_row' => $freshAnchor['fresh_row'],
            'deleted' => $deleted,
            'skipped' => $skipped,
        ];
    }

    /**
     * Pick the bucket's fresh anchor row. The anchor is "the row we'd
     * keep if we deleted everything else". Returns null when no row
     * meets the freshness bar; sets `conflict=true` when multiple
     * candidate fresh rows disagree on `account_email`.
     *
     * @param  Collection<int, BexSession>  $bucket
     * @return null|array{
     *   fresh_row:array{id:int,user_id:int,environment:string,account_email:?string,last_validated_at:?string},
     *   fresh_ids:list<int>,
     *   conflict:bool,
     *   conflict_emails:list<string>,
     * }
     */
    private function resolveFreshAnchor(Collection $bucket, Carbon $now): ?array
    {
        $cutoff = $now->copy()->subDay();

        $candidates = $bucket->filter(fn (BexSession $s) => $s->expired_at === null
            && ! empty($s->account_email)
            && $s->last_validated_at !== null
            && $s->last_validated_at->greaterThanOrEqualTo($cutoff)
        )->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $emails = $candidates->pluck('account_email')
            ->map(fn ($e) => (string) $e)
            ->unique()
            ->values()
            ->all();

        if (count($emails) > 1) {
            return [
                'fresh_row' => $this->describeFreshRow($candidates->first()),
                'fresh_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'conflict' => true,
                'conflict_emails' => $emails,
            ];
        }

        $latest = $candidates->sortByDesc('last_validated_at')->first();

        return [
            'fresh_row' => $this->describeFreshRow($latest),
            'fresh_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'conflict' => false,
            'conflict_emails' => [],
        ];
    }

    /**
     * @return array{id:int,user_id:int,environment:string,account_email:?string,last_validated_at:?string}
     */
    private function describeFreshRow(BexSession $row): array
    {
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'environment' => (string) $row->environment,
            'account_email' => $row->account_email,
            'last_validated_at' => $row->last_validated_at?->toIso8601String(),
        ];
    }
}
