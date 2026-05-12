<?php

namespace App\Services\Ai\Tools;

/**
 * Shared LLM-args -> LogQueryBuilder-filters coercion. The five
 * tools accept the same human-friendly filter shape (q / type /
 * action / method / status / startDate / endDate / jsonFilters);
 * this helper normalises whatever the LLM emitted into the array
 * shape LogQueryBuilder::applyFilters wants.
 */
final class ToolFilters
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function fromArgs(array $args): array
    {
        $filters = [];
        foreach (['q', 'type', 'action', 'method', 'status', 'startDate', 'endDate'] as $key) {
            if (isset($args[$key]) && is_string($args[$key]) && $args[$key] !== '') {
                $filters[$key] = $args[$key];
            }
        }

        if (isset($args['jsonFilters']) && is_array($args['jsonFilters'])) {
            $clean = [];
            foreach ($args['jsonFilters'] as $jf) {
                if (! is_array($jf) || ! isset($jf['field'], $jf['value'])) {
                    continue;
                }
                $field = (string) $jf['field'];
                $value = (string) $jf['value'];
                if ($field === '' || $value === '') {
                    continue;
                }
                $clean[] = ['field' => $field, 'value' => $value];
            }
            if ($clean !== []) {
                $filters['jsonFilters'] = $clean;
            }
        }

        return $filters;
    }
}
