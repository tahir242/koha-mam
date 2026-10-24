<?php

namespace App\Livewire;

use App\Enums\MediaType;
use App\Models\DigitalAsset;
use App\States\Asset\AssetState;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Asset library')]
class AssetLibrary extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $access = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    public function updating($property): void
    {
        if (in_array($property, ['search', 'type', 'state', 'access', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'state', 'access', 'sort');
        $this->resetPage();
    }

    public function render()
    {
        $states = AssetState::all()->mapWithKeys(fn (string $class) => [$class::getMorphClass() => (new $class(new DigitalAsset))->label()]);

        $assets = DigitalAsset::query()
            ->with(['currentVersion.files', 'creator'])
            ->when($this->search !== '', function (Builder $q) {
                $term = trim($this->search);

                $q->where(function (Builder $q) use ($term) {
                    if (ctype_digit($term)) {
                        $q->orWhere('biblio_id', (int) $term)->orWhere('item_id', (int) $term);
                    }

                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                    $q->orWhere('title', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('biblio_snapshot', 'like', $like)
                        // Item barcode / call number.
                        ->orWhere('item_snapshot', 'like', $like)
                        ->orWhere('uuid', $term);
                });
            })
            ->when(MediaType::tryFrom($this->type), fn (Builder $q, MediaType $type) => $q->where('media_type', $type->value))
            ->when($states->has($this->state), fn (Builder $q) => $q->where('state', $this->state))
            ->when(in_array($this->access, ['public', 'restricted'], true), fn (Builder $q) => $q->where('access_level', $this->access))
            ->when($this->sort, fn (Builder $q) => match ($this->sort) {
                'oldest' => $q->oldest(),
                'title' => $q->orderBy('title'),
                'record' => $q->orderBy('biblio_id')->orderBy('title'),
                'updated' => $q->latest('updated_at'),
                'views' => $q->orderByDesc('view_count')->latest(),
                default => $q->latest(),
            })
            ->paginate($this->view === 'grid' ? 24 : 30);

        return view('livewire.asset-library', [
            'assets' => $assets,
            'states' => $states,
            'types' => MediaType::cases(),
        ]);
    }
}
