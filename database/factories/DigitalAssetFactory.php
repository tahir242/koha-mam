<?php

namespace Database\Factories;

use App\Enums\FileRole;
use App\Enums\MediaType;
use App\Enums\VersionStatus;
use App\Models\DigitalAsset;
use App\Models\StorageBackend;
use App\States\Asset\AssetState;
use App\Storage\StorageManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DigitalAsset>
 */
class DigitalAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'koha_instance_id' => config('dam.koha.instance_id'),
            'biblio_id' => fake()->numberBetween(1, 50000),
            'title' => fake()->sentence(4),
            'media_type' => MediaType::Document,
            'access_level' => 'public',
            'marc_relationship' => 'resource',
        ];
    }

    /**
     * @param  class-string<AssetState>  $state
     */
    public function inState(string $state): static
    {
        return $this->afterCreating(function (DigitalAsset $asset) use ($state) {
            $asset->forceFill(['state' => $state::getMorphClass()])->saveQuietly();
        });
    }

    /**
     * Attach a processed, viewable version with derivative records whose
     * files are written to the given backend.
     */
    public function processed(StorageBackend $backend, array $roles = [FileRole::ViewerPdf, FileRole::Thumbnail]): static
    {
        return $this->afterCreating(function (DigitalAsset $asset) use ($backend, $roles) {
            $version = $asset->versions()->create([
                'version_number' => 1,
                'status' => VersionStatus::Ready,
                'original_filename' => 'source.pdf',
                'detected_mime' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 4,
                'checksum_sha256' => hash('sha256', 'test'),
                'storage_backend_id' => $backend->id,
                'is_viewable' => true,
            ]);

            $disk = app(StorageManager::class)->disk($backend);

            foreach ([FileRole::Original, ...$roles] as $role) {
                $path = $version->storagePrefix().'/'.($role === FileRole::Original ? 'original/'.Str::random(8).'.pdf' : 'derivatives/'.$role->value.'.bin');
                $disk->put($path, 'data-'.$role->value);
                $version->files()->create([
                    'role' => $role,
                    'storage_backend_id' => $backend->id,
                    'path' => $path,
                    'mime' => $role === FileRole::Thumbnail ? 'image/png' : 'application/pdf',
                    'size_bytes' => strlen('data-'.$role->value),
                ]);
            }

            $asset->forceFill(['current_version_id' => $version->id])->saveQuietly();
        });
    }
}
