<?php

namespace App\Jobs;

use App\Models\PublicationOperation;
use App\Publishing\PublicationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Relays one outbox entry to Koha. Retries are owned by the outbox
 * (available_at + attempts), not by the queue, so the operation survives
 * worker restarts and Redis loss; `dam:publications:relay` re-dispatches
 * anything due.
 */
class ProcessPublicationOperation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $operationId)
    {
        $this->onQueue('publication');
    }

    public function uniqueId(): string
    {
        return (string) $this->operationId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(PublicationService $service): void
    {
        if ($operation = PublicationOperation::find($this->operationId)) {
            $service->process($operation);
        }
    }
}
