<?php

namespace App\Models;

use App\Enums\FileRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asset_version_id', 'role', 'storage_backend_id', 'path', 'mime', 'size_bytes', 'checksum_sha256', 'width', 'height', 'page', 'metadata'])]
class AssetFile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => FileRole::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'page' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'asset_version_id');
    }

    public function storageBackend(): BelongsTo
    {
        return $this->belongsTo(StorageBackend::class);
    }

    /** Directory containing the file (used for HLS segment resolution). */
    public function directory(): string
    {
        return trim(str_replace('\\', '/', dirname($this->path)), '/.');
    }
}
