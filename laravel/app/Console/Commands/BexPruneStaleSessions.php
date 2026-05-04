<?php

namespace App\Console\Commands;

use App\Services\BexSessionPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Prune redundant BexSession rows. Defaults to dry-run so a casual
 * `php artisan bex:prune-stale-sessions` is safe — pass `--force` to
 * actually delete, and `--user=ID` to limit the scope (the prod
 * operator usually only needs to run this against their own user_id).
 *
 * The matching rules live in {@see BexSessionPruner}; this command is a
 * thin CLI wrapper that prints the plan in tabular form so the
 * operator can sanity-check what's about to disappear.
 */
class BexPruneStaleSessions extends Command
{
    protected $signature = 'bex:prune-stale-sessions
                            {--force : Apply the plan; without this flag the command runs as a dry-run.}
                            {--user= : Limit the prune to a single user_id.}
                            {--no-broadcast : Skip the BexSessionDeleted broadcast (only meaningful with --force).}';

    protected $description = 'Detect and (optionally) delete stale BexSession orphan rows left behind by older relink semantics.';

    public function handle(BexSessionPruner $pruner): int
    {
        $force = (bool) $this->option('force');
        $userOption = $this->option('user');
        $userId = $userOption === null ? null : (int) $userOption;
        $broadcast = ! $this->option('no-broadcast');

        $result = $pruner->prune(
            dryRun: ! $force,
            broadcast: $force && $broadcast,
            userId: $userId,
        );

        $this->logPlan($result, dryRun: ! $force);
        $this->renderPlan($result, dryRun: ! $force);

        if (! $force && $result['plans'] !== []) {
            $this->newLine();
            $this->comment('Dry-run only. Re-run with --force to delete the rows above.');
        }

        return self::SUCCESS;
    }

    /**
     * Pretty-print the plan as a table: one block per (user, env)
     * bucket showing the anchor we'd keep and the rows we'd nuke.
     *
     * @param  array<string,mixed>  $result
     */
    private function renderPlan(array $result, bool $dryRun): void
    {
        $verb = $dryRun ? 'Would delete' : 'Deleted';

        $this->info(sprintf('Inspected %d BexSession row(s).', $result['inspected_count']));

        if ($result['plans'] === []) {
            $this->info('Nothing to prune.');

            return;
        }

        $this->info(sprintf(
            '%s %d row(s) across %d bucket(s).',
            $verb,
            $result['deleted_count'] ?: $this->countPlanned($result['plans']),
            count($result['plans']),
        ));

        foreach ($result['plans'] as $plan) {
            $this->newLine();
            $this->line(sprintf(
                '<fg=cyan>user_id=%d environment=%s</>',
                $plan['user_id'],
                $plan['environment'],
            ));

            $anchor = $plan['fresh_row'];
            $this->line(sprintf(
                '  keep  id=%-4d email=%s  last_validated_at=%s',
                $anchor['id'],
                $anchor['account_email'] ?: '<empty>',
                $anchor['last_validated_at'] ?? 'never',
            ));

            foreach ($plan['deleted'] as $row) {
                $this->line(sprintf(
                    '  <fg=red>drop  id=%-4d email=%s  expired_at=%s</>',
                    $row['id'],
                    $row['account_email'] === null || $row['account_email'] === ''
                        ? '<empty>'
                        : $row['account_email'],
                    $row['expired_at'] ?? 'never',
                ));
            }

            foreach ($plan['skipped'] as $row) {
                $this->line(sprintf(
                    '  <fg=yellow>skip  id=%-4d email=%s  reason=%s</>',
                    $row['id'],
                    $row['account_email'] ?: '<empty>',
                    $row['reason'],
                ));
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     */
    private function countPlanned(array $plans): int
    {
        $count = 0;
        foreach ($plans as $plan) {
            $count += count($plan['deleted']);
        }

        return $count;
    }

    /**
     * Mirror the printed plan into the Laravel log so an operator who
     * fires the prune from cron / a one-shot SSH session has an audit
     * trail without having to capture stdout.
     *
     * @param  array<string,mixed>  $result
     */
    private function logPlan(array $result, bool $dryRun): void
    {
        Log::info('bex prune: '.($dryRun ? 'planned' : 'executed'), [
            'dry_run' => $dryRun,
            'inspected' => $result['inspected_count'],
            'deleted' => $result['deleted_count'],
            'plans' => $result['plans'],
        ]);
    }
}
