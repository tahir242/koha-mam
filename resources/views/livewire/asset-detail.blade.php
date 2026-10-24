@php
    use App\Enums\Capability;
    use App\States\Asset;
    $state = $asset->state;
@endphp
<div @if ($busy) wire:poll.5s @endif class="fade-in">
    <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-zinc-500" aria-label="Breadcrumb">
        <a href="{{ route('library') }}" wire:navigate class="hover:text-brand">Library</a>
        <flux:icon name="chevron-right" variant="micro" class="size-3.5" />
        <a href="{{ route('biblios.show', $asset->biblio_id) }}" wire:navigate class="max-w-xs truncate hover:text-brand">{{ $asset->snapshot('title', 'Record #'.$asset->biblio_id) }}</a>
        @if ($asset->isItemLevel())
            <flux:icon name="chevron-right" variant="micro" class="size-3.5" />
            <a href="{{ route('biblios.show', ['biblio' => $asset->biblio_id, 'item' => $asset->item_id]) }}" wire:navigate class="truncate hover:text-brand">{{ $asset->itemLabel() }}</a>
        @endif
        <flux:icon name="chevron-right" variant="micro" class="size-3.5" />
        <span class="truncate text-zinc-700">{{ $asset->title }}</span>
    </nav>

    <x-page-header :title="$asset->title" :eyebrow="$asset->media_type->label().' · Koha record #'.$asset->biblio_id.($asset->isItemLevel() ? ' · '.$asset->itemLabel() : '')">
        <x-slot:actions>
            <x-status-badge :state="$state" class="mr-1" />

            @if ($state->equals(Asset\ReadyForReview::class, Asset\Unpublished::class) && $can(Capability::Submit))
                @if ($policy['requires_approval'])
                    <flux:button variant="primary" icon="paper-airplane" wire:click="submit">Submit for approval</flux:button>
                @else
                    <flux:button variant="primary" icon="globe-alt" wire:click="submit" wire:confirm="Publish this resource and add its link to the Koha record?">Publish</flux:button>
                @endif
            @endif

            @if ($state->equals(Asset\PendingApproval::class))
                @if ($can(Capability::Review))
                    <flux:modal.trigger name="reject"><flux:button icon="arrow-uturn-left">Reject</flux:button></flux:modal.trigger>
                @endif
                @if ($can(Capability::Approve))
                    <flux:button variant="primary" icon="check" wire:click="approve" wire:confirm="Approve and publish this resource to Koha?">Approve &amp; publish</flux:button>
                @endif
            @endif

            @if ($state->equals(Asset\Approved::class) && $can(Capability::Publish))
                <flux:button variant="primary" icon="globe-alt" wire:click="publish">Publish now</flux:button>
            @endif

            @if ($state->equals(Asset\PublicationFailed::class) && $can(Capability::Publish))
                <flux:button variant="primary" icon="arrow-path" wire:click="retryPublication">Retry publication</flux:button>
            @endif

            @if ($state->equals(Asset\ProcessingFailed::class) && $can(Capability::Edit))
                <flux:button variant="primary" icon="arrow-path" wire:click="retryProcessing">Retry processing</flux:button>
            @endif

            @if ($state->equals(Asset\Published::class, Asset\PublicationPending::class, Asset\PublicationFailed::class) && $can(Capability::Unpublish))
                <flux:modal.trigger name="unpublish"><flux:button variant="danger" icon="eye-slash">Unpublish</flux:button></flux:modal.trigger>
            @endif

            @if ($asset->currentVersion?->is_viewable)
                <flux:button icon="arrow-top-right-on-square" href="{{ route('viewer.show', $asset->uuid) }}" target="_blank">{{ $asset->isPublished() ? 'Public view' : 'Preview' }}</flux:button>
            @endif

            @if ($can(Capability::Delete) && ($state->equals(Asset\ReadyForReview::class, Asset\ProcessingFailed::class, Asset\Unpublished::class, Asset\PublicationFailed::class) || $state->equals(Asset\Archived::class)))
                <flux:dropdown align="end">
                    <flux:button icon="ellipsis-horizontal" aria-label="More actions" />
                    <flux:menu>
                        @if ($state->equals(Asset\Archived::class))
                            <flux:menu.item icon="arrow-uturn-up" wire:click="restore">Restore</flux:menu.item>
                        @else
                            <flux:menu.item icon="archive-box" wire:click="archive" wire:confirm="Archive this resource? It will be hidden from active lists.">Archive</flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($asset->rejection_reason && $state->equals(Asset\ReadyForReview::class))
        <x-notice variant="warning" icon="chat-bubble-left-ellipsis" class="mb-6" heading="Returned by reviewer">
            {{ $asset->rejection_reason }}
        </x-notice>
    @endif

    @if ($pendingActivation)
        <x-notice icon="sparkles" class="mb-6" heading="Replacement version {{ $pendingActivation->version_number }} is ready">
            The public link keeps serving the current version until the replacement is activated. The Koha {{ $asset->isItemLevel() ? 'item URL (952$u)' : '856 link' }} does not change.
            @if ($can(Capability::Publish))
                <x-slot name="actions"><flux:button size="sm" variant="primary" wire:click="activateVersion({{ $pendingActivation->id }})">Make version {{ $pendingActivation->version_number }} live</flux:button></x-slot>
            @endif
        </x-notice>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Preview --}}
            <section class="card overflow-hidden">
                <div class="relative grid aspect-[16/8] place-items-center bg-zinc-900">
                    @if ($thumb)
                        <img src="{{ app(\App\Delivery\MediaAccess::class)->signedFileUrl($asset, $thumb) }}" alt="Preview of {{ $asset->title }}" class="size-full object-contain" draggable="false">
                    @else
                        <div class="text-center text-zinc-400">
                            <flux:icon :name="$asset->media_type->icon()" variant="outline" class="mx-auto size-10" />
                            <p class="mt-2 text-sm">{{ $asset->currentVersion ? 'No preview available' : 'Preview will appear after processing' }}</p>
                        </div>
                    @endif
                    @if ($asset->access_level === \App\Enums\AccessLevel::Restricted || $asset->is_sensitive)
                        <div class="absolute left-4 top-4 flex gap-2">
                            @if ($asset->access_level === \App\Enums\AccessLevel::Restricted)<span class="inline-flex items-center gap-1 rounded-md bg-white/90 px-2 py-1 text-xs font-medium text-zinc-800"><flux:icon name="lock-closed" variant="micro" class="size-3" /> Restricted</span>@endif
                            @if ($asset->is_sensitive)<span class="inline-flex items-center gap-1 rounded-md bg-brand-red px-2 py-1 text-xs font-medium text-white"><flux:icon name="shield-exclamation" variant="micro" class="size-3" /> Sensitive</span>@endif
                        </div>
                    @endif
                </div>
                @if ($asset->currentVersion)
                    @php $m = $asset->currentVersion->metadata ?? []; @endphp
                    <dl class="grid grid-cols-2 gap-4 border-t border-zinc-100 p-5 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-zinc-500">Live version</dt><dd class="font-medium">v{{ $asset->currentVersion->version_number }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Original size</dt><dd class="font-medium">{{ \Illuminate\Support\Number::fileSize($asset->currentVersion->size_bytes, 1) }}</dd></div>
                        @isset($m['pages'])<div><dt class="text-xs text-zinc-500">Pages</dt><dd class="font-medium">{{ $m['pages'] }}</dd></div>@endisset
                        @isset($m['duration'])<div><dt class="text-xs text-zinc-500">Duration</dt><dd class="font-medium">{{ gmdate($m['duration'] >= 3600 ? 'H:i:s' : 'i:s', (int) $m['duration']) }}</dd></div>@endisset
                        @isset($m['width'])<div><dt class="text-xs text-zinc-500">Dimensions</dt><dd class="font-medium">{{ $m['width'] }} × {{ $m['height'] }}</dd></div>@endisset
                        <div><dt class="text-xs text-zinc-500">Format</dt><dd class="font-mono text-xs">{{ $asset->currentVersion->detected_mime }}</dd></div>
                    </dl>
                @endif
            </section>

            {{-- Tabs --}}
            <div class="card">
                <div class="flex gap-1 overflow-x-auto border-b border-zinc-100 px-4" role="tablist">
                    @foreach (['overview' => 'Details', 'versions' => 'Versions', 'koha' => 'Koha link', 'history' => 'History'] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                                @class(['-mb-px border-b-2 px-3 py-3.5 text-sm font-medium transition whitespace-nowrap', 'border-brand text-brand' => $tab === $key, 'border-transparent text-zinc-500 hover:text-zinc-800' => $tab !== $key])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="p-6">
                    @if ($tab === 'overview')
                        <form wire:submit="save" class="space-y-5">
                            <flux:input wire:model="title" label="Title" required :disabled="! $user->can('update', $asset)" />
                            <flux:textarea wire:model="description" label="Description" rows="3" description="Shown to staff and on the public viewer." :disabled="! $user->can('update', $asset)" />

                            <div class="grid gap-5 sm:grid-cols-2">
                                <flux:select wire:model="access_level" label="Access" :disabled="! $user->can('update', $asset)">
                                    @foreach (\App\Enums\AccessLevel::cases() as $level)
                                        <flux:select.option value="{{ $level->value }}">{{ $level->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="approval_override" label="Approval rule" :disabled="! $can(Capability::ManageSettings) && ! $can(Capability::Approve)">
                                    <flux:select.option value="">Follow publishing policy</flux:select.option>
                                    <flux:select.option value="require">Always require approval</flux:select.option>
                                    @if ($can(Capability::ManageSettings))
                                        <flux:select.option value="exempt">Exempt from approval</flux:select.option>
                                    @endif
                                </flux:select>
                            </div>

                            <flux:checkbox wire:model="is_sensitive" label="Sensitive resource" description="Always requires review before publication. Marking a published resource as sensitive unpublishes it." :disabled="! $user->can('update', $asset)" />

                            {{-- Item media is linked through the item's URI (952$u); the 856 settings do not apply. --}}
                            @unless ($asset->isItemLevel())
                                <flux:separator text="Koha 856 link" />

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <flux:select wire:model="marc_relationship" label="Relationship to the record" :disabled="! $user->can('update', $asset)">
                                        @foreach (\App\Enums\MarcRelationship::cases() as $rel)
                                            <flux:select.option value="{{ $rel->value }}">{{ $rel->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:input wire:model="link_text" label="Link text ($y)" :placeholder="config('dam.publishing.marc.link_text')" :disabled="! $user->can('update', $asset)" />
                                </div>
                                <flux:input wire:model="public_note" label="Public note ($z)" description="Optional note shown with the link in the catalogue." :disabled="! $user->can('update', $asset)" />
                            @endunless

                            @can('update', $asset)
                                <div class="flex items-center justify-end gap-3 pt-2">
                                    @if ($asset->isPublished() && ! $asset->isItemLevel())
                                        <span class="text-xs text-zinc-500">Link changes are re-published to Koha automatically.</span>
                                    @endif
                                    <flux:button type="submit" variant="primary">Save changes</flux:button>
                                </div>
                            @endcan
                        </form>
                    @elseif ($tab === 'versions')
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead><tr><th>Version</th><th>Status</th><th>File</th><th>Uploaded</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($versions as $v)
                                        <tr wire:key="v-{{ $v->id }}">
                                            <td class="font-medium">
                                                v{{ $v->version_number }}
                                                @if ($v->id === $asset->current_version_id)<span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">Live</span>@endif
                                            </td>
                                            <td>
                                                <span class="text-sm capitalize">{{ $v->status->value }}</span>
                                                @if (! $v->status->isTerminal())
                                                    <span class="text-xs text-zinc-500">· {{ $v->processing_progress }}% {{ str_replace('_', ' ', $v->processing_stage ?? '') }}</span>
                                                @endif
                                                @if ($v->processing_error)
                                                    <p class="mt-1 max-w-sm text-xs text-red-600">{{ $v->processing_error }}</p>
                                                @endif
                                            </td>
                                            <td class="text-xs text-zinc-600">
                                                <span class="block max-w-[220px] truncate" title="{{ $v->original_filename }}">{{ $v->original_filename }}</span>
                                                {{ \Illuminate\Support\Number::fileSize($v->size_bytes, 1) }} · <span class="font-mono" title="SHA-256 {{ $v->checksum_sha256 }}">{{ substr($v->checksum_sha256, 0, 10) }}…</span>
                                                @if ($v->change_note)<p class="mt-1 text-zinc-500">{{ $v->change_note }}</p>@endif
                                            </td>
                                            <td class="whitespace-nowrap text-xs text-zinc-500">{{ $v->created_at->format('d M Y H:i') }}<br>{{ $v->uploader?->name() }}</td>
                                            <td class="text-right">
                                                @if ($v->status === \App\Enums\VersionStatus::Ready && $v->is_viewable && $v->id !== $asset->current_version_id && $can(Capability::Publish) && $asset->isPublished())
                                                    <flux:button size="xs" wire:click="activateVersion({{ $v->id }})" wire:confirm="Make version {{ $v->version_number }} the live version?">Make live</flux:button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($canReplace)
                            <div class="mt-6 rounded-2xl border border-dashed border-zinc-300 p-5" x-data="chunkedUploader(@js($uploader))" wire:ignore>
                                <p class="text-sm font-medium text-zinc-900">Upload a replacement version</p>
                                <p class="mt-1 text-xs text-zinc-500">
                                    The viewer URL stays the same. @if ($asset->isPublished()) The current version stays live until the replacement is processed and activated. @endif
                                    Allowed: {{ implode(', ', $uploader['extensions']) }}.
                                </p>
                                <label class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-700">
                                    <flux:icon name="arrow-up-tray" variant="mini" class="size-4" /> Choose file
                                    <input type="file" class="sr-only" @change="pick($event)">
                                </label>
                                <template x-for="item in items" :key="item.id">
                                    <p class="mt-3 text-xs" :class="item.status === 'error' ? 'text-red-600' : 'text-zinc-600'">
                                        <span class="font-medium" x-text="item.name"></span> —
                                        <span x-text="item.status === 'uploading' ? item.progress + '%' : (item.message || item.status)"></span>
                                    </p>
                                </template>
                            </div>
                        @endif
                    @elseif ($tab === 'koha')
                        @if ($asset->isItemLevel())
                            <p class="text-sm text-zinc-600">This resource belongs to Koha item <span class="font-mono">#{{ $asset->item_id }}</span> of record <span class="font-mono">#{{ $asset->biblio_id }}</span>. When published, the MAM adds one link for the item to its URI field, shown as the item's URL in the staff and OPAC holdings tables. All published media of the item share that link. A URL already catalogued for the item is kept; the MAM link is added after it, separated by “ | ”. Every update is read back and verified.</p>
                            <div class="mt-4 overflow-x-auto rounded-xl bg-zinc-950 p-4 font-mono text-sm text-zinc-100">
                                <span class="text-emerald-400">952</span> <span class="text-sky-300">$u</span> {{ $asset->itemUrl() }}
                            </div>
                            <p class="mt-2 text-xs text-zinc-500">items.uri. The link opens the resource directly, or a list when the item has several.</p>
                        @else
                            <p class="text-sm text-zinc-600">When published, the MAM maintains exactly one 856 field for this resource in Koha record <span class="font-mono">#{{ $asset->biblio_id }}</span>. All other fields, including other 856 links, are preserved. Every update is read back and verified.</p>
                            <div class="mt-4 overflow-x-auto rounded-xl bg-zinc-950 p-4 font-mono text-sm text-zinc-100">
                                <span class="text-emerald-400">856</span> <span class="text-amber-300">{{ str_replace(' ', '#', $field856->ind1.$field856->ind2) }}</span>
                                @foreach ($field856->subfields as [$code, $value])
                                    <span class="text-sky-300">${{ $code }}</span> {{ $value }}
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-zinc-500">Indicators: 4 = HTTP; second indicator from the relationship setting. $7 0 = open access, 1 = restricted.</p>
                        @endif

                        @if ($asset->marc_verified_at)
                            <p class="mt-4 flex items-center gap-2 text-sm text-emerald-700"><flux:icon name="check-badge" variant="mini" class="size-4" /> Verified in Koha {{ $asset->marc_verified_at->diffForHumans() }}</p>
                        @endif

                        <h3 class="mb-2 mt-8 text-sm font-semibold text-zinc-900">Publication operations</h3>
                        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200">
                            @forelse ($operations as $op)
                                <li class="flex flex-wrap items-start justify-between gap-3 p-3 text-sm">
                                    <div class="min-w-0">
                                        <p class="font-medium capitalize text-zinc-800">{{ $op->action }} <span class="ml-1 text-xs font-normal text-zinc-500">attempts {{ $op->attempts }}</span></p>
                                        @if ($op->last_error)<p class="mt-0.5 text-xs text-red-600">{{ $op->last_error }}</p>@endif
                                        @if ($op->available_at && $op->status === \App\Enums\PublicationStatus::Pending)<p class="text-xs text-zinc-500">Next attempt {{ $op->available_at->diffForHumans() }}</p>@endif
                                    </div>
                                    <div class="text-right text-xs text-zinc-500">
                                        <span @class(['rounded-full px-2 py-0.5 font-medium', 'bg-emerald-50 text-emerald-700' => $op->status->value === 'succeeded', 'bg-red-50 text-red-700' => in_array($op->status->value, ['failed', 'needs_review']), 'bg-zinc-100 text-zinc-600' => ! in_array($op->status->value, ['succeeded', 'failed', 'needs_review'])])>{{ str_replace('_', ' ', $op->status->value) }}</span>
                                        <p class="mt-1">{{ $op->created_at->format('d M Y H:i') }}</p>
                                    </div>
                                </li>
                            @empty
                                <li class="p-4 text-center text-sm text-zinc-500">Not published yet.</li>
                            @endforelse
                        </ul>
                    @else
                        <ol class="space-y-0">
                            @forelse ($history as $entry)
                                <li class="relative border-l border-zinc-200 py-3 pl-5">
                                    <span class="absolute -left-[5px] top-[18px] size-2.5 rounded-full border-2 border-white bg-brand/60"></span>
                                    <p class="text-sm text-zinc-800">{{ $entry->description }}</p>
                                    @php $props = collect($entry->properties ?? [])->except(['attributes', 'old'])->filter(fn ($v) => is_scalar($v)); @endphp
                                    @if ($props->isNotEmpty())
                                        <p class="mt-0.5 text-xs text-zinc-500">{{ $props->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.$v)->implode(' · ') }}</p>
                                    @endif
                                    <p class="text-xs text-zinc-400">{{ $entry->causer?->name() ?? 'System' }} · {{ $entry->created_at->format('d M Y H:i') }}</p>
                                </li>
                            @empty
                                <li class="py-6 text-center text-sm text-zinc-500">No history yet.</li>
                            @endforelse
                        </ol>
                    @endif
                </div>
            </div>
        </div>

        {{-- Side panel --}}
        <aside class="space-y-6">
            <section class="card p-5">
                <h2 class="eyebrow mb-3">Publishing</h2>
                <p class="text-sm text-zinc-700">{{ $policy['requires_approval'] ? 'Requires approval' : 'May be published directly by you' }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ $policy['reason'] }}</p>
                <dl class="mt-4 space-y-2 text-sm">
                    @if ($asset->submitter)<div class="flex justify-between gap-2"><dt class="text-zinc-500">Submitted</dt><dd class="text-right">{{ $asset->submitter->name() }}<br><span class="text-xs text-zinc-400">{{ $asset->submitted_at?->diffForHumans() }}</span></dd></div>@endif
                    @if ($asset->approver)<div class="flex justify-between gap-2"><dt class="text-zinc-500">Approved</dt><dd class="text-right">{{ $asset->approver->name() }}<br><span class="text-xs text-zinc-400">{{ $asset->approved_at?->diffForHumans() }}</span></dd></div>@endif
                    @if ($asset->published_at)<div class="flex justify-between gap-2"><dt class="text-zinc-500">First published</dt><dd>{{ $asset->published_at->format('d M Y') }}</dd></div>@endif
                </dl>
            </section>

            <section class="card p-5" aria-labelledby="views-heading">
                <h2 id="views-heading" class="eyebrow mb-3">Views</h2>
                <p class="font-serif text-3xl font-semibold tabular-nums text-zinc-900">{{ number_format($asset->view_count) }}</p>
                <p class="text-xs text-zinc-500">{{ $asset->last_viewed_at ? 'Last viewed '.$asset->last_viewed_at->diffForHumans() : 'Not viewed yet' }}</p>

                {{-- Counted views per day, last 14 days. --}}
                <div class="mt-4 flex h-16 items-end gap-1" role="img" aria-label="Views per day over the last 14 days">
                    @foreach ($views['daily'] as $day => $count)
                        <div class="flex-1 rounded-t bg-brand-teal/70" style="height: {{ max(4, round($count / max(1, $views['peak']) * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($day)->format('j M') }}: {{ $count }}"></div>
                    @endforeach
                </div>
                <p class="mt-1 flex justify-between text-[11px] text-zinc-400"><span>14 days ago</span><span>Today</span></p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Views, last 30 days</dt><dd class="tabular-nums">{{ number_format($views['views_30d']) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500" title="Visitors are recognised per day, without storing IP addresses">Daily unique viewers, 30 days</dt><dd class="tabular-nums">{{ number_format($views['unique_30d']) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Page opens, 30 days</dt><dd class="tabular-nums">{{ number_format($views['opens_30d']) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Staff views, 30 days</dt><dd class="tabular-nums">{{ number_format($views['staff_30d']) }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-zinc-500">A view counts after {{ $views['qualifying'] }} seconds of watching or reading. Repeat views count again after {{ config('dam.viewer.views.repeat_after_minutes') }} minutes; staff and bots are not counted.</p>
            </section>

            <section class="card p-5">
                <h2 class="eyebrow mb-3">Stable viewer URL</h2>
                <p class="break-all font-mono text-xs text-zinc-700">{{ $asset->viewerUrl() }}</p>
                <p class="mt-2 text-xs text-zinc-500">Never changes, even when the file is replaced or moved to another storage backend.</p>
            </section>

            <section class="card p-5">
                <h2 class="eyebrow mb-3">Record</h2>
                <p class="font-serif text-base font-semibold leading-snug text-zinc-900">{{ $asset->snapshot('title', 'Record #'.$asset->biblio_id) }}</p>
                @if ($asset->snapshot('author'))<p class="mt-1 text-sm text-zinc-600">{{ $asset->snapshot('author') }}</p>@endif
                @if ($asset->isItemLevel())
                    <p class="mt-3 text-sm text-zinc-700"><span class="text-xs text-zinc-500">Koha item</span><br>{{ $asset->itemLabel() }}@if ($asset->itemDetails())<br><span class="text-xs text-zinc-500">{{ $asset->itemDetails() }}</span>@endif</p>
                    <a href="{{ route('biblios.show', ['biblio' => $asset->biblio_id, 'item' => $asset->item_id]) }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline">Open item workspace <flux:icon name="arrow-right" variant="micro" class="size-3.5" /></a><br>
                @endif
                <a href="{{ route('biblios.show', $asset->biblio_id) }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline">Open record workspace <flux:icon name="arrow-right" variant="micro" class="size-3.5" /></a>
            </section>

            <section class="card p-5 text-sm">
                <h2 class="eyebrow mb-3">Ownership</h2>
                <dl class="space-y-2">
                    <div class="flex justify-between"><dt class="text-zinc-500">Created by</dt><dd>{{ $asset->creator?->name() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500">Created</dt><dd>{{ $asset->created_at->format('d M Y H:i') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500">Asset ID</dt><dd class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($asset->uuid, 13) }}</dd></div>
                </dl>
            </section>
        </aside>
    </div>

    <flux:modal name="reject" class="md:w-[28rem]">
        <form wire:submit="reject" class="space-y-5">
            <div>
                <flux:heading size="lg">Return for changes</flux:heading>
                <flux:text class="mt-1">The uploader will see this reason on the asset.</flux:text>
            </div>
            <flux:textarea wire:model="rejectReason" label="Reason" rows="4" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Return</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="unpublish" class="md:w-[28rem]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Unpublish this resource?</flux:heading>
                <flux:text class="mt-1">@if ($asset->isItemLevel())Public access ends immediately. The MAM link is then removed from the Koha item's URL (952$u) once no other published media of the item needs it; other catalogue data is not touched.@else Public access ends immediately. The MAM-managed 856 link is then removed from the Koha record; other catalogue data is not touched.@endif</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="unpublish">Unpublish</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
