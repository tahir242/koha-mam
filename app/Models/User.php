<?php

namespace App\Models;

use App\Enums\Capability;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A Koha staff identity. Authentication always originates from Koha; this
 * record only anchors ownership, auditing and the MAM role assignment.
 */
#[Fillable(['koha_instance_id', 'koha_patron_id', 'koha_userid', 'display_name', 'branch_code', 'role', 'is_active', 'last_authenticated_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity;

    protected $rememberTokenName = null;

    /**
     * Koha plugin capabilities for the current session (code-v2). They cap
     * what the MAM role may do; null means no ceiling (legacy hand-off,
     * console and queue contexts).
     *
     * @var list<string>|null
     */
    private ?array $kohaCapabilities = null;

    /**
     * @param  list<string>|null  $capabilities
     */
    public function setKohaCapabilities(?array $capabilities): static
    {
        $this->kohaCapabilities = $capabilities;

        return $this;
    }

    protected function casts(): array
    {
        return [
            'koha_patron_id' => 'integer',
            'is_active' => 'boolean',
            'last_authenticated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['role', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('identity');
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function name(): string
    {
        return $this->display_name ?: $this->koha_userid;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name())))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function roleLabel(): string
    {
        return config("dam.authorization.roles.{$this->role}.label", $this->role);
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        if (! $this->is_active) {
            return [];
        }

        $granted = config("dam.authorization.roles.{$this->role}.capabilities", []);

        if ($this->kohaCapabilities === null) {
            return $granted;
        }

        // Keys contain dots, so index the map directly rather than via dotted config().
        $map = config('dam.sso.capability_map', []);
        $allowed = collect($this->kohaCapabilities)
            ->flatMap(fn (string $c) => $map[$c] ?? [])
            ->unique()->values()->all();

        if (! in_array('media.view_dashboard', $allowed, true)) {
            return [];
        }

        return in_array('*', $granted, true)
            ? $allowed
            : array_values(array_intersect($granted, $allowed));
    }

    public function hasCapability(Capability|string $capability): bool
    {
        $capability = $capability instanceof Capability ? $capability->value : $capability;

        return in_array($capability, $this->capabilities(), true) || in_array('*', $this->capabilities(), true);
    }
}
