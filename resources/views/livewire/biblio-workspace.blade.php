<div @if ($processing) wire:poll.5s @endif class="fade-in">
    <nav class="mb-6 flex items-center gap-2 text-sm text-zinc-500" aria-label="Breadcrumb">
        <a href="{{ route('library') }}" wire:navigate class="hover:text-brand">Library</a>
        <flux:icon name="chevron-right" variant="micro" class="size-3.5" />
        @if ($item)
            <a href="{{ route('biblios.show', $biblio) }}" wire:navigate class="hover:text-brand">Koha record #{{ $biblio }}</a>
            <flux:icon name="chevron-right" variant="micro" class="size-3.5" />
            <span class="text-zinc-700">{{ $itemLabel }}</span>
        @else
            <span class="text-zinc-700">Koha record #{{ $biblio }}</span>
        @endif
    </nav>

    @if ($notFound)
        <div class="card">
            <x-empty-state icon="document-magnifying-glass" title="Record not found in Koha" description="Bibliographic record #{{ $biblio }} does not exist or has been deleted. Assets already attached to it are listed below and cannot be published." />
        </div>
    @else
        {{-- Record header --}}
        <section class="card overflow-hidden" aria-labelledby="record-title">
            <div class="flex flex-col gap-6 p-6 sm:flex-row sm:p-8">
                <div class="relative h-44 w-32 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br from-brand to-brand-900 shadow-lg ring-1 ring-black/5">
                    {{-- A missing cover simply leaves the branded placeholder visible. --}}
                    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ route('covers.show', $biblio) }}')" aria-hidden="true"></div>
                    <div class="flex h-full flex-col justify-end p-3 text-white/90">
                        <flux:icon name="book-open" variant="outline" class="mb-2 size-6 opacity-60" />
                        <span class="line-clamp-3 font-serif text-xs leading-snug">{{ $meta?->title ?? 'Record '.$biblio }}</span>
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="eyebrow">Koha bibliographic record</span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 font-mono text-xs text-zinc-600">#{{ $biblio }}</span>
                        @if ($meta?->fromCache)
                            <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-700" title="Fetched {{ $meta->fetchedAt->diffForHumans() }}">
                                <flux:icon name="clock" variant="micro" class="size-3" /> Cached metadata
                            </span>
                        @endif
                    </div>
                    <h1 id="record-title" class="mt-2 font-serif text-2xl font-semibold leading-tight text-zinc-900 sm:text-3xl">{{ $meta?->fullTitle() ?? 'Metadata unavailable' }}</h1>
                    @if ($meta?->author)
                        <p class="mt-1.5 text-base text-zinc-600">{{ $meta->author }}</p>
                    @endif

                    <dl class="mt-5 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-zinc-500">Edition</dt><dd class="font-medium text-zinc-800">{{ $meta?->edition ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Publication</dt><dd class="font-medium text-zinc-800">{{ $meta?->publication() ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">ISBN</dt><dd class="font-mono text-zinc-800">{{ $meta?->isbn ?? '—' }}</dd></div>
                    </dl>

                    @if ($kohaError)
                        <x-notice variant="warning" icon="exclamation-triangle" class="mt-5" heading="Koha is currently unreachable">
                            Showing the last known metadata. Uploads still work; publication will wait until Koha is available.
                        </x-notice>
                    @endif

                    <div class="mt-6 flex flex-wrap gap-2">
                        @if (config('dam.koha.staff_url'))
                            <flux:button size="sm" icon="arrow-uturn-left" href="{{ config('dam.koha.staff_url') }}/cgi-bin/koha/catalogue/detail.pl?biblionumber={{ $biblio }}">Return to Koha record</flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshMetadata" wire:loading.attr="disabled">Refresh from Koha</flux:button>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($item)
        {{-- Opened from a Koha item: uploads attach to this copy of the record. --}}
        <section class="card mt-6 flex flex-wrap items-center gap-4 border-l-4 border-sky-500 p-5" aria-labelledby="item-heading">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700"><flux:icon name="tag" variant="outline" class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <span class="eyebrow">Koha item</span>
                <h2 id="item-heading" class="font-semibold text-zinc-900">{{ $itemLabel }}</h2>
                <p class="mt-0.5 text-sm text-zinc-600">{{ $itemDetails ?? 'No barcode yet' }}</p>
                <p class="mt-1 text-xs text-zinc-500">
                    @if ($itemNotFound)
                        This item no longer exists in Koha or now belongs to another record. Its media stays listed; new uploads go to the record.
                    @elseif ($itemError)
                        Koha is unreachable; showing the last known item details.
                    @else
                        Uploads here belong to this item. Once published they appear in the item's URL (952$u) in the holdings table, staff and OPAC.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if (config('dam.koha.staff_url') && ! $itemNotFound)
                    <flux:button size="sm" icon="arrow-uturn-left" href="{{ config('dam.koha.staff_url') }}/cgi-bin/koha/cataloguing/additem.pl?op=edititem&biblionumber={{ $biblio }}&itemnumber={{ $item }}#edititem">Return to Koha item</flux:button>
                @endif
                <flux:button size="sm" variant="ghost" href="{{ route('biblios.show', $biblio) }}" wire:navigate>Work on the whole record</flux:button>
            </div>
        </section>
    @endif

    <div class="mt-6 grid gap-6 xl:grid-cols-5">
        {{-- Assets --}}
        <section class="xl:col-span-3 space-y-6" aria-labelledby="assets-heading">
            @if ($item)
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 id="assets-heading" class="font-semibold text-zinc-900">Media of this item <span class="ml-1 text-sm font-normal text-zinc-400">{{ $itemAssets->count() }}</span></h2>
                    </div>
                    <div class="card divide-y divide-zinc-100">
                        @forelse ($itemAssets as $asset)
                            <x-workspace-asset-row :asset="$asset" wire:key="asset-{{ $asset->id }}" />
                        @empty
                            <x-empty-state icon="photo" title="No media for this item yet" description="Upload files here to attach them to {{ $itemLabel }}." />
                        @endforelse
                    </div>
                </div>
            @endif
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h2 @unless ($item) id="assets-heading" @endunless class="font-semibold text-zinc-900">{{ $item ? 'Other media of this record' : 'Media assets' }} <span class="ml-1 text-sm font-normal text-zinc-400">{{ $otherAssets->count() }}</span></h2>
                </div>
                <div class="card divide-y divide-zinc-100">
                    @forelse ($otherAssets as $asset)
                        <x-workspace-asset-row :asset="$asset" wire:key="asset-{{ $asset->id }}" />
                    @empty
                        <x-empty-state icon="photo" title="No media assets yet" description="Upload documents, images, audio or video for this record. Files are validated, scanned and processed before anyone can view them." />
                    @endforelse
                </div>
            </div>
        </section>

        {{-- Uploader --}}
        <section class="xl:col-span-2" aria-labelledby="upload-heading">
            <h2 id="upload-heading" class="mb-3 font-semibold text-zinc-900">{{ $uploadToItem ? 'Upload media for '.$itemLabel : 'Upload media' }}</h2>
            @can('media.upload')
                <div class="card p-5" x-data="chunkedUploader(@js($uploader))" wire:ignore>
                    <label
                        @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop($event)"
                        :class="dragging ? 'border-brand bg-brand-50' : 'border-zinc-300 bg-zinc-50/60 hover:border-brand/60 hover:bg-brand-50/40'"
                        class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition">
                        <span class="mb-3 grid size-12 place-items-center rounded-2xl bg-white text-brand shadow-sm ring-1 ring-zinc-200">
                            <flux:icon name="cloud-arrow-up" variant="outline" class="size-6" />
                        </span>
                        <span class="text-sm font-medium text-zinc-900">Drop files here or <span class="text-brand underline-offset-2 hover:underline">browse</span></span>
                        <span class="mt-1 text-xs text-zinc-500">Up to {{ \Illuminate\Support\Number::fileSize(config('dam.upload.max_bytes')) }} per file · multiple files allowed</span>
                        <input type="file" multiple class="sr-only" @change="pick($event)" accept="{{ collect($uploader['extensions'])->map(fn ($e) => '.'.$e)->implode(',') }}">
                    </label>

                    <template x-if="hasItems">
                        <ul class="mt-4 space-y-2" aria-live="polite">
                            <template x-for="item in items" :key="item.id">
                                <li class="rounded-xl border border-zinc-200 p-3">
                                    <div class="flex items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-zinc-800" x-text="item.name"></p>
                                            <p class="text-xs text-zinc-500"><span x-text="humanSize(item.size)"></span> · <span x-text="item.status === 'uploading' ? item.progress + '%' : item.status"></span></p>
                                        </div>
                                        <button type="button" x-show="['queued','uploading'].includes(item.status)" @click="cancel(item)" class="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700" aria-label="Cancel upload">
                                            <flux:icon name="x-mark" variant="mini" class="size-4" />
                                        </button>
                                        <flux:icon x-show="item.status === 'done'" name="check-circle" variant="solid" class="size-5 text-emerald-500" />
                                        <flux:icon x-show="item.status === 'error'" name="exclamation-circle" variant="solid" class="size-5 text-red-500" />
                                    </div>
                                    <div x-show="item.status === 'uploading'" class="mt-2 h-1 overflow-hidden rounded-full bg-zinc-100">
                                        <div class="h-full rounded-full bg-brand transition-all" :style="`width: ${item.progress}%`"></div>
                                    </div>
                                    <p x-show="item.message" x-text="item.message" class="mt-1.5 text-xs" :class="item.status === 'error' ? 'text-red-600' : 'text-zinc-500'"></p>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <div x-show="hasItems && !busy" class="mt-3 text-right">
                        <button type="button" @click="clearFinished()" class="text-xs font-medium text-zinc-500 hover:text-zinc-800">Clear finished</button>
                    </div>

                    <details class="mt-5 text-xs text-zinc-500">
                        <summary class="cursor-pointer font-medium text-zinc-600">Supported formats</summary>
                        <dl class="mt-2 space-y-1">
                            @foreach ($extensionsByType as $type => $exts)
                                <div class="flex gap-2"><dt class="w-20 shrink-0 capitalize text-zinc-700">{{ $type }}</dt><dd>{{ implode(', ', $exts) }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </div>
            @else
                <div class="card"><x-empty-state icon="lock-closed" title="Uploading is not permitted for your role" /></div>
            @endcan
        </section>
    </div>
</div>
