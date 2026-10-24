<div class="fade-in">
    <x-page-header eyebrow="Administration" title="Media processing" description="Conversion tools, supported formats and recent queue failures. Live queue metrics are in the queue monitor (Horizon).">
        @can('viewHorizon')
            <x-slot:actions><flux:button icon="queue-list" href="{{ url(config('horizon.path')) }}">Open queue monitor</flux:button></x-slot:actions>
        @endcan
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">Tools</h2></div>
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($tools as $t)
                    <li class="flex items-center justify-between px-6 py-3">
                        <span><span class="font-medium text-zinc-800">{{ $t['tool'] }}</span> <span class="ml-2 font-mono text-xs text-zinc-500">{{ $t['binary'] ?? 'not configured' }}</span></span>
                        <span @class(['inline-flex items-center gap-1 text-xs font-medium', 'text-emerald-700' => $t['available'], 'text-zinc-400' => ! $t['available']])>
                            <flux:icon :name="$t['available'] ? 'check-circle' : 'minus-circle'" variant="mini" class="size-4" /> {{ $t['available'] ? 'Available' : 'Not found' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">Configuration</h2></div>
            <dl class="divide-y divide-zinc-100 text-sm">
                @foreach ($config as $label => $value)
                    <div class="flex justify-between gap-4 px-6 py-3"><dt class="text-zinc-500">{{ $label }}</dt><dd class="text-right font-medium text-zinc-800">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">Supported formats</h2></div>
            <dl class="divide-y divide-zinc-100 text-sm">
                @foreach ($formats as $type => $exts)
                    <div class="flex gap-4 px-6 py-3"><dt class="w-24 shrink-0 font-medium capitalize text-zinc-800">{{ $type }}</dt><dd class="text-zinc-600">{{ implode(', ', $exts) }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="card">
            <div class="border-b border-zinc-100 px-6 py-4"><h2 class="font-semibold text-zinc-900">Recent failed jobs</h2></div>
            <ul class="divide-y divide-zinc-100 text-sm">
                @forelse ($failedJobs as $job)
                    <li class="px-6 py-3"><p class="text-xs text-zinc-500">{{ $job->queue }} · {{ $job->failed_at }}</p><p class="mt-0.5 text-xs text-red-600">{{ $job->error }}</p></li>
                @empty
                    <li><x-empty-state icon="check-circle" title="No failed jobs" /></li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
