<?php

namespace App\Services\Letsync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncLogger
{
    public function success(string $entity, string $event, ?int $externalId, ?int $localId, int $durationMs, string $message = 'OK'): void
    {
        $this->write($entity, $event, $externalId, 'success', $localId, $message, $durationMs);
    }

    public function error(string $entity, string $event, ?int $externalId, string $message, int $durationMs = 0): void
    {
        $this->write($entity, $event, $externalId, 'error', null, $message, $durationMs);
        Log::channel('stack')->error("letsync {$entity} {$event} #{$externalId}: {$message}");
    }

    public function skipped(string $entity, string $event, ?int $externalId, string $message): void
    {
        $this->write($entity, $event, $externalId, 'skipped', null, $message, 0);
    }

    private function write(string $entity, string $event, ?int $externalId, string $status, ?int $localId, string $message, int $durationMs): void
    {
        DB::table('letsync_logs')->insert([
            'entity' => $entity,
            'external_id' => $externalId,
            'event' => $event,
            'status' => $status,
            'local_id' => $localId,
            'message' => mb_substr($message, 0, 2000),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
