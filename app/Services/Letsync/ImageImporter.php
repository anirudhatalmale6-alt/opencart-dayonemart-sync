<?php

namespace App\Services\Letsync;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageImporter
{
    public function sync(string $tableName, int $tableId, string $key, array $ocImagePaths): void
    {
        $ocImagePaths = array_values(array_filter(array_map('trim', $ocImagePaths)));

        $existing = File::query()
            ->where('table_name', $tableName)
            ->where('table_id', $tableId)
            ->where('key', $key)
            ->get();

        $existingByOcPath = [];
        foreach ($existing as $file) {
            $ocPath = $file->meta_data['oc_path'] ?? null;
            if ($ocPath !== null) {
                $existingByOcPath[$ocPath] = $file;
            }
        }

        $keep = [];
        $position = 0;

        foreach ($ocImagePaths as $ocPath) {
            if (isset($existingByOcPath[$ocPath])) {
                $file = $existingByOcPath[$ocPath];
                $file->update(['position' => $position]);
                $keep[$file->id] = true;
                $position++;
                continue;
            }

            $stored = $this->download($ocPath, $tableName);
            if ($stored === null) {
                continue;
            }

            $file = File::create([
                'name' => $stored['name'],
                'path' => $stored['path'],
                'type' => 'image',
                'size' => (string) $stored['size'],
                'disk' => config('letsync.image_disk', 'public'),
                'extension' => $stored['extension'],
                'mime_type' => $stored['mime_type'],
                'table_name' => $tableName,
                'table_id' => $tableId,
                'key' => $key,
                'position' => $position,
                'meta_data' => ['oc_path' => $ocPath],
            ]);
            $keep[$file->id] = true;
            $position++;
        }

        foreach ($existing as $file) {
            if (! isset($keep[$file->id])) {
                $file->delete();
            }
        }
    }

    private function download(string $ocPath, string $tableName): ?array
    {
        $base = config('letsync.image_base_url');
        $segments = array_map('rawurlencode', explode('/', $ocPath));
        $url = $base . '/' . implode('/', $segments);

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; LetsyncBot/1.0)',
        ])->timeout(20)->retry(2, 500, throw: false)->get($url);

        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        $extension = strtolower(pathinfo($ocPath, PATHINFO_EXTENSION)) ?: 'jpg';
        $name = Str::uuid()->toString() . '.' . $extension;
        $path = $tableName . '/' . $name;

        Storage::disk(config('letsync.image_disk', 'public'))->put($path, $response->body());

        return [
            'name' => $name,
            'path' => $path,
            'size' => strlen($response->body()),
            'extension' => $extension,
            'mime_type' => $response->header('Content-Type') ?: 'image/' . $extension,
        ];
    }
}
