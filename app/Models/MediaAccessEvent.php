<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "open": the viewer page was loaded. "view": the visitor watched or read for
 * the qualifying time; `counted` tells whether it added to the public total
 * (otherwise `reason`: staff, repeat, daily_cap, bot or preview).
 */
#[Fillable(['digital_asset_id', 'event', 'visitor_hash', 'referrer_host', 'user_agent_family', 'is_staff', 'counted', 'reason', 'created_at'])]
class MediaAccessEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['is_staff' => 'boolean', 'counted' => 'boolean', 'created_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id')->withTrashed();
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('dam.audit.access_event_retention_days')));
    }
}
