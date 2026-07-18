<?php

namespace App\Services\Letsync;

use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Support\Str;

class CategorySyncService
{
    public function __construct(
        private readonly OpenCartReader $reader,
        private readonly ImageImporter $images,
        private readonly SyncLogger $logger,
    ) {}

    public function syncById(int $ocCategoryId, string $event = 'sync'): void
    {
        $start = (int) (microtime(true) * 1000);

        $data = $this->reader->category($ocCategoryId);
        if ($data === null) {
            $this->logger->skipped('category', $event, $ocCategoryId, 'Category not found in OpenCart');

            return;
        }

        if ((int) $data['parent_id'] === 0) {
            $category = $this->upsertCategory($data);
            $this->images->sync('categories', $category->id, 'primary_image', $this->imagePaths($data));
            $this->logger->success('category', $event, $ocCategoryId, $category->id, $this->elapsed($start), 'Category synced');

            return;
        }

        $subCategory = $this->upsertSubCategory($data);
        $this->images->sync('sub_categories', $subCategory->id, 'primary_image', $this->imagePaths($data));
        $this->logger->success('category', $event, $ocCategoryId, $subCategory->id, $this->elapsed($start), 'Sub-category synced');
    }

    private function imagePaths(array $data): array
    {
        $image = trim((string) ($data['image'] ?? ''));

        return $image !== '' ? [$image] : [];
    }

    public function deleteByExternalId(int $ocCategoryId, string $event = 'delete_category'): void
    {
        Category::where('external_id', $ocCategoryId)->get()->each->delete();
        SubCategory::where('external_id', $ocCategoryId)->get()->each->delete();
        $this->logger->success('category', $event, $ocCategoryId, null, 0, 'Category deleted');
    }

    public function upsertCategory(array $data): Category
    {
        $externalId = (int) $data['category_id'];
        $name = $this->name($data, "Category {$externalId}");

        $category = Category::where('external_id', $externalId)->first() ?? new Category();
        $category->external_id = $externalId;
        $category->fill([
            'module_id' => (int) config('letsync.module_id'),
            'name' => $name,
            'description' => $data['description'] ?? null,
            'position' => (int) ($data['sort_order'] ?? 0),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keyword'] ?? null,
            'is_active' => (int) ($data['status'] ?? 1) === 1,
        ]);
        $category->save();

        return $category;
    }

    public function upsertSubCategory(array $data): SubCategory
    {
        $externalId = (int) $data['category_id'];
        $root = $this->rootCategory((int) $data['parent_id']);
        $localCategory = $this->upsertCategory($root);
        $name = $this->name($data, "Category {$externalId}");

        $subCategory = SubCategory::where('external_id', $externalId)->first() ?? new SubCategory();
        $subCategory->external_id = $externalId;
        $subCategory->fill([
            'name' => $name,
            'slug' => Str::slug($name) . '-oc' . $externalId,
            'description' => $data['description'] ?? null,
            'category_id' => $localCategory->id,
            'is_active' => (int) ($data['status'] ?? 1) === 1,
        ]);
        $subCategory->save();

        return $subCategory;
    }

    public function resolveForProduct(int $ocCategoryId): array
    {
        $data = $this->reader->category($ocCategoryId);
        if ($data === null) {
            return [null, null];
        }

        if ((int) $data['parent_id'] === 0) {
            return [$this->upsertCategory($data)->id, null];
        }

        $subCategory = $this->upsertSubCategory($data);

        return [$subCategory->category_id, $subCategory->id];
    }

    public function fallbackCategoryId(): int
    {
        return Category::firstOrCreate(
            ['name' => config('letsync.fallback_category'), 'module_id' => (int) config('letsync.module_id')],
            ['is_active' => true, 'position' => 999999]
        )->id;
    }

    private function rootCategory(int $ocCategoryId): array
    {
        $guard = 0;
        $current = $this->reader->category($ocCategoryId);

        while ($current !== null && (int) $current['parent_id'] !== 0 && $guard < 20) {
            $current = $this->reader->category((int) $current['parent_id']);
            $guard++;
        }

        return $current ?? ['category_id' => $ocCategoryId, 'parent_id' => 0, 'name' => "Category {$ocCategoryId}", 'status' => 1];
    }

    private function name(array $data, string $fallback): string
    {
        $name = trim(strip_tags(html_entity_decode((string) ($data['name'] ?? ''))));

        return $name !== '' ? $name : $fallback;
    }

    private function elapsed(int $start): int
    {
        return max(0, (int) (microtime(true) * 1000) - $start);
    }
}
