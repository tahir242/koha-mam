<?php

namespace App\Livewire;

use App\Assets\UploadIntake;
use App\Delivery\ViewCounter;
use App\Enums\AccessLevel;
use App\Enums\Capability;
use App\Enums\FileRole;
use App\Enums\MarcRelationship;
use App\Enums\VersionStatus;
use App\Koha\Marc\Managed856;
use App\Media\MediaTypeRegistry;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Publishing\AssetWorkflow;
use App\Publishing\PublishingPolicyResolver;
use App\Publishing\WorkflowException;
use App\States\Asset\PublicationPending;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class AssetDetail extends Component
{
    public DigitalAsset $asset;

    public string $title = '';

    public ?string $description = null;

    public string $access_level = 'public';

    public bool $is_sensitive = false;

    /** '' = follow policy, 'require' or 'exempt' */
    public string $approval_override = '';

    public string $marc_relationship = 'resource';

    public ?string $link_text = null;

    public ?string $public_note = null;

    public string $rejectReason = '';

    public string $tab = 'overview';

    public function mount(DigitalAsset $asset): void
    {
        $this->authorize('view', $asset);
        $this->asset = $asset;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->title = $this->asset->title;
        $this->description = $this->asset->description;
        $this->access_level = $this->asset->access_level->value;
        $this->is_sensitive = $this->asset->is_sensitive;
        $this->approval_override = match ($this->asset->requires_approval_override) {
            true => 'require',
            false => 'exempt',
            null => '',
        };
        $this->marc_relationship = $this->asset->marc_relationship->value;
        $this->link_text = $this->asset->link_text;
        $this->public_note = $this->asset->public_note;
    }

    public function save(AssetWorkflow $workflow): void
    {
        $this->authorize('update', $this->asset);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],
            'access_level' => ['required', Rule::enum(AccessLevel::class)],
            'is_sensitive' => ['boolean'],
            'approval_override' => ['in:,require,exempt'],
            'marc_relationship' => ['required', Rule::enum(MarcRelationship::class)],
            'link_text' => ['nullable', 'string', 'max:200'],
            'public_note' => ['nullable', 'string', 'max:500'],
        ]);

        $data['requires_approval_override'] = match ($data['approval_override']) {
            'require' => true,
            'exempt' => false,
            default => null,
        };
        unset($data['approval_override']);

        $this->run(fn () => $workflow->updateDetails($this->asset, auth()->user(), $data), 'Details saved.');
    }

    public function submit(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->submit($this->asset, auth()->user()), 'Submitted.');
    }

    public function publish(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->publish($this->asset, auth()->user()), 'Publication to Koha has started.');
    }

    public function approve(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->approve($this->asset, auth()->user()), 'Approved — publishing to Koha.');
    }

    public function reject(AssetWorkflow $workflow): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->run(fn () => $workflow->reject($this->asset, auth()->user(), $this->rejectReason), 'Returned to the uploader.');
        $this->rejectReason = '';
        Flux::modal('reject')->close();
    }

    public function unpublish(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->unpublish($this->asset, auth()->user()), 'Unpublished. Public access has ended; the Koha link is being removed.');
        Flux::modal('unpublish')->close();
    }

    public function retryPublication(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->retryPublication($this->asset, auth()->user()), 'Publication retried.');
    }

    public function retryProcessing(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->retryProcessing($this->asset, auth()->user()), 'Processing restarted.');
    }

    public function archive(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->archive($this->asset, auth()->user()), 'Archived.');
    }

    public function restore(AssetWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->restore($this->asset, auth()->user()), 'Restored.');
    }

    public function activateVersion(int $versionId, AssetWorkflow $workflow): void
    {
        $version = AssetVersion::where('digital_asset_id', $this->asset->id)->findOrFail($versionId);
        $this->run(fn () => $workflow->activateVersion($this->asset, $version, auth()->user()), "Version {$version->version_number} is now live.");
    }

    #[On('asset-uploaded')]
    public function versionUploaded(): void
    {
        $this->asset->refresh();
    }

    private function run(callable $action, string $success): void
    {
        try {
            $action();
            Flux::toast(variant: 'success', text: $success);
        } catch (WorkflowException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->asset->refresh();
        $this->fillForm();
    }

    public function render(PublishingPolicyResolver $policies, Managed856 $managed, MediaTypeRegistry $registry)
    {
        $asset = $this->asset->load(['currentVersion.files', 'creator', 'submitter', 'approver']);
        $user = auth()->user();
        $versions = $asset->versions()->with('uploader')->get();
        $busy = $asset->state->isProcessing()
            || $asset->state->equals(PublicationPending::class)
            || $versions->contains(fn ($v) => ! $v->status->isTerminal());

        return view('livewire.asset-detail', [
            'user' => $user,
            'versions' => $versions,
            'policy' => $policies->explain($asset, $user),
            'field856' => $managed->build($asset),
            'operations' => $asset->publicationOperations()->with('requester')->limit(10)->get(),
            'views' => $this->viewStats($asset),
            'history' => Activity::with('causer')->where('subject_type', $asset->getMorphClass())->where('subject_id', $asset->id)->latest()->limit(30)->get(),
            'busy' => $busy,
            'thumb' => $asset->currentVersion?->file(FileRole::Poster) ?? $asset->currentVersion?->file(FileRole::ImageMedium) ?? $asset->currentVersion?->file(FileRole::Thumbnail),
            'canReplace' => $user->can('replace', $asset),
            'uploader' => [
                'endpoint' => route('uploads.store'),
                'biblioId' => $asset->biblio_id,
                'itemId' => $asset->item_id,
                'replace' => $asset->uuid,
                'csrf' => csrf_token(),
                'chunkBytes' => UploadIntake::chunkBytes(),
                'maxBytes' => (int) config('dam.upload.max_bytes'),
                'extensions' => $registry->extensionsByType()[$asset->media_type->value] ?? [],
            ],
            'pendingActivation' => $versions->first(fn ($v) => $v->status === VersionStatus::Ready && $v->is_viewable && $v->id !== $asset->current_version_id && $v->activated_at === null),
            'can' => fn (Capability $c) => $user->hasCapability($c),
        ])->title($asset->title);
    }

    /**
     * View analytics for the side panel: counted views per day (last 14 days)
     * and 30-day totals of views, unique viewers, page opens and staff views.
     */
    private function viewStats(DigitalAsset $asset): array
    {
        $since = now()->subDays(30);
        $events = $asset->accessEvents()->where('created_at', '>=', $since);

        $perDay = $asset->accessEvents()->where('event', 'view')->where('counted', true)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at'])->countBy(fn ($e) => $e->created_at->toDateString());

        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $daily[$day] = $perDay[$day] ?? 0;
        }

        return [
            'daily' => $daily,
            'peak' => max($daily),
            'views_30d' => (clone $events)->where('event', 'view')->where('counted', true)->count(),
            'unique_30d' => (clone $events)->where('event', 'view')->where('counted', true)->distinct()->count('visitor_hash'),
            'opens_30d' => (clone $events)->where('event', 'open')->where('is_staff', false)->count(),
            'staff_30d' => (clone $events)->where('event', 'view')->where('is_staff', true)->count(),
            'qualifying' => app(ViewCounter::class)->qualifyingSeconds($asset),
        ];
    }
}
