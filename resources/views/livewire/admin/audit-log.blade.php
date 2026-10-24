<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Audit log" description="Authentication, uploads, workflow decisions, Koha publication, processing and configuration changes. Retained for {{ config('dam.audit.retention_days') }} days." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-zinc-100 p-4 sm:flex-row">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search events" class="sm:max-w-xs" />
            <flux:select wire:model.live="log" class="sm:max-w-xs" aria-label="Category">
                <flux:select.option value="">All categories</flux:select.option>
                @foreach ($logs as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
            </flux:select>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>When</th><th>Category</th><th>Event</th><th>Who</th><th>Subject</th><th>Details</th></tr></thead>
                <tbody>
                    @foreach ($entries as $e)
                        <tr wire:key="e-{{ $e->id }}">
                            <td class="whitespace-nowrap text-xs text-zinc-500">{{ $e->created_at->format('d M Y H:i:s') }}</td>
                            <td><span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600">{{ $logs[$e->log_name] ?? $e->log_name }}</span></td>
                            <td class="text-sm text-zinc-800">{{ $e->description }}</td>
                            <td class="whitespace-nowrap text-xs">{{ $e->causer?->name() ?? 'System' }}</td>
                            <td class="text-xs">
                                @if ($e->subject instanceof \App\Models\DigitalAsset)
                                    <a href="{{ route('assets.show', $e->subject) }}" wire:navigate class="text-brand hover:underline">{{ \Illuminate\Support\Str::limit($e->subject->title, 30) }}</a>
                                @elseif ($e->subject)
                                    {{ class_basename($e->subject) }} #{{ $e->subject_id }}
                                @endif
                            </td>
                            <td class="max-w-md text-xs text-zinc-500">
                                @php $props = collect($e->properties ?? [])->except(['attributes', 'old'])->filter(fn ($v) => is_scalar($v)); @endphp
                                {{ $props->map(fn ($v, $k) => $k.': '.\Illuminate\Support\Str::limit((string) $v, 80))->implode(' · ') }}
                                @if ($e->attribute_changes)
                                    <span class="block">changed: {{ implode(', ', array_keys($e->attribute_changes['attributes'] ?? [])) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-6">{{ $entries->links() }}</div>
</div>
