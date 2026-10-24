<?php

namespace App\Livewire;

use App\Enums\Capability;
use App\Enums\PublicationStatus;
use App\Jobs\ProcessPublicationOperation;
use App\Models\PublicationOperation;
use App\Publishing\AssetWorkflow;
use App\States\Asset\PublicationFailed;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The publication outbox: every Koha 856 change, its attempts and outcome.
 */
#[Title('Koha publishing')]
class Publications extends Component
{
    use WithPagination;

    #[Url(except: 'attention')]
    public string $filter = 'attention';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    /** Re-queue a failed operation (e.g. after fixing API credentials). */
    public function retry(int $id): void
    {
        $this->authorize(Capability::Publish->value);

        $operation = PublicationOperation::findOrFail($id);

        if (! in_array($operation->status, [PublicationStatus::Failed, PublicationStatus::NeedsReview], true)) {
            return;
        }

        $asset = $operation->asset;

        // Link retries go through the workflow so the asset state is correct.
        if ($operation->action === PublicationOperation::ACTION_LINK && $asset->state->equals(PublicationFailed::class)) {
            app(AssetWorkflow::class)->retryPublication($asset, auth()->user());
        } else {
            $operation->update(['status' => PublicationStatus::Pending, 'available_at' => now(), 'attempts' => 0, 'last_error' => null]);
            ProcessPublicationOperation::dispatch($operation->id);
        }

        activity('publication')->causedBy(auth()->user())->performedOn($asset)->event('publication_retry')->log('Koha operation retried manually');
        Flux::toast(variant: 'success', text: 'Operation queued.');
    }

    public function render()
    {
        $operations = PublicationOperation::query()
            ->with(['asset', 'requester'])
            ->when($this->filter === 'attention', fn ($q) => $q->whereIn('status', [PublicationStatus::Failed->value, PublicationStatus::NeedsReview->value, PublicationStatus::Pending->value, PublicationStatus::Running->value]))
            ->when($this->filter === 'succeeded', fn ($q) => $q->where('status', PublicationStatus::Succeeded->value))
            ->latest('id')
            ->paginate(25);

        return view('livewire.publications', [
            'operations' => $operations,
            'counts' => PublicationOperation::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
