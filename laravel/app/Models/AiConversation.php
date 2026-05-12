<?php

namespace App\Models;

use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per "ask your logs" chat session, scoped to a single
 * (user, subscription) pair. The agent's tool dispatcher reads
 * `subscription_id` from this row (NEVER from LLM input) and pins it
 * into every WHERE clause; reopening a conversation requires both keys
 * to still match the requesting user via the Subscription -> Application
 * -> Organization -> user_id walk.
 */
#[Fillable(['user_id', 'subscription_id', 'title', 'model'])]
class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<AiMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Limit a query to conversations whose subscription is owned by the
     * given user via the canonical Subscription -> Application ->
     * Organization -> user_id walk. Mirrors the ownership check used by
     * PageController / ChatController so the agent never sees a row a
     * direct page visit wouldn't.
     *
     * @param  Builder<AiConversation>  $query
     * @return Builder<AiConversation>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query
            ->where('ai_conversations.user_id', $user->id)
            ->whereExists(function ($q) use ($user) {
                $q->selectRaw('1')
                    ->from('subscriptions')
                    ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
                    ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
                    ->whereColumn('subscriptions.id', 'ai_conversations.subscription_id')
                    ->where('organizations.user_id', $user->id);
            });
    }
}
