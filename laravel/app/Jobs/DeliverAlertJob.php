<?php

namespace App\Jobs;

use App\Models\AlertChannel;
use App\Models\AlertDelivery;
use App\Services\AlertDelivery\AlertPayloadBuilder;
use App\Services\AlertDelivery\DriverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Self-contained delivery job. Idempotent w.r.t. the audit row: every
 * dispatch creates the AlertDelivery row in `queued` status, then the
 * job swings the row to `sent` / `failed` once the driver call
 * returns.
 *
 * Self-contained because `php artisan queue:work` runs in a separate
 * container that cannot see closures from the dispatching process.
 * The job carries IDs (not full models) — Laravel's
 * `SerializesModels` would do that anyway but being explicit means a
 * model deletion between dispatch and execution surfaces as a clean
 * NotFound rather than an unserialise blowup.
 *
 * Retries: leaves the queue retry policy at Laravel's default (3
 * attempts, exponential backoff configured in `config/queue.php`).
 * On the final failure the audit row keeps `attempts > 0` and the
 * operator sees it in the /alerts UI's "Recent failures" panel.
 */
class DeliverAlertJob implements ShouldQueue
{
    use FoundationQueueable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     *                                        Pre-rendered driver-agnostic payload (see
     *                                        {@see AlertPayloadBuilder}).
     */
    public function __construct(
        public int $alertChannelId,
        public ?int $savedQueryId,
        public ?int $logMessageId,
        public array $payload,
    ) {}

    public function handle(DriverFactory $factory): void
    {
        $channel = AlertChannel::query()->find($this->alertChannelId);
        if (! $channel) {
            // The channel was deleted between dispatch and execution.
            // Don't crash the queue worker — just log and exit. The
            // audit row was never created (we create it inside this
            // method) so there's nothing to update.
            Log::info('DeliverAlertJob: channel disappeared, skipping', [
                'alert_channel_id' => $this->alertChannelId,
                'saved_query_id' => $this->savedQueryId,
            ]);

            return;
        }

        if (! $channel->enabled) {
            Log::info('DeliverAlertJob: channel disabled, skipping', [
                'alert_channel_id' => $channel->id,
            ]);

            return;
        }

        $delivery = AlertDelivery::query()->create([
            'saved_query_id' => $this->savedQueryId,
            'alert_channel_id' => $channel->id,
            'log_message_id' => $this->logMessageId,
            'payload' => $this->payload,
            'status' => AlertDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        try {
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            $factory->for($channel)->send($channel, $this->payload);

            $delivery->update(['status' => AlertDelivery::STATUS_SENT, 'error' => null]);
        } catch (Throwable $e) {
            $delivery->update([
                'status' => AlertDelivery::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::warning('DeliverAlertJob: delivery failed', [
                'alert_channel_id' => $channel->id,
                'saved_query_id' => $this->savedQueryId,
                'kind' => $channel->kind,
                'error' => $e->getMessage(),
            ]);

            // Re-throw so Laravel's queue runner records the attempt
            // and applies its retry policy. The audit row above
            // already captured the user-facing trail — re-throwing
            // adds the framework-level retry signal without mucking
            // with the user-visible failure count.
            throw $e;
        }
    }

    /**
     * Hint to the queue: the title field is short and operator-readable,
     * so it ends up in `php artisan queue:listen` output. Makes
     * paging / observability cheaper without exposing secrets.
     */
    public function displayName(): string
    {
        $title = (string) ($this->payload['title'] ?? 'BexLogs alert');

        return "DeliverAlertJob[{$title}]";
    }
}
