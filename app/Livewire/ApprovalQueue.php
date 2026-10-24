<?php

namespace App\Livewire;

use App\Models\DigitalAsset;
use App\States\Asset\PendingApproval;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Approvals')]
class ApprovalQueue extends Component
{
    use WithPagination;

    public function boot(): void
    {
        $this->authorize('media.review');
    }

    public function render()
    {
        return view('livewire.approval-queue', [
            'assets' => DigitalAsset::query()
                ->whereState('state', PendingApproval::class)
                ->with(['currentVersion.files', 'submitter'])
                ->orderBy('submitted_at')
                ->paginate(20),
        ]);
    }
}
