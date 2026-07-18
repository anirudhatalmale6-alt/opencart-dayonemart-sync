<?php

namespace App\Services\Letsync;

use App\Models\GroceryItem;
use App\Models\Hub;
use App\Models\InventoryStock;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\ItemStock;
use Illuminate\Support\Facades\DB;

class ProductSyncService
{
    public function __construct(
        private readonly OpenCartReader $reader,
        private readonly CategorySyncService $categories,
        private readonly ImageImporter $images,
        private readonly SyncLogger $logger,
    ) {}

    public function syncById(int $ocProductId, string $event = 'sync'): void
    {
        $start = (int) (microtime(true) * 1000);

        $data = $this->reader->product($ocProductId);
        if ($data === null) {
            $this->logger->skipped('product', $event, $ocProductId, 'Product not found in OpenCart');

            return;
        }

        $item = DB::transaction(fn (): Item => $this->upsert($data));

        $this->images->sync('items', $item->id, 'primary_image', $this->primaryImage($data));
        $this->images->sync('items', $item->id, 'gallery_images', $data['images']);

        $this->logger->success('product', $event, $ocProductId, $item->id, $this->elapsed($start), 'Product synced');
    }

    public function deleteByExternalId(int $ocProductId, string $event = 'delete_product'): void
    {
        Item::where('external_id', $ocProductId)->get()->each->delete();
        $this->logger->success('product', $event, $ocProductId, null, 0, 'Product deleted');
    }

    private function upsert(array $data): Item
    {
        $product = $data['product'];
        $description = $data['description'];
        $externalId = (int) $product['product_id'];

        [$categoryId, $subCategoryId] = $this->resolveCategory($data['category_ids']);

        $name = $this->name($description, $product, $externalId);
        $price = (float) ($product['price'] ?? 0);
        $quantity = (int) ($product['quantity'] ?? 0);
        $isLimitedStock = (int) ($product['subtract'] ?? 1) === 1;

        $item = Item::where('external_id', $externalId)->first() ?? new Item();
        $item->external_id = $externalId;
        $item->fill([
            'module_id' => (int) config('letsync.module_id'),
            'name' => $name,
            'description' => $description['description'] ?? null,
            'sku' => $this->uniqueSku($product, $externalId),
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId,
            'is_active' => (int) ($product['status'] ?? 1) === 1,
            'has_unit' => false,
            'has_brand' => false,
            'has_discount' => false,
            'track_batches' => false,
            'meta_title' => $description['meta_title'] ?? null,
            'meta_description' => $description['meta_description'] ?? null,
            'meta_keywords' => $description['meta_keyword'] ?? null,
        ]);
        $item->save();

        ItemPrice::updateOrCreate(
            ['item_id' => $item->id],
            [
                'base_price' => $price,
                'min_price' => $price,
                'max_price' => $price,
                'has_variants' => false,
            ]
        );

        ItemStock::updateOrCreate(
            ['item_id' => $item->id],
            [
                'quantity' => $quantity,
                'stock_type' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
                'is_limited_stock' => $isLimitedStock,
            ]
        );

        $this->syncInventory($item->id, max(0, $quantity), $isLimitedStock);

        if ((int) config('letsync.module_id') === 1) {
            GroceryItem::firstOrCreate(['item_id' => $item->id], ['is_halal' => false, 'has_label' => false]);
        }

        return $item;
    }

    private function syncInventory(int $itemId, int $quantity, bool $isLimitedStock): void
    {
        foreach (Hub::query()->pluck('id') as $hubId) {
            InventoryStock::updateOrCreate(
                ['item_id' => $itemId, 'hub_id' => $hubId, 'variant_key' => ''],
                ['quantity' => $quantity, 'is_limited_stock' => $isLimitedStock]
            );
        }
    }

    private function resolveCategory(array $ocCategoryIds): array
    {
        foreach ($ocCategoryIds as $ocCategoryId) {
            [$categoryId, $subCategoryId] = $this->categories->resolveForProduct((int) $ocCategoryId);
            if ($categoryId !== null) {
                return [$categoryId, $subCategoryId];
            }
        }

        return [$this->categories->fallbackCategoryId(), null];
    }

    private function primaryImage(array $data): array
    {
        $image = trim((string) ($data['product']['image'] ?? ''));

        return $image !== '' ? [$image] : [];
    }

    private function uniqueSku(array $product, int $externalId): ?string
    {
        $candidate = trim((string) ($product['sku'] ?? '')) ?: trim((string) ($product['model'] ?? ''));
        if ($candidate === '') {
            return null;
        }

        $clash = Item::where('sku', $candidate)->where('external_id', '!=', $externalId)->exists();

        return $clash ? $candidate . '-' . $externalId : $candidate;
    }

    private function name(array $description, array $product, int $externalId): string
    {
        $name = trim(strip_tags(html_entity_decode((string) ($description['name'] ?? ''))));
        if ($name !== '') {
            return $name;
        }

        $model = trim((string) ($product['model'] ?? ''));

        return $model !== '' ? $model : "Product {$externalId}";
    }

    private function elapsed(int $start): int
    {
        return max(0, (int) (microtime(true) * 1000) - $start);
    }
}
