<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'user_id', 'name'])]
// Explicit `uriTemplate` on each operation so route registration doesn't
// rely on api-platform introspecting the DB schema at boot time. In test
// environments with `RefreshDatabase` the routes are registered before
// the migrations run, which collapses the auto-derived Get URI to
// `/organizations{._format}` (no `/{id}`). Pinning the URI here means
// the api routes are stable in any environment.
#[ApiResource(
    shortName: 'Organization',
    operations: [
        new GetCollection(uriTemplate: '/organizations{._format}'),
        new Get(uriTemplate: '/organizations/{id}{._format}'),
    ],
)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
