<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\MarcRelationship;
use App\Enums\MediaType;
use App\Koha\ItemMetadata;
use App\Koha\ManagedItemUri;
use App\States\Asset\AssetState;
use App\States\Asset\Published;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\ModelStates\HasStates;

/**
 * @property AssetState $state
 * @property MediaType $media_type
 * @property AccessLevel $access_level
 * @property MarcRelationship $marc_relationship
 */
#[Fillable(['koha_instance_id', 'biblio_id', 'item_id', 'item_snapshot', 'item_snapshot_at', 'title', 'description', 'media_type', 'access_level', 'is_sensitive', 'requires_approval_override', 'marc_relationship', 'link_text', 'public_note', 'biblio_snapshot', 'biblio_snapshot_at', 'created_by', 'updated_by'])]
class DigitalAsset extends Model
{
    use HasFactory, HasStates, HasUuids, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'biblio_id' => 'integer',
            'item_id' => 'integer',
            'item_snapshot' => 'array',
            'item_snapshot_at' => 'datetime',
            'state' => AssetState::class,
            'media_type' => MediaType::class,
            'access_level' => AccessLevel::class,
            'marc_relationship' => MarcRelationship::class,
            'is_sensitive' => 'boolean',
            'requires_approval_override' => 'boolean',
            'biblio_snapshot' => 'array',
            'biblio_snapshot_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'marc_verified_at' => 'datetime',
            'view_count' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }

    /** The integer id stays the primary key; the UUID is the public identifier. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'state', 'access_level', 'is_sensitive', 'requires_approval_override', 'marc_relationship', 'link_text', 'public_note', 'current_version_id', 'rejection_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Asset record {$event}")
            ->useLogName('asset');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'current_version_id');
    }

    public function publicationOperations(): HasMany
    {
        return $this->hasMany(PublicationOperation::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForBiblio(Builder $query, int $biblioId): void
    {
        $query->where('koha_instance_id', config('dam.koha.instance_id'))->where('biblio_id', $biblioId);
    }

    public function scopeForItem(Builder $query, int $itemId): void
    {
        $query->where('koha_instance_id', config('dam.koha.instance_id'))->where('item_id', $itemId);
    }

    public function scopePublished(Builder $query): void
    {
        $query->whereState('state', Published::class);
    }

    public function isPublished(): bool
    {
        return $this->state instanceof Published;
    }

    /** Stable public viewer URL written to MARC 856 $u. */
    public function viewerUrl(): string
    {
        return config('dam.public_url').'/view/'.$this->uuid;
    }

    public function linkText(): string
    {
        return $this->link_text ?: config('dam.publishing.marc.link_text');
    }

    public function snapshot(string $key, mixed $default = null): mixed
    {
        return data_get($this->biblio_snapshot, $key, $default);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(MediaAccessEvent::class);
    }

    /** "1,234 views", as on the viewer page. */
    public function viewsLabel(): string
    {
        $views = (int) $this->view_count;

        return $views === 0 ? 'No views yet' : number_format($views).' '.Str::plural('view', $views);
    }

    /** "1.2K views", for lists and cards. */
    public function viewsShort(): string
    {
        $views = (int) $this->view_count;

        return ($views < 1000 ? (string) $views : Number::abbreviate($views, maxPrecision: 1)).' '.Str::plural('view', $views);
    }

    public function isItemLevel(): bool
    {
        return $this->item_id !== null;
    }

    /** "Item #4768", or null for a record-level asset. */
    public function itemLabel(): ?string
    {
        return $this->item_id === null ? null : ItemMetadata::label($this->item_id);
    }

    /** Barcode, copy and call number of the item, when known. */
    public function itemDetails(): ?string
    {
        return $this->item_id === null ? null : ItemMetadata::details($this->item_snapshot);
    }

    /** Stable public link written to the Koha item's URI field (952$u). */
    public function itemUrl(): ?string
    {
        return $this->item_id === null ? null : app(ManagedItemUri::class)->url($this->item_id);
    }
}
