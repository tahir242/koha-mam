<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent outbox entry for a Koha MARC 856 change.
 */
#[Fillable(['digital_asset_id', 'koha_instance_id', 'biblio_id', 'item_id', 'action', 'status', 'attempts', 'available_at', 'last_error', 'last_error_kind', 'marc_005_before', 'marc_005_after', 'marc_hash_before', 'marc_hash_after', 'field_856', 'diagnostics', 'requested_by', 'started_at', 'completed_at'])]
class PublicationOperation extends Model
{
    use HasFactory, HasUuids;

    public const ACTION_LINK = 'link';

    public const ACTION_UNLINK = 'unlink';

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'biblio_id' => 'integer',
            'item_id' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'field_856' => 'array',
            'diagnostics' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeDue(Builder $query): void
    {
        $query->where('status', PublicationStatus::Pending->value)
            ->where(fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }
}
