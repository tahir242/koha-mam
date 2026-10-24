<?php

namespace App\Health;

use App\Models\StorageBackend;
use App\Storage\StorageManager;
use Illuminate\Support\Number;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class StorageBackendsCheck extends Check
{
    /** Warn when a filesystem backend has less than this fraction free. */
    private float $warnFreeRatio = 0.10;

    public function run(): Result
    {
        $manager = app(StorageManager::class);
        $failing = [];
        $low = [];

        foreach (StorageBackend::query()->active()->get() as $backend) {
            $probe = $manager->probe($backend);

            if (! $probe['ok']) {
                $failing[] = "{$backend->name}: {$probe['message']}";
            } elseif ($probe['total_bytes'] && $this->warnFreeRatio > $probe['free_bytes'] / $probe['total_bytes']) {
                $low[] = "{$backend->name} (".Number::fileSize($probe['free_bytes']).' free)';
            }
        }

        $result = Result::make();

        if ($failing) {
            return $result->failed(implode('; ', $failing))->shortSummary(count($failing).' failing');
        }

        if ($low) {
            return $result->warning('Low free space: '.implode(', ', $low))->shortSummary('Low space');
        }

        return $result->ok()->shortSummary('Healthy');
    }
}
