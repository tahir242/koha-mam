<?php

namespace App\Health;

use App\Enums\PublicationStatus;
use App\Models\PublicationOperation;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class PublicationBacklogCheck extends Check
{
    public function run(): Result
    {
        $failed = PublicationOperation::whereIn('status', [PublicationStatus::Failed->value, PublicationStatus::NeedsReview->value])
            ->where('completed_at', '>=', now()->subDays(7))->count();
        $overdue = PublicationOperation::where('status', PublicationStatus::Pending->value)
            ->where('created_at', '<', now()->subHour())->count();

        $result = Result::make()->meta(compact('failed', 'overdue'))->shortSummary("{$failed} failed, {$overdue} overdue");

        if ($overdue > 0) {
            return $result->warning("{$overdue} Koha publication(s) pending for more than an hour.");
        }

        return $failed > 0 ? $result->warning("{$failed} Koha publication(s) need attention.") : $result->ok();
    }
}
