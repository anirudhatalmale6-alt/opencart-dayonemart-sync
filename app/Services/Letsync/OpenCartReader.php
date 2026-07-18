<?php

namespace App\Services\Letsync;

use Illuminate\Support\Facades\DB;

class OpenCartReader
{
    private const CONNECTION = 'opencart';

    public function languageId(): int
    {
        return (int) config('letsync.language_id', 1);
    }

    private function db()
    {
        return DB::connection(self::CONNECTION);
    }

    public function category(int $categoryId): ?array
    {
        $category = $this->db()->table('category')->where('category_id', $categoryId)->first();
        if (! $category) {
            return null;
        }

        $description = $this->db()->table('category_description')
            ->where('category_id', $categoryId)
            ->where('language_id', $this->languageId())
            ->first();

        return array_merge((array) $category, [
            'name' => $description->name ?? null,
            'description' => $description->description ?? null,
            'meta_title' => $description->meta_title ?? null,
            'meta_description' => $description->meta_description ?? null,
            'meta_keyword' => $description->meta_keyword ?? null,
        ]);
    }

    public function allCategoryIds(): array
    {
        return $this->db()->table('category')->orderBy('parent_id')->orderBy('category_id')->pluck('category_id')->map(fn ($id) => (int) $id)->all();
    }

    public function product(int $productId): ?array
    {
        $product = $this->db()->table('product')->where('product_id', $productId)->first();
        if (! $product) {
            return null;
        }

        $description = $this->db()->table('product_description')
            ->where('product_id', $productId)
            ->where('language_id', $this->languageId())
            ->first();

        $categoryIds = $this->db()->table('product_to_category')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $images = $this->db()->table('product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->filter()
            ->values()
            ->all();

        return [
            'product' => (array) $product,
            'description' => $description ? (array) $description : [],
            'category_ids' => $categoryIds,
            'images' => $images,
        ];
    }

    public function allProductIds(): array
    {
        return $this->db()->table('product')->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    public function customer(int $customerId): ?array
    {
        $customer = $this->db()->table('customer')->where('customer_id', $customerId)->first();
        if (! $customer) {
            return null;
        }

        return ['customer' => (array) $customer];
    }

    public function allCustomerIds(): array
    {
        return $this->db()->table('customer')->orderBy('customer_id')->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
    }

    public function order(int $orderId): ?array
    {
        $order = $this->db()->table('order')->where('order_id', $orderId)->first();
        if (! $order) {
            return null;
        }

        $products = $this->db()->table('order_product')->where('order_id', $orderId)->get()->map(fn ($row) => (array) $row)->all();
        $totals = $this->db()->table('order_total')->where('order_id', $orderId)->orderBy('sort_order')->get()->map(fn ($row) => (array) $row)->all();

        return [
            'order' => (array) $order,
            'products' => $products,
            'totals' => $totals,
        ];
    }

    public function allOrderIds(): array
    {
        return $this->db()->table('order')->where('order_status_id', '>', 0)->orderBy('order_id')->pluck('order_id')->map(fn ($id) => (int) $id)->all();
    }
}
