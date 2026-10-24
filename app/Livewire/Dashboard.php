<?php

namespace App\Livewire;

use App\Enums\PublicationStatus;
use App\Enums\VersionStatus;
use App\Koha\KohaClient;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\MediaAccessEvent;
use App\Models\PublicationOperation;
use App\Models\StorageBackend;
use App\States\Asset\PendingApproval;
use App\States\Asset\PublicationFailed;
use App\States\Asset\Published;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

#[Title('Overview')]
class Dashboard extends Component
{
    public function refreshKoha(): void
    {
        Cache::forget('dam:koha-status');
    }

    public function render()
    {
        $kohaStatus = Cache::remember('dam:koha-status', 300, fn () => app(KohaClient::class)->ping());

        $byState = DigitalAsset::query()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');

        return view('livewire.dashboard', [
            'totals' => [
                'all' => $byState->sum(),
                'published' => $byState[Published::$name] ?? 0,
                'pending' => $byState[PendingApproval::$name] ?? 0,
                'failed_publications' => $byState[PublicationFailed::$name] ?? 0,
                'views_30d' => MediaAccessEvent::where('event', 'view')->where('counted', true)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'mostViewed' => DigitalAsset::with('currentVersion.files')->where('view_count', '>', 0)->orderByDesc('view_count')->limit(5)->get(),
            'recent' => DigitalAsset::with('currentVersion.files', 'creator')->latest()->limit(6)->get(),
            'processing' => AssetVersion::with('asset')
                ->whereIn('status', [VersionStatus::Received->value, VersionStatus::Validating->value, VersionStatus::Scanning->value, VersionStatus::Processing->value])
                ->latest()->limit(8)->get(),
            'failedProcessing' => AssetVersion::with('asset')->where('status', VersionStatus::Failed->value)->where('updated_at', '>=', now()->subDays(14))->latest()->limit(5)->get(),
            'failedOps' => PublicationOperation::with('asset')->whereIn('status', [PublicationStatus::Failed->value, PublicationStatus::NeedsReview->value])->latest()->limit(5)->get(),
            'storage' => StorageBackend::query()->active()->get()->map(fn (StorageBackend $b) => [
                'backend' => $b,
                'used' => $b->usedBytes(),
            ]),
            'activity' => Activity::with('causer')->latest()->limit(8)->get(),
            'koha' => $kohaStatus,
        ]);
    }
}
