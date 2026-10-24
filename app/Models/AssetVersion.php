<?php

namespace App\Models;

use App\Enums\FileRole;
use App\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['digital_asset_id', 'version_number', 'status', 'processing_stage', 'processing_progress', 'processing_error', 'processing_attempts', 'original_filename', 'client_mime', 'detected_mime', 'extension', 'size_bytes', 'checksum_sha256', 'quarantine_path', 'storage_backend_id', 'metadata', 'is_viewable', 'change_note', 'uploaded_by', 'processing_started_at', 'processing_completed_at', 'activated_at'])]
class AssetVersion extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'metadata' => 'array',
            'is_viewable' => 'boolean',
            'size_bytes' => 'integer',
            'processing_progress' => 'integer',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id')->withTrashed();
    }

    public function files(): HasMany
    {
        return $this->hasMany(AssetFile::class);
    }

    public function storageBackend(): BelongsTo
    {
        return $this->belongsTo(StorageBackend::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function file(FileRole $role): ?AssetFile
    {
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        return $files->first(fn (AssetFile $file) => $file->role === $role);
    }

    /** Storage directory for this version, relative to the backend root. */
    public function storagePrefix(): string
    {
        $asset = $this->asset;

        return substr($asset->uuid, 0, 2).'/'.$asset->uuid.'/'.$this->uuid;
    }

    public function updateProgress(string $stage, int $progress): void
    {
        $this->forceFill(['processing_stage' => $stage, 'processing_progress' => max(0, min(100, $progress))])->saveQuietly();
    }
}
