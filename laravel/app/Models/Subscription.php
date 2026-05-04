<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'application_id',
    'name',
    'environment',
    'auto_scrape',
    'scrape_interval_minutes',
    'max_pages_per_scrape',
    'lookback_days_first_scrape',
    'max_duration_minutes',
    'max_concurrent_jobs',
    'job_spacing_minutes',
    'last_scraped_at',
])]
class Subscription extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Defaults applied to newly-instantiated (unsaved) Subscriptions. These
     * mirror the column defaults set in the
     * 2026_04_30_180100_add_scrape_budget_to_subscriptions and
     * 2026_05_04_205500_add_concurrency_fields_to_subscriptions migrations so
     * the application layer and the DB layer agree even when a Subscription
     * is created without explicit overrides. Existing rows are not affected;
     * this only seeds new instances.
     *
     * The (1, 10) concurrency defaults preserve the pre-concurrency
     * behaviour: at most one queued/running job per subscription, with a
     * 10-minute slot reservation so a follow-up scheduler tick can't pile
     * on while the first job is still spinning up.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'max_pages_per_scrape' => 200,
        'lookback_days_first_scrape' => 30,
        'max_duration_minutes' => 30,
        'max_concurrent_jobs' => 1,
        'job_spacing_minutes' => 10,
    ];

    protected function casts(): array
    {
        return [
            'auto_scrape' => 'boolean',
            'scrape_interval_minutes' => 'integer',
            'max_pages_per_scrape' => 'integer',
            'lookback_days_first_scrape' => 'integer',
            'max_duration_minutes' => 'integer',
            'max_concurrent_jobs' => 'integer',
            'job_spacing_minutes' => 'integer',
            'last_scraped_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function scrapeJobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class, 'subscription_id');
    }
}
