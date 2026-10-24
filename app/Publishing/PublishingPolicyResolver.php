<?php

namespace App\Publishing;

use App\Enums\Capability;
use App\Models\DigitalAsset;
use App\Models\PublishingPolicy;
use App\Models\User;

/**
 * Decides whether publishing an asset needs a separate approval.
 *
 * Order of precedence:
 *  1. Sensitive assets always require approval.
 *  2. Users without the publish capability always require approval.
 *  3. A per-asset override (set only by authorised staff).
 *  4. The highest-priority (then most specific) matching active policy.
 *  5. The configured default.
 */
class PublishingPolicyResolver
{
    public function requiresApproval(DigitalAsset $asset, User $user): bool
    {
        return $this->explain($asset, $user)['requires_approval'];
    }

    /**
     * @return array{requires_approval: bool, reason: string, policy: ?PublishingPolicy}
     */
    public function explain(DigitalAsset $asset, User $user): array
    {
        if ($asset->is_sensitive) {
            return ['requires_approval' => true, 'reason' => 'Sensitive resources always require review.', 'policy' => null];
        }

        if (! $user->hasCapability(Capability::Publish)) {
            return ['requires_approval' => true, 'reason' => 'Your role cannot publish directly.', 'policy' => null];
        }

        if ($asset->requires_approval_override !== null) {
            return [
                'requires_approval' => $asset->requires_approval_override,
                'reason' => $asset->requires_approval_override ? 'This resource is set to always require approval.' : 'This resource is exempt from approval.',
                'policy' => null,
            ];
        }

        $policy = $this->matchingPolicy($asset, $user);

        if ($policy) {
            return ['requires_approval' => $policy->requires_approval, 'reason' => "Policy “{$policy->name}”.", 'policy' => $policy];
        }

        $default = (bool) config('dam.publishing.default_requires_approval');

        return ['requires_approval' => $default, 'reason' => $default ? 'Default policy: approval required.' : 'Default policy: direct publication allowed.', 'policy' => null];
    }

    public function matchingPolicy(DigitalAsset $asset, User $user): ?PublishingPolicy
    {
        return PublishingPolicy::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (PublishingPolicy $p) => ($p->role === null || $p->role === $user->role)
                && ($p->media_type === null || $p->media_type === $asset->media_type->value)
                && ($p->access_level === null || $p->access_level === $asset->access_level->value)
                && ($p->applies_to_sensitive === null || $p->applies_to_sensitive === $asset->is_sensitive))
            ->sortBy([
                fn ($a, $b) => $b->priority <=> $a->priority,
                fn ($a, $b) => $b->specificity() <=> $a->specificity(),
                // On a full tie, the stricter rule wins.
                fn ($a, $b) => $b->requires_approval <=> $a->requires_approval,
            ])
            ->first();
    }
}
