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
 * One-time re-seed of hub inventory from OpenCart quantities.
 *
 * Resets each item's hub stock to the current OpenCart quantity and records a
 * single OPENING_BALANCE movement, giving DayOneMart a clean starting point for
 * its per-hub stock and profitability reports. After this, DayOneMart owns the
 * stock (the real-time sync no longer overwrites hub quantities).
 */
class LetsyncReseedInventoryCommand extends Command
{
    protected $signature = 'letsync:reseed-inventory
        {--hub=0 : Restrict to a single hub id (0 = all active hubs)}
        {--reason= : Movement reason label}';

    protected $description = 'Re-seed DayOneMart hub inventory from OpenCart opening balances (one-time).';

    public function handle(): int
    {
        $reason = (string) ($this->option('reason') ?: 'Opening balance imported from OpenCart');

        $hubIds = ((int) $this->option('hub')) > 0
            ? [(int) $this->option('hub')]
            : Hub::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();

        if ($hubIds === []) {
            $this->error('No active hubs found.');

            return self::FAILURE;
        }

        $this->info('Reading OpenCart quantities...');
        $ocProducts = DB::connection('opencart')->table('product')
            ->get(['product_id', 'quantity', 'subtract'])
            ->keyBy('product_id');
        $this->info('OpenCart products: ' . $ocProducts->count());

        $items = Item::query()->whereNotNull('external_id')->get(['id', 'external_id']);
        $total = $items->count();
        $this->info("Re-seeding {$total} items across hubs [" . implode(', ', $hubIds) . ']...');

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
                    // Keep the master mirror aligned with the opening balance.
                    ItemStock::updateOrCreate(
                        ['item_id' => $item->id],
                        [
                            'quantity' => $quantity,
                            'stock_type' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
                            'is_limited_stock' => $isLimited,
                        ]
                    );

                    foreach ($hubIds as $hubId) {
                        // Fresh opening balance: clear prior movements for this hub stock.
                        InventoryMovement::where('item_id', $item->id)
                            ->where('hub_id', $hubId)
                            ->where('variant_key', '')
                            ->delete();

                        InventoryStock::updateOrCreate(
                            ['item_id' => $item->id, 'hub_id' => $hubId, 'variant_key' => ''],
                            [
                                'quantity' => $quantity,
                                'reserved_quantity' => 0,
                                'is_limited_stock' => $isLimited,
                            ]
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
        $this->info("Done. seeded={$seeded}, no-oc-match={$missing}, failed={$failures}.");

        return self::SUCCESS;
    }
}
