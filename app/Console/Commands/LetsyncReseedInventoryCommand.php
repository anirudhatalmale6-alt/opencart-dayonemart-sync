<?php

namespace App\Console\Commands;

use App\Enums\StockMovementType;
use App\Models\Hub;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Item;
use App\Models\ItemStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reset DayOneMart hub inventory.
 *
 * Default (zero mode): sets every item's hub stock to ZERO (tracked) and removes
 * the letsync-created opening-balance movements, so DayOneMart manages stock
 * fully on its own via purchase orders and delivery. OpenCart quantities are not
 * imported.
 *
 * --from-opencart: legacy mode — seeds each hub stock from the current OpenCart
 * quantity and records an OPENING_BALANCE movement.
 */
class LetsyncReseedInventoryCommand extends Command
{
    protected $signature = 'letsync:reseed-inventory
        {--hub=0 : Restrict to a single hub id (0 = all active hubs)}
        {--from-opencart : Seed hub stock from OpenCart quantities instead of zero}
        {--reason= : Movement reason label (opencart mode only)}';

    protected $description = 'Reset DayOneMart hub inventory to zero (default) or seed from OpenCart (--from-opencart).';

    public function handle(): int
    {
        $hubIds = ((int) $this->option('hub')) > 0
            ? [(int) $this->option('hub')]
            : Hub::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();

        if ($hubIds === []) {
            $this->error('No active hubs found.');

            return self::FAILURE;
        }

        return $this->option('from-opencart')
            ? $this->seedFromOpenCart($hubIds)
            : $this->resetToZero($hubIds);
    }

    private function resetToZero(array $hubIds): int
    {
        $items = Item::query()->whereNotNull('external_id')->get(['id']);
        $total = $items->count();
        $this->info("Resetting {$total} items to ZERO stock across hubs [" . implode(', ', $hubIds) . ']...');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $failures = 0;

        foreach ($items as $item) {
            try {
                DB::transaction(function () use ($item, $hubIds): void {
                    ItemStock::updateOrCreate(
                        ['item_id' => $item->id],
                        ['quantity' => 0, 'stock_type' => 'out_of_stock', 'is_limited_stock' => true]
                    );

                    foreach ($hubIds as $hubId) {
                        // Drop the OpenCart-derived opening balances we created earlier.
                        InventoryMovement::where('item_id', $item->id)
                            ->where('hub_id', $hubId)
                            ->where('variant_key', '')
                            ->where('reference_type', 'letsync')
                            ->delete();

                        InventoryStock::updateOrCreate(
                            ['item_id' => $item->id, 'hub_id' => $hubId, 'variant_key' => ''],
                            ['quantity' => 0, 'reserved_quantity' => 0, 'is_limited_stock' => true]
                        );
                    }
                });
                $done++;
            } catch (Throwable $exception) {
                $failures++;
                $this->newLine();
                $this->warn("item #{$item->id} failed: " . $exception->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done (zero mode). reset={$done}, failed={$failures}.");

        return self::SUCCESS;
    }

    private function seedFromOpenCart(array $hubIds): int
    {
        $reason = (string) ($this->option('reason') ?: 'Opening balance imported from OpenCart');

        $this->info('Reading OpenCart quantities...');
        $ocProducts = DB::connection('opencart')->table('product')
            ->get(['product_id', 'quantity', 'subtract'])
            ->keyBy('product_id');
        $this->info('OpenCart products: ' . $ocProducts->count());

        $items = Item::query()->whereNotNull('external_id')->get(['id', 'external_id']);
        $total = $items->count();
        $this->info("Seeding {$total} items from OpenCart across hubs [" . implode(', ', $hubIds) . ']...');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $seeded = 0;
        $missing = 0;
        $failures = 0;

        foreach ($items as $item) {
            $oc = $ocProducts->get($item->external_id);
            if ($oc === null) {
                $missing++;
                $bar->advance();
                continue;
            }

            $quantity = max(0, (int) $oc->quantity);
            $isLimited = (int) $oc->subtract === 1;

            try {
                DB::transaction(function () use ($item, $hubIds, $quantity, $isLimited, $reason): void {
                    ItemStock::updateOrCreate(
                        ['item_id' => $item->id],
                        [
                            'quantity' => $quantity,
                            'stock_type' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
                            'is_limited_stock' => $isLimited,
                        ]
                    );

                    foreach ($hubIds as $hubId) {
                        InventoryMovement::where('item_id', $item->id)
                            ->where('hub_id', $hubId)
                            ->where('variant_key', '')
                            ->delete();

                        InventoryStock::updateOrCreate(
                            ['item_id' => $item->id, 'hub_id' => $hubId, 'variant_key' => ''],
                            ['quantity' => $quantity, 'reserved_quantity' => 0, 'is_limited_stock' => $isLimited]
                        );

                        InventoryMovement::create([
                            'item_id' => $item->id,
                            'variant_key' => '',
                            'hub_id' => $hubId,
                            'type' => StockMovementType::OPENING_BALANCE,
                            'quantity_change' => $quantity,
                            'balance_after' => $quantity,
                            'reference_type' => 'letsync',
                            'reference_id' => null,
                            'reason' => $reason,
                            'user_id' => null,
                        ]);
                    }
                });
                $seeded++;
            } catch (Throwable $exception) {
                $failures++;
                $this->newLine();
                $this->warn("item #{$item->id} (oc {$item->external_id}) failed: " . $exception->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done (opencart mode). seeded={$seeded}, no-oc-match={$missing}, failed={$failures}.");

        return self::SUCCESS;
    }
}
