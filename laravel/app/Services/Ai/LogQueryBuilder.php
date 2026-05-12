<?php

namespace App\Services\Ai;

use App\Models\LogMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the log filter contract that powers both
 * the operator-facing /logs/{page} screen and the AI agent's
 * search_logs / count_logs / aggregate_by tools. Extracted from the
 * previous private PageController::applyFilters; semantics are intentionally
 * unchanged so the existing Vue UI stays pixel-identical and the agent
 * can never see rows the human-facing page wouldn't.
 *
 * NOTE on cold-tier read-through: PageController still owns the merge
 * with App\Services\ColdLogReader because the merge is paginator-shaped
 * (LengthAwarePaginator) rather than query-builder-shaped. The agent's
 * MVP is hot-only by design (see plan §9). When/if a cold fallback is
 * wanted for the agent, the right hook is here — call ColdLogReader in
 * SearchLogsTool / CountLogsTool after the hot result, rather than
 * inside the builder, so we don't break the pure-builder contract.
 * TODO(log-agent): cold-tier integration for agent tools is non-trivial
 * (it would need to push filters through ColdLogReader::fetchRowsForRange);
 * leave hot-only for MVP.
 */
class LogQueryBuilder
{
    /**
     * @param  Builder<LogMessage>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<LogMessage>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['startDate'])) {
            $query->where('timestamp', '>=', $filters['startDate']);
        }
        if (! empty($filters['endDate'])) {
            $query->where('timestamp', '<=', $filters['endDate']);
        }
        foreach (['type', 'action', 'method', 'status'] as $col) {
            if (! empty($filters[$col])) {
                $query->where($col, $filters[$col]);
            }
        }

        // Entity = first whitespace-separated token of action. Postgres ILIKE
        // gives case-insensitive prefix match without rebuilding an index.
        if (! empty($filters['entity'])) {
            $entity = $filters['entity'];
            $query->where(function ($w) use ($entity) {
                $w->where('action', 'ILIKE', $entity.' %')
                    ->orWhere('action', 'ILIKE', $entity);
            });
        }

        // Free-text search across action / path / method / json bodies. The
        // user's needle has its SQL LIKE wildcards (`%` and `_`) escaped so
        // a search for an entity id like `26205663` doesn't get reinterpreted
        // as a wildcard pattern.
        if (! empty($filters['q'])) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($w) use ($needle) {
                $w->where('action', 'ILIKE', $needle)
                    ->orWhere('path', 'ILIKE', $needle)
                    ->orWhere('method', 'ILIKE', $needle)
                    ->orWhereRaw('parameters::text ILIKE ?', [$needle])
                    ->orWhereRaw('request::text ILIKE ?', [$needle])
                    ->orWhereRaw('response::text ILIKE ?', [$needle]);
            });
        }

        if (! empty($filters['jsonFilters'])) {
            foreach ($filters['jsonFilters'] as $jf) {
                $field = $jf['field'];
                $value = $jf['value'];
                $query->where(function ($q) use ($field, $value) {
                    foreach (['parameters', 'request', 'response'] as $col) {
                        $q->orWhereRaw("$col::text ILIKE ?", ["%\"$field\":%$value%"]);
                    }
                });
            }
        }

        return $query;
    }

    /**
     * Apply filter, sort, and ordering tiebreakers in one shot. Used by the
     * agent's read tools so callers don't have to remember the secondary
     * (timestamp desc, id desc) order PageController has always paired with
     * the user-selected sort column.
     *
     * @param  Builder<LogMessage>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applySorted(
        Builder $query,
        array $filters,
        string $orderBy = 'timestamp',
        string $direction = 'desc',
    ): Builder {
        $this->applyFilters($query, $filters);

        $allowedColumns = ['timestamp', 'type', 'action', 'method', 'status'];
        if (! in_array($orderBy, $allowedColumns, true)) {
            $orderBy = 'timestamp';
        }
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($orderBy, $direction)
            ->orderByDesc('timestamp')
            ->orderByDesc('id');
    }
}
