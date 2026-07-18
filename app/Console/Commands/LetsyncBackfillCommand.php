<?php

namespace App\Console\Commands;

use App\Services\Letsync\CategorySyncService;
use App\Services\Letsync\CustomerSyncService;
use App\Services\Letsync\OpenCartReader;
use App\Services\Letsync\OrderSyncService;
use App\Services\Letsync\ProductSyncService;
use Illuminate\Console\Command;
use Throwable;

class LetsyncBackfillCommand extends Command
{
    protected $signature = 'letsync:backfill
        {entity=all : categories|products|customers|orders|all}
        {--limit=0 : Max records per entity (0 = no limit)}';

    protected $description = 'Backfill existing OpenCart data into DayOneMart (idempotent).';

    public function handle(
        OpenCartReader $reader,
        CategorySyncService $categories,
        ProductSyncService $products,
        CustomerSyncService $customers,
        OrderSyncService $orders,
    ): int {
        $entity = (string) $this->argument('entity');
        $limit = (int) $this->option('limit');

        $plan = $entity === 'all'
            ? ['categories', 'products', 'customers', 'orders']
            : [$entity];

        foreach ($plan as $target) {
            match ($target) {
                'categories' => $this->backfillEntity('categories', $reader->allCategoryIds(), fn (int $id) => $categories->syncById($id, 'backfill'), $limit),
                'products' => $this->backfillEntity('products', $reader->allProductIds(), fn (int $id) => $products->syncById($id, 'backfill'), $limit),
                'customers' => $this->backfillEntity('customers', $reader->allCustomerIds(), fn (int $id) => $customers->syncById($id, 'backfill'), $limit),
                'orders' => $this->backfillEntity('orders', $reader->allOrderIds(), fn (int $id) => $orders->syncById($id, 'backfill'), $limit),
                default => $this->error("Unknown entity: {$target}"),
            };
        }

        return self::SUCCESS;
    }

    private function backfillEntity(string $label, array $ids, callable $sync, int $limit): void
    {
        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
        }

        $total = count($ids);
        $this->info("Backfilling {$total} {$label}...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $failures = 0;
        foreach ($ids as $id) {
            try {
                $sync((int) $id);
            } catch (Throwable $exception) {
                $failures++;
                $this->newLine();
                $this->warn("{$label} #{$id} failed: " . $exception->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done {$label}: {$total} processed, {$failures} failed.");
    }
}
