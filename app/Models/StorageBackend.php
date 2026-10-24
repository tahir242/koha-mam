<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['key', 'name', 'driver', 'settings', 'allowed_media_types', 'capacity_bytes', 'is_default', 'is_active', 'health_status', 'health_message', 'health_checked_at'])]
class StorageBackend extends Model
{
    use HasFactory, LogsActivity;

    public const DRIVERS = [
        'local' => 'Local private filesystem',
        'nas' => 'NAS-mounted filesystem',
        'shared' => 'Mounted shared drive',
        's3' => 'MinIO / S3-compatible object storage',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'allowed_media_types' => 'array',
            'capacity_bytes' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'health_checked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'name', 'driver', 'settings', 'allowed_media_types', 'capacity_bytes', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('settings');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AssetFile::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isFilesystem(): bool
    {
        return in_array($this->driver, ['local', 'nas', 'shared'], true);
    }

    public function root(): ?string
    {
        return $this->settings['root'] ?? null;
    }

    public function acceptsMediaType(string $mediaType): bool
    {
        return empty($this->allowed_media_types) || in_array($mediaType, $this->allowed_media_types, true);
    }

    public function usedBytes(): int
    {
        return (int) $this->files()->sum('size_bytes');
    }
}
