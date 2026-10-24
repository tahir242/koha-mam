<?php

namespace App\Livewire\Admin;

use App\Enums\MediaType;
use App\Models\StorageBackend;
use App\Storage\StorageManager;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Storage backend configuration. Changing the default only affects new
 * uploads: existing assets are never migrated automatically, and viewer
 * URLs do not depend on where files are stored.
 */
#[Title('Storage')]
class StorageBackends extends Component
{
    public ?int $editingId = null;

    public string $key = '';

    public string $name = '';

    public string $driver = 'local';

    public string $root = '';

    public string $bucket = '';

    public string $endpoint = '';

    public string $region = 'us-east-1';

    public string $prefix = '';

    public string $credentials_profile = 'default';

    public bool $use_path_style_endpoint = true;

    public array $allowed_media_types = [];

    public ?int $capacity_gb = null;

    public bool $is_active = true;

    /** Runs on every request, including Livewire actions. */
    public function boot(): void
    {
        $this->authorize('media.manage_storage');
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('backend')->show();
    }

    public function edit(int $id): void
    {
        $backend = StorageBackend::findOrFail($id);
        $s = $backend->settings ?? [];

        $this->editingId = $backend->id;
        $this->key = $backend->key;
        $this->name = $backend->name;
        $this->driver = $backend->driver;
        $this->root = $s['root'] ?? '';
        $this->bucket = $s['bucket'] ?? '';
        $this->endpoint = $s['endpoint'] ?? '';
        $this->region = $s['region'] ?? 'us-east-1';
        $this->prefix = $s['prefix'] ?? '';
        $this->credentials_profile = $s['credentials_profile'] ?? 'default';
        $this->use_path_style_endpoint = (bool) ($s['use_path_style_endpoint'] ?? true);
        $this->allowed_media_types = $backend->allowed_media_types ?? [];
        $this->capacity_gb = $backend->capacity_bytes ? (int) round($backend->capacity_bytes / 1024 ** 3) : null;
        $this->is_active = $backend->is_active;

        Flux::modal('backend')->show();
    }

    public function save(StorageManager $manager): void
    {
        $data = $this->validate([
            'key' => ['required', 'alpha_dash', 'max:64', Rule::unique('storage_backends', 'key')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in(array_keys(StorageBackend::DRIVERS))],
            'root' => [Rule::requiredIf(fn () => $this->driver !== 's3'), 'nullable', 'string', 'max:500', 'not_regex:/\.\./'],
            'bucket' => [Rule::requiredIf(fn () => $this->driver === 's3'), 'nullable', 'string', 'max:63'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'region' => ['nullable', 'string', 'max:40'],
            'prefix' => ['nullable', 'string', 'max:120', 'not_regex:/\.\./'],
            'credentials_profile' => ['required', Rule::in(array_keys(config('dam.storage.s3_credentials')))],
            'use_path_style_endpoint' => ['boolean'],
            'allowed_media_types' => ['array'],
            'allowed_media_types.*' => [Rule::enum(MediaType::class)],
            'capacity_gb' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        // The public web root must never contain originals.
        if ($data['driver'] !== 's3' && str_starts_with(realpath($data['root']) ?: $data['root'], public_path())) {
            $this->addError('root', 'Storage cannot be inside the public web directory.');

            return;
        }

        $settings = $data['driver'] === 's3'
            ? array_filter(['bucket' => $data['bucket'], 'endpoint' => $data['endpoint'], 'region' => $data['region'], 'prefix' => $data['prefix'], 'credentials_profile' => $data['credentials_profile'], 'use_path_style_endpoint' => $data['use_path_style_endpoint']], fn ($v) => $v !== '' && $v !== null)
            : ['root' => rtrim($data['root'], '/\\')];

        $backend = StorageBackend::updateOrCreate(['id' => $this->editingId], [
            'key' => $data['key'],
            'name' => $data['name'],
            'driver' => $data['driver'],
            'settings' => $settings,
            'allowed_media_types' => $data['allowed_media_types'] ?: null,
            'capacity_bytes' => $data['capacity_gb'] ? $data['capacity_gb'] * 1024 ** 3 : null,
            'is_active' => $data['is_active'],
        ]);

        $manager->flush();
        $probe = $manager->probe($backend);

        Flux::modal('backend')->close();
        Flux::toast(variant: $probe['ok'] ? 'success' : 'warning', text: $probe['ok'] ? 'Storage saved and verified.' : 'Saved, but the health check failed: '.$probe['message']);
    }

    public function makeDefault(int $id, StorageManager $manager): void
    {
        $backend = StorageBackend::findOrFail($id);

        if (! $backend->is_active || ! $manager->probe($backend)['ok']) {
            Flux::toast(variant: 'danger', text: 'Only an active, healthy backend can be the default.');

            return;
        }

        DB::transaction(function () use ($backend) {
            StorageBackend::where('is_default', true)->update(['is_default' => false]);
            $backend->update(['is_default' => true]);
        });

        Flux::toast(variant: 'success', text: "{$backend->name} is now the default for new uploads. Existing files stay where they are.");
    }

    public function check(int $id, StorageManager $manager): void
    {
        $probe = $manager->probe(StorageBackend::findOrFail($id));
        Flux::toast(variant: $probe['ok'] ? 'success' : 'danger', text: $probe['message']);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'key', 'name', 'driver', 'root', 'bucket', 'endpoint', 'region', 'prefix', 'credentials_profile', 'use_path_style_endpoint', 'allowed_media_types', 'capacity_gb', 'is_active');
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.admin.storage-backends', [
            'backends' => StorageBackend::orderByDesc('is_default')->orderBy('name')->get()->map(fn (StorageBackend $b) => ['model' => $b, 'used' => $b->usedBytes(), 'files' => $b->files()->count()]),
            'drivers' => StorageBackend::DRIVERS,
            'profiles' => array_keys(config('dam.storage.s3_credentials')),
            'types' => MediaType::cases(),
        ]);
    }
}
