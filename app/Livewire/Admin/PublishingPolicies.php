<?php

namespace App\Livewire\Admin;

use App\Enums\AccessLevel;
use App\Enums\MediaType;
use App\Models\PublishingPolicy;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Publishing policies')]
class PublishingPolicies extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?string $description = null;

    public string $role = '';

    public string $media_type = '';

    public string $access_level = '';

    public string $sensitive = '';

    public bool $requires_approval = true;

    public int $priority = 100;

    public bool $is_active = true;

    public function boot(): void
    {
        $this->authorize('media.manage_settings');
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'description', 'role', 'media_type', 'access_level', 'sensitive', 'requires_approval', 'priority', 'is_active');
        $this->resetValidation();
        Flux::modal('policy')->show();
    }

    public function edit(int $id): void
    {
        $p = PublishingPolicy::findOrFail($id);
        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->description = $p->description;
        $this->role = $p->role ?? '';
        $this->media_type = $p->media_type ?? '';
        $this->access_level = $p->access_level ?? '';
        $this->sensitive = $p->applies_to_sensitive === null ? '' : ($p->applies_to_sensitive ? 'yes' : 'no');
        $this->requires_approval = $p->requires_approval;
        $this->priority = $p->priority;
        $this->is_active = $p->is_active;
        Flux::modal('policy')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'role' => ['nullable', Rule::in(array_keys(config('dam.authorization.roles')))],
            'media_type' => ['nullable', Rule::enum(MediaType::class)],
            'access_level' => ['nullable', Rule::enum(AccessLevel::class)],
            'sensitive' => ['in:,yes,no'],
            'requires_approval' => ['boolean'],
            'priority' => ['integer', 'min:0', 'max:10000'],
            'is_active' => ['boolean'],
        ]);

        PublishingPolicy::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'description' => $data['description'],
            'role' => $data['role'] ?: null,
            'media_type' => $data['media_type'] ?: null,
            'access_level' => $data['access_level'] ?: null,
            'applies_to_sensitive' => match ($data['sensitive']) {
                'yes' => true, 'no' => false, default => null
            },
            'requires_approval' => $data['requires_approval'],
            'priority' => $data['priority'],
            'is_active' => $data['is_active'],
        ]);

        Flux::modal('policy')->close();
        Flux::toast(variant: 'success', text: 'Policy saved.');
    }

    public function delete(int $id): void
    {
        PublishingPolicy::findOrFail($id)->delete();
        Flux::toast(text: 'Policy deleted.');
    }

    public function render()
    {
        return view('livewire.admin.publishing-policies', [
            'policies' => PublishingPolicy::orderByDesc('priority')->orderBy('name')->get(),
            'roles' => collect(config('dam.authorization.roles'))->map(fn ($r) => $r['label']),
            'types' => MediaType::cases(),
            'default' => config('dam.publishing.default_requires_approval'),
        ]);
    }
}
