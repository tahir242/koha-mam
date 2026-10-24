<?php

namespace Tests\Feature;

use App\Livewire\Admin\StorageBackends;
use App\Models\StorageBackend;
use App\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Livewire;
use Tests\TestCase;

class StorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_backend_is_private_and_healthy(): void
    {
        $backend = $this->localBackend();
        $manager = app(StorageManager::class);

        $probe = $manager->probe($backend);

        $this->assertTrue($probe['ok']);
        $this->assertSame('healthy', $backend->fresh()->health_status);
        $this->assertInstanceOf(LocalFilesystemAdapter::class, $manager->disk($backend)->getAdapter());
        $this->assertSame('private', $manager->diskConfig($backend)['visibility']);
    }

    public function test_nas_and_shared_drive_backends_use_mounted_paths(): void
    {
        $manager = app(StorageManager::class);

        foreach (['nas', 'shared'] as $driver) {
            $backend = $this->localBackend(['driver' => $driver, 'is_default' => false]);
            $manager->disk($backend)->put('a/b.txt', 'x');

            $this->assertFileExists($manager->absolutePath($backend, 'a/b.txt'));
        }
    }

    public function test_unmounted_path_is_reported_as_failing(): void
    {
        $backend = $this->localBackend(['settings' => ['root' => sys_get_temp_dir().'/does-not-exist-'.uniqid()]]);

        $probe = app(StorageManager::class)->probe($backend);

        $this->assertFalse($probe['ok']);
        $this->assertSame('failing', $backend->fresh()->health_status);
    }

    public function test_s3_backend_builds_a_private_minio_disk_with_credentials_from_environment(): void
    {
        config(['dam.storage.s3_credentials.default' => ['key' => 'minio-key', 'secret' => 'minio-secret']]);

        $backend = StorageBackend::create([
            'key' => 'minio',
            'name' => 'MinIO',
            'driver' => 's3',
            'settings' => ['bucket' => 'dam-private', 'endpoint' => 'http://minio.internal:9000', 'credentials_profile' => 'default'],
        ]);

        $manager = app(StorageManager::class);
        $config = $manager->diskConfig($backend);

        $this->assertSame('minio-key', $config['key']);
        $this->assertTrue($config['use_path_style_endpoint']);
        $this->assertSame('private', $config['visibility']);
        $this->assertInstanceOf(AwsS3V3Adapter::class, $manager->disk($backend)->getAdapter());
        // Secrets are never persisted with the backend.
        $this->assertStringNotContainsString('minio-secret', json_encode($backend->fresh()->settings));
    }

    public function test_default_backend_respects_media_type_restrictions(): void
    {
        $this->localBackend(['key' => 'video-only', 'allowed_media_types' => ['video'], 'is_default' => true]);
        $general = $this->localBackend(['key' => 'general', 'is_default' => false]);

        $this->assertSame('video-only', app(StorageManager::class)->default('video')->key);
        $this->assertSame($general->id, app(StorageManager::class)->default('document')->id);
    }

    public function test_storage_admin_requires_capability_and_rejects_public_paths(): void
    {
        $this->actingAsStaff('cataloguer');
        $this->get('/staff/admin/storage')->assertForbidden();

        $this->actingAsStaff('administrator');
        $this->get('/staff/admin/storage')->assertOk();

        Livewire::test(StorageBackends::class)
            ->set('key', 'web')->set('name', 'Web')->set('driver', 'local')->set('root', public_path('uploads'))
            ->call('save')
            ->assertHasErrors('root');

        $this->assertDatabaseMissing('storage_backends', ['key' => 'web']);
    }
}
