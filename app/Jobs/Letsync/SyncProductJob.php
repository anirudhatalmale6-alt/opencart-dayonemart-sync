<?php

namespace App\Jobs\Letsync;

use App\Services\Letsync\ProductSyncService;
use App\Services\Letsync\SyncLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(
        public readonly string $event,
        public readonly int $externalId,
    ) {
        $this->onConnection('database')->onQueue(config('letsync.queue', 'letsync'));
    }

    public function handle(ProductSyncService $service): void
    {
        if ($this->event === 'delete_product') {
            $service->deleteByExternalId($this->externalId, $this->event);

            return;
        }

        $service->syncById($this->externalId, $this->event);
    }

    public function failed(Throwable $exception): void
    {
        app(SyncLogger::class)->error('product', $this->event, $this->externalId, $exception->getMessage());
    }
}
