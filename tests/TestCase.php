<?php

namespace Tests;

use App\Models\StorageBackend;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Refuse to run against anything but the in-memory test database. With a
     * cached configuration (php artisan config:cache on a deployed server)
     * Laravel ignores phpunit.xml, and RefreshDatabase would wipe the LIVE
     * database. Run tests on a server with:
     *   APP_CONFIG_CACHE=storage/framework/testing-config.php php artisan test
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($app->configurationIsCached() || $app->environment() !== 'testing' || $database !== ':memory:') {
            fwrite(STDERR, "\nREFUSING TO RUN TESTS: not using the in-memory test database (connection {$connection}, database {$database}, env {$app->environment()}).\n"
                ."The configuration is probably cached. Run: APP_CONFIG_CACHE=storage/framework/testing-config.php php artisan test\n\n");
            exit(1);
        }

        return $app;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dam-test-'.Str::random(10);
        File::ensureDirectoryExists($dir);

        return $this->tempDirs[] = $dir;
    }

    protected function localBackend(array $attributes = []): StorageBackend
    {
        return StorageBackend::create(array_merge([
            'key' => 'local-'.Str::random(6),
            'name' => 'Test storage',
            'driver' => 'local',
            'settings' => ['root' => $this->tempDir()],
            'is_default' => true,
            'is_active' => true,
        ], $attributes));
    }

    /** Authenticate as staff the same way SSO does (including the session marker). */
    protected function actingAsStaff(string $role = 'cataloguer', array $attributes = []): User
    {
        $user = User::factory()->role($role)->create($attributes);
        $this->actingAs($user)->withSession([
            'dam.authenticated_at' => now()->getTimestamp(),
            'dam.koha_capabilities' => ['media.access', 'media.upload', 'media.manage', 'media.admin'],
        ]);

        return $user;
    }
}
