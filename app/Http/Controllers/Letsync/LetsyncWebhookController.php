<?php

namespace App\Http\Controllers\Letsync;

use App\Http\Controllers\Controller;
use App\Jobs\Letsync\SyncCategoryJob;
use App\Jobs\Letsync\SyncCustomerJob;
use App\Jobs\Letsync\SyncOrderJob;
use App\Jobs\Letsync\SyncProductJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetsyncWebhookController extends Controller
{
    public function product(Request $request): JsonResponse
    {
        return $this->dispatchJob($request, fn (string $event, int $id) => SyncProductJob::dispatch($event, $id));
    }

    public function category(Request $request): JsonResponse
    {
        return $this->dispatchJob($request, fn (string $event, int $id) => SyncCategoryJob::dispatch($event, $id));
    }

    public function customer(Request $request): JsonResponse
    {
        return $this->dispatchJob($request, fn (string $event, int $id) => SyncCustomerJob::dispatch($event, $id));
    }

    public function order(Request $request): JsonResponse
    {
        return $this->dispatchJob($request, fn (string $event, int $id) => SyncOrderJob::dispatch($event, $id));
    }

    private function dispatchJob(Request $request, callable $dispatcher): JsonResponse
    {
        $event = (string) ($request->input('event') ?: $request->header('X-Letsync-Event', ''));
        $externalId = (int) $request->input('id');

        if ($event === '' || $externalId <= 0) {
            return response()->json(['status' => false, 'message' => 'Invalid payload: event and id are required'], 422);
        }

        $dispatcher($event, $externalId);

        return response()->json(['status' => true, 'message' => 'Queued'], 202);
    }
}
