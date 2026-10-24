<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $log = '';

    #[Url(except: '')]
    public string $search = '';

    public function boot(): void
    {
        $this->authorize('media.view_audit');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.audit-log', [
            'entries' => Activity::query()
                ->with(['causer', 'subject'])
                ->when($this->log, fn ($q) => $q->where('log_name', $this->log))
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('event', 'like', "%{$this->search}%")))
                ->latest('id')
                ->paginate(40),
            'logs' => ['auth' => 'Authentication', 'asset' => 'Assets', 'workflow' => 'Workflow', 'publication' => 'Koha publication', 'processing' => 'Processing', 'security' => 'Security', 'settings' => 'Settings', 'identity' => 'Identities'],
        ]);
    }
}
