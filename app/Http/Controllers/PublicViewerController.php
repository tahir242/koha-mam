<?php

namespace App\Http\Controllers;

use App\Delivery\MediaAccess;
use App\Delivery\ViewCounter;
use App\Enums\FileRole;
use App\Enums\MediaType;
use App\Models\DigitalAsset;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Stable public viewer: /view/{uuid}. The page uses the bibliographic
 * snapshot stored with the asset, so viewing never depends on Koha being
 * reachable.
 */
class PublicViewerController extends Controller
{
    public function __construct(
        private readonly MediaAccess $access,
        private readonly Settings $settings,
        private readonly ViewCounter $views,
    ) {}

    public function show(Request $request, string $uuid): View
    {
        abort_unless(Str::isUuid($uuid), 404);

        $asset = DigitalAsset::with(['currentVersion.files'])->where('uuid', $uuid)->first();

        abort_unless($asset && $this->access->canViewAsset($asset, $request->user()) && $asset->currentVersion?->is_viewable, 404);

        $version = $asset->currentVersion;
        $sources = $this->sources($asset);

        $this->views->open($request, $asset);

        return view('viewer.show', [
            'asset' => $asset,
            'version' => $version,
            'sources' => $sources,
            'isPreview' => ! $asset->isPublished(),
            'viewTracking' => config('dam.viewer.record_views') ? [
                'endpoint' => route('viewer.engagement', $asset->uuid),
                'token' => $this->views->token($asset),
                'seconds' => $this->views->qualifyingSeconds($asset),
            ] : null,
            'watermark' => $this->settings->get('viewer.watermark_enabled', config('dam.viewer.watermark.enabled'))
                ? $this->settings->get('viewer.watermark_text', config('dam.viewer.watermark.text'))
                : null,
        ]);
    }

    /**
     * Stable item link written to the Koha item's URI field (952$u):
     * /item/{itemnumber}. One viewable resource opens directly; several are
     * listed. Only what the visitor may view is shown.
     */
    public function item(Request $request, int $item): View|RedirectResponse
    {
        $assets = DigitalAsset::with(['currentVersion'])->forItem($item)->published()->orderBy('title')->get()
            ->filter(fn (DigitalAsset $asset) => $this->access->canViewAsset($asset, $request->user()) && $asset->currentVersion?->is_viewable)
            ->values();

        abort_if($assets->isEmpty(), 404);

        if ($assets->count() === 1) {
            return redirect()->route('viewer.show', $assets->first()->uuid);
        }

        return view('viewer.item', ['item' => $item, 'assets' => $assets, 'first' => $assets->first()]);
    }

    /**
     * The viewer page reports that the visitor has watched or read for the
     * qualifying time. Authorised by the signed single-use token from the
     * page, not by a session, so the route needs no CSRF token.
     */
    public function engage(Request $request, string $uuid): JsonResponse
    {
        abort_unless(Str::isUuid($uuid), 404);

        $token = $request->input('token');
        abort_unless(is_string($token) && strlen($token) <= 2048, 422);

        $asset = DigitalAsset::with('currentVersion')->where('uuid', $uuid)->first();
        abort_unless($asset && $this->access->canViewAsset($asset, $request->user()), 404);

        $result = $this->views->engage($request, $asset, $token);

        return response()->json(['counted' => $result['counted'], 'views' => $result['views']])
            ->header('Cache-Control', 'no-store');
    }

    private function sources(DigitalAsset $asset): array
    {
        $version = $asset->currentVersion;

        return match ($asset->media_type) {
            MediaType::Document => [
                'pdf' => $this->access->urlFor($asset, FileRole::ViewerPdf),
                'pages' => $version->files->where('role', FileRole::PageImage)->sortBy('page')->values()
                    ->map(fn ($f) => ['url' => $this->access->signedFileUrl($asset, $f), 'width' => $f->width, 'height' => $f->height, 'page' => $f->page])->all(),
            ],
            MediaType::Image => [
                'large' => $this->access->urlFor($asset, FileRole::ImageLarge),
                'medium' => $this->access->urlFor($asset, FileRole::ImageMedium),
                'width' => $version->file(FileRole::ImageLarge)?->width,
                'height' => $version->file(FileRole::ImageLarge)?->height,
            ],
            MediaType::Video => [
                'hls' => $this->access->urlFor($asset, FileRole::HlsMaster),
                'poster' => $this->access->urlFor($asset, FileRole::Poster),
            ],
            MediaType::Audio => [
                'audio' => $this->access->urlFor($asset, FileRole::Audio),
            ],
            default => [],
        };
    }
}
