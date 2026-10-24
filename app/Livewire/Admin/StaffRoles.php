<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * MAM role assignment for Koha staff identities. Identities are created on
 * first SSO login; Koha remains the source of truth for who they are.
 */
#[Title('Staff & roles')]
class StaffRoles extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function boot(): void
    {
        $this->authorize('media.manage_settings');
    }

    public function setRole(int $userId, string $role): void
    {
        validator(['role' => $role], ['role' => ['required', Rule::in(array_keys(config('dam.authorization.roles')))]])->validate();

        $user = User::findOrFail($userId);

        if ($user->is(auth()->user()) && $role !== 'administrator') {
            Flux::toast(variant: 'danger', text: 'You cannot remove your own administrator role.');

            return;
        }

        if (in_array($user->koha_userid, config('dam.authorization.bootstrap_admins'), true)) {
            Flux::toast(variant: 'warning', text: 'This account is a bootstrap administrator (DAM_BOOTSTRAP_ADMINS) and regains the role at next sign-in.');
        }

        $user->update(['role' => $role, 'is_active' => $role !== 'suspended']);
        Flux::toast(variant: 'success', text: "{$user->name()} is now {$user->roleLabel()}.");
    }

    public function render()
    {
        return view('livewire.admin.staff-roles', [
            'users' => User::query()
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('koha_userid', 'like', "%{$this->search}%")->orWhere('display_name', 'like', "%{$this->search}%")))
                ->orderByDesc('last_authenticated_at')
                ->paginate(25),
            'roles' => collect(config('dam.authorization.roles')),
        ]);
    }
}
