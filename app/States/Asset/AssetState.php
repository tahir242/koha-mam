<?php

namespace App\States\Asset;

use App\Models\DigitalAsset;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Lifecycle of a media asset. Only the transitions declared here are
 * possible; client requests never set the state directly.
 *
 * @extends State<DigitalAsset>
 */
abstract class AssetState extends State
{
    abstract public function label(): string;

    /** Tailwind-friendly tone used by status badges. */
    abstract public function tone(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Uploaded::class)
            ->allowTransition(Uploaded::class, Validating::class)
            ->allowTransition([Validating::class, ProcessingFailed::class], Processing::class)
            ->allowTransition([Uploaded::class, Validating::class, Processing::class], ProcessingFailed::class)
            ->allowTransition(ProcessingFailed::class, Validating::class)
            ->allowTransition(Processing::class, ReadyForReview::class)
            // A replacement version restarts processing for unpublished assets.
            ->allowTransition([ReadyForReview::class, PendingApproval::class, Approved::class, Unpublished::class, PublicationFailed::class], Validating::class)
            ->allowTransition(ReadyForReview::class, PendingApproval::class)
            ->allowTransition(PendingApproval::class, Approved::class)
            ->allowTransition([PendingApproval::class, Approved::class], ReadyForReview::class)
            ->allowTransition([ReadyForReview::class, Approved::class, PublicationFailed::class, Unpublished::class], PublicationPending::class)
            ->allowTransition(Unpublished::class, PendingApproval::class)
            ->allowTransition(PublicationPending::class, Published::class)
            ->allowTransition(PublicationPending::class, PublicationFailed::class)
            ->allowTransition(PublicationFailed::class, ReadyForReview::class)
            ->allowTransition([Published::class, PublicationPending::class, PublicationFailed::class], Unpublished::class)
            ->allowTransition([Draft::class, ProcessingFailed::class, ReadyForReview::class, Unpublished::class, PublicationFailed::class], Archived::class)
            ->allowTransition(Archived::class, ReadyForReview::class);
    }

    public function isPubliclyVisible(): bool
    {
        return false;
    }

    public function isProcessing(): bool
    {
        return false;
    }
}
