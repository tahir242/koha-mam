<?php

namespace App\Settings;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Administrator-managed runtime settings (non-secret). Values override the
 * defaults from config/dam.php; credentials are never stored here.
 */
class Settings
{
    /** Keys the administration UI may change, with their validation rules. */
    public const EDITABLE = [
        'viewer.watermark_enabled' => ['boolean'],
        'viewer.watermark_text' => ['nullable', 'string', 'max:120'],
        'publishing.default_requires_approval' => ['boolean'],
        'koha.metadata_cache_ttl' => ['integer', 'min:60', 'max:604800'],
        'upload.duplicate_policy' => ['in:block,warn'],
    ];

    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function all(): array
    {
        return $this->values ??= Cache::rememberForever('dam:settings', fn () => AppSetting::query()->pluck('value', 'key')->all());
    }

    public function set(string $key, mixed $value, ?User $user = null): void
    {
        if (! array_key_exists($key, self::EDITABLE)) {
            throw new \InvalidArgumentException("Setting [{$key}] is not editable.");
        }

        AppSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user?->id]);
        Cache::forget('dam:settings');
        $this->values = null;
        $this->apply();
    }

    /** Push stored overrides into the runtime configuration. */
    public function apply(): void
    {
        foreach (['publishing.default_requires_approval', 'koha.metadata_cache_ttl', 'upload.duplicate_policy'] as $key) {
            $value = $this->get($key);

            if ($value !== null) {
                config(["dam.{$key}" => $value]);
            }
        }
    }
}
