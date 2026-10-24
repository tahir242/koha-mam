<?php

namespace App\Delivery;

use App\Enums\AccessLevel;
use App\Enums\Capability;
use App\Enums\FileRole;
use App\Models\AssetFile;
use App\Models\DigitalAsset;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Single authority for "may this request see this asset / file?".
 *
 * Public: published + public access + current version + derivative role.
 * Staff (media.view_dashboard): any derivative of any version, including
 * unpublished and restricted assets. Archival originals are never
 * delivered to anyone through HTTP.
 */
class MediaAccess
{
    public function canViewAsset(DigitalAsset $asset, ?User $user): bool
    {
        if ($asset->trashed()) {
            return false;
        }

        if ($this->isStaff($user)) {
            return true;
        }

        return $asset->isPublished()
            && $asset->access_level === AccessLevel::Public
            && $asset->currentVersion?->is_viewable === true;
    }

    public function canViewFile(DigitalAsset $asset, AssetFile $file, ?User $user): bool
    {
        if (! $file->role->isPublicDerivative()) {
            return false;
        }

        $version = $file->version;

        if (! $version || $version->digital_asset_id !== $asset->id) {
            return false;
        }

        if ($this->isStaff($user)) {
            return true;
        }

        return $this->canViewAsset($asset, $user) && $version->id === $asset->current_version_id;
    }

    /** Restricted assets are visible to signed-in staff only. */
    public function isStaff(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Public routes do not run EnsureStaffSession: apply the session's Koha ceiling here too.
        if (! session()->has('dam.koha_capabilities')) {
            return false;
        }

        $user->setKohaCapabilities(session('dam.koha_capabilities'));

        return $user->hasCapability(Capability::ViewDashboard);
    }

    public function signedFileUrl(DigitalAsset $asset, AssetFile $file): string
    {
        return URL::temporarySignedRoute('media.file', now()->addSeconds(config('dam.delivery.signed_url_ttl')), [
            'asset' => $asset->uuid,
            'file' => $file->id,
        ]);
    }

    public function signedHlsUrl(DigitalAsset $asset, AssetFile $master, string $relative): string
    {
        // Segment URLs must outlive playback of the whole (VOD) programme.
        $ttl = (int) config('dam.delivery.signed_url_ttl') + (int) ceil((float) ($master->metadata['duration'] ?? 0));

        return URL::temporarySignedRoute('media.hls', now()->addSeconds($ttl), [
            'asset' => $asset->uuid,
            'file' => $master->id,
            'path' => $relative,
        ]);
    }

    /**
     * URL for a derivative role of the asset's current version, or null.
     */
    public function urlFor(DigitalAsset $asset, FileRole $role): ?string
    {
        $file = $asset->currentVersion?->file($role);

        return $file ? ($role === FileRole::HlsMaster ? $this->signedHlsUrl($asset, $file, 'master.m3u8') : $this->signedFileUrl($asset, $file)) : null;
    }
}
