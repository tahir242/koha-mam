<?php

namespace App\Http\Controllers;

use App\Delivery\MediaAccess;
use App\Delivery\MediaResponder;
use App\Enums\FileRole;
use App\Models\AssetFile;
use App\Models\DigitalAsset;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed, authorised delivery of viewing derivatives. There is no route
 * that serves an original file, and every URL expires.
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaAccess $access, private readonly MediaResponder $responder) {}

    public function file(Request $request, DigitalAsset $asset, AssetFile $file): Response
    {
        $this->authorizeFile($request, $asset, $file);

        abort_if($file->role === FileRole::HlsMaster, 404);

        return $this->responder->file($request, $file);
    }

    public function hls(Request $request, DigitalAsset $asset, AssetFile $file, string $path): Response
    {
        $this->authorizeFile($request, $asset, $file);

        abort_unless($file->role === FileRole::HlsMaster && MediaResponder::isAllowedHlsPath($path), 404);

        if (str_ends_with($path, '.m3u8')) {
            return $this->responder->playlist($asset, $file, $path);
        }

        return $this->responder->file($request, $file, $path, 'video/mp2t');
    }

    private function authorizeFile(Request $request, DigitalAsset $asset, AssetFile $file): void
    {
        // Not-found rather than forbidden, so unpublished assets cannot be probed.
        abort_unless($this->access->canViewFile($asset, $file, $request->user()), 404);

        if (config('dam.delivery.block_cross_site') && $request->header('Sec-Fetch-Site') === 'cross-site') {
            abort(403);
        }
    }
}
