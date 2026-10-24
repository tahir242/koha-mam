<?php

namespace App\Health;

use App\Koha\KohaClient;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class KohaApiCheck extends Check
{
    public function run(): Result
    {
        $ping = app(KohaClient::class)->ping();
        $result = Result::make()->meta(['latency_ms' => $ping['latency_ms']])->shortSummary($ping['ok'] ? ($ping['latency_ms'].' ms') : 'Unreachable');

        return $ping['ok'] ? $result->ok() : $result->failed($ping['message']);
    }
}
