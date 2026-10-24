<?php

use App\Auth\Sso\KohaSsoAdapter;
use App\Auth\Sso\SsoException;
use App\Enums\PublicationStatus;
use App\Enums\VersionStatus;
use App\Jobs\ProcessPublicationOperation;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\MediaAccessEvent;
use App\Models\PublicationOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

/*
| SSO diagnostics: effective configuration (secret masked), the exact
| configuration check that fails, and a live probe of the Koha exchange URL.
*/
Artisan::command('dam:sso:doctor', function () {
    $sso = config('dam.sso');
    $secret = (string) ($sso['client_secret'] ?? '');

    $this->info('Effective configuration (from '.(app()->configurationIsCached() ? 'CACHED config — run php artisan config:cache after editing .env' : '.env').')');
    $this->table(['Setting', 'Value'], [
        ['APP_URL', config('app.url')],
        ['KOHA_INSTANCE_ID', config('dam.koha.instance_id')],
        ['KOHA_STAFF_URL', config('dam.koha.staff_url') ?: '(empty)'],
        ['KOHA_SSO_CLIENT_ID', $sso['client_id'] ?: '(empty)'],
        ['KOHA_SSO_CLIENT_SECRET', $secret === '' ? '(empty)' : strlen($secret).' chars, '.(preg_match('/^[0-9a-f]{64}$/', $secret) ? 'valid hex' : 'INVALID (needs 64 lowercase hex)').', ends …'.substr($secret, -4)],
        ['return URL', $sso['return_url']],
        ['exchange URL', $sso['exchange_url']],
        ['DAM_ALLOW_INSECURE_HTTP', $sso['allow_insecure_http'] ? 'true' : 'false'],
        ['SESSION_SECURE_COOKIE', var_export(config('session.secure'), true)],
        ['session cookie', config('session.cookie')],
    ]);

    // Run the adapter's own checks with a syntactically valid initiation.
    $request = Request::create('/sso/koha/start', 'GET', ['request' => str_repeat('a', 64)], [], [], ['HTTPS' => str_starts_with((string) config('app.url'), 'https') ? 'on' : null]);
    $request->setLaravelSession(app('session')->driver());

    try {
        app(KohaSsoAdapter::class)->start($request);
        $this->info('✔ SSO configuration checks pass.');
    } catch (SsoException $e) {
        $this->error('✘ '.$e->getMessage());

        return 1;
    }

    // The exchange must answer 401 invalid_client for wrong credentials (proves route + reachability).
    try {
        $response = Http::timeout(10)->acceptJson()->asJson()
            ->withHeaders(['X-Media-Client' => 'dam-sso-doctor', 'X-Media-Secret' => str_repeat('0', 64)])
            ->post($sso['exchange_url'], ['code' => str_repeat('0', 64)]);
        $line = "Exchange probe: HTTP {$response->status()} ".mb_substr($response->body(), 0, 120);
        $response->status() === 401 ? $this->info('✔ '.$line.' (expected)') : $this->warn('✘ '.$line.' (expected 401 invalid_client)');
    } catch (Throwable $e) {
        $this->error('✘ Exchange probe failed: '.$e->getMessage());
    }

    return 0;
})->purpose('Diagnose the Koha SSO (code-v2) configuration');

/*
| Publication outbox relay: dispatch due operations and recover operations
| left "running" by a crashed worker. The outbox table, not the queue, is
| the source of truth, so nothing is lost if Redis is flushed.
*/
Artisan::command('dam:publications:relay', function () {
    $recovered = PublicationOperation::where('status', PublicationStatus::Running->value)
        ->where('started_at', '<', now()->subMinutes(15))
        ->update(['status' => PublicationStatus::Pending->value, 'available_at' => now(), 'last_error' => 'Recovered after worker interruption.']);

    $due = PublicationOperation::query()->due()->orderBy('id')->limit(100)->pluck('id');
    $due->each(fn (int $id) => ProcessPublicationOperation::dispatch($id));

    $this->info("Dispatched {$due->count()} operation(s); recovered {$recovered}.");
})->purpose('Relay due Koha publication operations from the outbox');

/*
| One-off after upgrading to item URI publication: queue a link operation for
| every published item-level asset, which moves its link from the record's
| 856 (written by 2.5.0) to the item's URI field (952$u).
*/
Artisan::command('dam:items:republish', function () {
    $assets = DigitalAsset::query()->whereNotNull('item_id')->published()->get();

    foreach ($assets as $asset) {
        PublicationOperation::where('digital_asset_id', $asset->id)->where('status', PublicationStatus::Pending->value)
            ->update(['status' => PublicationStatus::Superseded->value, 'completed_at' => now()]);

        $operation = PublicationOperation::create([
            'digital_asset_id' => $asset->id,
            'koha_instance_id' => $asset->koha_instance_id,
            'biblio_id' => $asset->biblio_id,
            'item_id' => $asset->item_id,
            'action' => PublicationOperation::ACTION_LINK,
            'status' => PublicationStatus::Pending,
            'available_at' => now(),
        ]);

        ProcessPublicationOperation::dispatch($operation->id);
    }

    $this->info("Queued {$assets->count()} item-level asset(s) for publication to the item URI.");
})->purpose('Re-publish item-level media to the Koha item URI field (952$u)');

/*
| Remove orphaned scratch directories and abandoned quarantine files.
*/
Artisan::command('dam:cleanup', function () {
    $work = config('dam.storage.work_path');
    $removed = 0;

    if (is_dir($work)) {
        foreach (File::directories($work) as $dir) {
            if (filemtime($dir) < now()->subHours(6)->getTimestamp()) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }
    }

    $quarantine = Storage::disk(config('dam.storage.quarantine_disk'));
    $referenced = AssetVersion::whereNotNull('quarantine_path')
        ->whereNotIn('status', [VersionStatus::Failed->value])
        ->pluck('quarantine_path')->flip();

    foreach ($quarantine->files('incoming') as $path) {
        if (! $referenced->has($path) && $quarantine->lastModified($path) < now()->subDay()->getTimestamp()) {
            $quarantine->delete($path);
            $removed++;
        }
    }

    // Failed versions keep their quarantined file for 7 days to allow a retry.
    AssetVersion::where('status', VersionStatus::Failed->value)
        ->whereNotNull('quarantine_path')
        ->where('updated_at', '<', now()->subDays(7))
        ->each(function (AssetVersion $version) use ($quarantine, &$removed) {
            $quarantine->delete($version->quarantine_path);
            $version->forceFill(['quarantine_path' => null])->saveQuietly();
            $removed++;
        });

    $this->info("Removed {$removed} stale item(s).");
})->purpose('Clean up stale processing scratch space and quarantine files');

Schedule::command('dam:publications:relay')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('dam:cleanup')->hourly()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command(RunHealthChecksCommand::class)->everyMinute();
Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
Schedule::command('activitylog:clean', ['--days' => config('dam.audit.retention_days')])->daily();
Schedule::command('model:prune', ['--model' => [MediaAccessEvent::class]])->daily();
