<?php

namespace Database\Seeders;

use App\Models\PublishingPolicy;
use App\Models\StorageBackend;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Idempotent bootstrap data: a private local storage backend and sensible
 * starting publishing policies. No users are seeded — identities are
 * created by Koha SSO.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $root = config('dam.storage.default_local_root');
        File::ensureDirectoryExists($root, 0750);

        StorageBackend::firstOrCreate(['key' => 'local'], [
            'name' => 'Local private storage',
            'driver' => 'local',
            'settings' => ['root' => $root],
            'is_default' => ! StorageBackend::where('is_default', true)->exists(),
            'is_active' => true,
        ]);

        PublishingPolicy::firstOrCreate(['name' => 'Trusted cataloguers publish directly'], [
            'description' => 'Staff with the trusted cataloguer role may publish non-sensitive public resources without review.',
            'role' => 'trusted_cataloguer',
            'access_level' => 'public',
            'requires_approval' => false,
            'priority' => 100,
        ]);

        PublishingPolicy::firstOrCreate(['name' => 'Reviewers publish directly'], [
            'description' => 'Reviewers may publish their own uploads without a second review.',
            'role' => 'reviewer',
            'requires_approval' => false,
            'priority' => 100,
        ]);

        PublishingPolicy::firstOrCreate(['name' => 'Video always reviewed'], [
            'description' => 'Video recordings may contain identifiable people and are always reviewed.',
            'media_type' => 'video',
            'requires_approval' => true,
            'priority' => 200,
        ]);
    }
}
