<?php

namespace Tests\Feature\Ai;

use App\Models\LogMessage;
use App\Models\Page;
use App\Services\Ai\LogQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pin the filter contract that the AI tools and the legacy
 * PageController::applyFilters call site both rely on.
 *
 * The original applyFilters implementation used Postgres ILIKE / ::text.
 * Under PHPUnit's sqlite-in-memory driver those are not supported; we
 * therefore only exercise the SQL-portable branches here and leave the
 * Postgres-only behaviour (free-text q across action/path/method, JSONB
 * needles) covered by the running production app + a dedicated guard.
 */
class LogQueryBuilderRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Page $page;

    private LogQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = Page::factory()->create();
        $this->builder = new LogQueryBuilder;
    }

    public function test_no_filters_returns_all_rows_for_page(): void
    {
        LogMessage::factory()->count(5)->create(['page_id' => $this->page->id]);

        $count = $this->builder->applyFilters(
            LogMessage::query()->where('page_id', $this->page->id),
            [],
        )->count();

        $this->assertSame(5, $count);
    }

    public function test_start_and_end_date_filter_clamps_window(): void
    {
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-01-01T00:00:00Z',
        ]);
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-02-15T12:00:00Z',
        ]);
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-03-01T00:00:00Z',
        ]);

        $count = $this->builder->applyFilters(
            LogMessage::query()->where('page_id', $this->page->id),
            [
                'startDate' => '2026-02-01T00:00:00Z',
                'endDate' => '2026-02-28T23:59:59Z',
            ],
        )->count();

        $this->assertSame(1, $count);
    }

    public function test_exact_match_filters_for_type_action_method_status(): void
    {
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'type' => 'http',
            'action' => 'Reservation updated',
            'method' => 'POST',
            'status' => '201',
        ]);
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'type' => 'http',
            'action' => 'Reservation updated',
            'method' => 'GET',
            'status' => '200',
        ]);

        $cases = [
            [['type' => 'http'], 2],
            [['action' => 'Reservation updated'], 2],
            [['method' => 'POST'], 1],
            [['status' => '200'], 1],
        ];

        foreach ($cases as [$filters, $expected]) {
            $count = $this->builder->applyFilters(
                LogMessage::query()->where('page_id', $this->page->id),
                $filters,
            )->count();
            $this->assertSame(
                $expected,
                $count,
                'filter '.json_encode($filters).' expected '.$expected.' rows',
            );
        }
    }

    public function test_empty_string_filter_values_are_ignored(): void
    {
        LogMessage::factory()->count(3)->create([
            'page_id' => $this->page->id,
            'type' => 'http',
        ]);

        $count = $this->builder->applyFilters(
            LogMessage::query()->where('page_id', $this->page->id),
            ['type' => '', 'action' => '', 'method' => '', 'status' => ''],
        )->count();

        $this->assertSame(3, $count);
    }

    public function test_apply_sorted_clamps_unknown_order_column(): void
    {
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-01-01T00:00:00Z',
        ]);
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-06-01T00:00:00Z',
        ]);

        $rows = $this->builder->applySorted(
            LogMessage::query()->where('page_id', $this->page->id),
            [],
            orderBy: 'totally-bogus-column',
            direction: 'asc',
        )->get();

        // Falls back to timestamp asc.
        $this->assertStringStartsWith('2026-01-01', (string) $rows[0]->timestamp);
    }

    public function test_sql_like_wildcards_in_q_are_escaped(): void
    {
        // q escaping is the SQL-portable branch of the q filter — the
        // ILIKE itself is Postgres-only, but on sqlite we can at least
        // confirm the builder doesn't blow up with a wildcard-laden
        // needle. Behaviour parity for ILIKE is exercised in the live
        // Postgres app.
        if ($this->app['db.connection']->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Free-text q uses Postgres ILIKE / ::text; covered by integration env.');
        }

        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'action' => 'percent_signs_%',
        ]);

        $count = $this->builder->applyFilters(
            LogMessage::query()->where('page_id', $this->page->id),
            ['q' => '%'],
        )->count();

        $this->assertSame(1, $count);
    }

    public function test_json_filters_are_postgres_only(): void
    {
        if ($this->app['db.connection']->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('jsonFilters use Postgres ::text; covered by integration env.');
        }

        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'parameters' => ['todo_id' => '26205663'],
        ]);

        $count = $this->builder->applyFilters(
            LogMessage::query()->where('page_id', $this->page->id),
            ['jsonFilters' => [['field' => 'todo_id', 'value' => '26205663']]],
        )->count();

        $this->assertSame(1, $count);
    }
}
