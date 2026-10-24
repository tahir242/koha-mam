<?php

namespace App\Http\Controllers;

use App\Assets\UploadIntake;
use App\Assets\UploadRejectedException;
use App\Enums\Capability;
use App\Koha\Exceptions\KohaException;
use App\Koha\Exceptions\KohaRecordNotFoundException;
use App\Koha\ItemRepository;
use App\Models\DigitalAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\ContentRangeUploadHandler;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

/**
 * Resumable chunked uploads (Content-Range) via pion/laravel-chunk-upload,
 * so large video files are never pushed through one long request. Chunks
 * are assembled in private local storage; the completed file goes through
 * UploadIntake validation before anything else happens.
 */
class UploadController extends Controller
{
    public function store(Request $request, UploadIntake $intake, ItemRepository $items): JsonResponse
    {
        $validated = $request->validate([
            'biblio_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'replace' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:250'],
            'change_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $biblioId = (int) $validated['biblio_id'];
        $itemId = isset($validated['item_id']) ? (int) $validated['item_id'] : null;
        $replacing = null;

        if (! empty($validated['replace'])) {
            $replacing = DigitalAsset::where('uuid', $validated['replace'])->firstOrFail();
            Gate::authorize('replace', $replacing);

            if ($replacing->biblio_id !== $biblioId) {
                abort(422, 'The replacement does not belong to this record.');
            }

            if ($replacing->item_id !== $itemId) {
                abort(422, 'The replacement does not belong to this item.');
            }
        } else {
            Gate::authorize(Capability::Upload->value);

            if ($itemId !== null) {
                try {
                    // Cached after the first chunk; Koha is asked again only when the cache expires.
                    $items->find($itemId, $biblioId);
                } catch (KohaRecordNotFoundException) {
                    return response()->json(['message' => 'The Koha item no longer exists or belongs to another record.'], 422);
                } catch (KohaException) {
                    return response()->json(['message' => 'Koha is unreachable, so the item could not be checked. Try again shortly.'], 503);
                }
            }
        }

        $declaredTotal = $this->declaredTotal($request);

        if ($declaredTotal !== null && $declaredTotal > config('dam.upload.max_bytes')) {
            return response()->json(['message' => 'The file exceeds the maximum upload size.'], 413);
        }

        try {
            $receiver = new FileReceiver('file', $request, ContentRangeUploadHandler::class);
        } catch (UploadMissingFileException) {
            return response()->json(['message' => 'No file was received.'], 422);
        }

        $save = $receiver->receive();

        if (! $save->isFinished()) {
            return response()->json(['done' => false, 'progress' => $save->handler()->getPercentageDone()]);
        }

        $file = $save->getFile();

        try {
            $result = $intake->accept($file, $request->user(), $biblioId, $replacing, [
                'title' => $validated['title'] ?? null,
                'change_note' => $validated['change_note'] ?? null,
            ], $itemId);
        } catch (UploadRejectedException $e) {
            activity('asset')->causedBy($request->user())->event('upload_rejected')
                ->withProperties(['biblio_id' => $biblioId, 'item_id' => $itemId, 'reason' => $e->getMessage()])
                ->log('Upload rejected');

            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            @unlink($file->getPathname());
        }

        return response()->json([
            'done' => true,
            'asset' => $result['version']->asset->uuid,
            'version' => $result['version']->version_number,
            'warnings' => $result['warnings'],
        ], 201);
    }

    private function declaredTotal(Request $request): ?int
    {
        return preg_match('#/(\d+)$#', (string) $request->header('Content-Range'), $m) ? (int) $m[1] : null;
    }
}
