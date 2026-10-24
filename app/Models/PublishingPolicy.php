<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'description', 'role', 'media_type', 'access_level', 'applies_to_sensitive', 'requires_approval', 'priority', 'is_active'])]
class PublishingPolicy extends Model
{
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'applies_to_sensitive' => 'boolean',
            'requires_approval' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /** Number of conditions set; more specific policies win ties on priority. */
    public function specificity(): int
    {
        return collect([$this->role, $this->media_type, $this->access_level, $this->applies_to_sensitive])
            ->filter(fn ($value) => $value !== null)
            ->count();
    }
}
