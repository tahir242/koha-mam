<?php

namespace App\Publishing;

use App\Enums\AccessLevel;
use App\Enums\Capability;
use App\Enums\MarcRelationship;
use App\Enums\PublicationStatus;
use App\Enums\VersionStatus;
use App\Jobs\IngestAssetVersion;
use App\Jobs\ProcessAssetVersion;
use App\Jobs\ProcessPublicationOperation;
use App\Media\MediaTypeRegistry;
use App\Models\AssetVersion;
use App\Models\DigitalAsset;
use App\Models\PublicationOperation;
use App\Models\User;
use App\States\Asset\Approved;
use App\States\Asset\Archived;
use App\States\Asset\PendingApproval;
use App\States\Asset\ProcessingFailed;
use App\States\Asset\PublicationFailed;
use App\States\Asset\PublicationPending;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use App\States\Asset\Unpublished;
use App\States\Asset\Validating;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Server-side implementation of every lifecycle action. Each method checks
 * the actor's capability and the asset's current state; the state machine
 * itself rejects any transition not declared in AssetState::config().
 */
class AssetWorkflow
{
    public function __construct(private readonly PublishingPolicyResolver $policies) {}

    /** Submit for publication; publishes directly when policy allows. */
    public function submit(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Submit);
        $this->assertState($asset, [ReadyForReview::class, Unpublished::class], 'submitted');
        $this->assertPublishable($asset);

        if (! $this->policies->requiresApproval($asset, $user)) {
            $this->publish($asset, $user);

            return;
        }

        DB::transaction(function () use ($asset, $user) {
            $asset->forceFill(['submitted_by' => $user->id, 'submitted_at' => now(), 'rejection_reason' => null, 'approved_by' => null, 'approved_at' => null])->save();
            $asset->state->transitionTo(PendingApproval::class);
        });

        $this->log($asset, $user, 'submitted', 'Submitted for approval');
    }

    public function approve(DigitalAsset $asset, User $user, ?string $note = null): void
    {
        $this->authorize($user, Capability::Approve);
        $this->assertState($asset, [PendingApproval::class], 'approved');

        if (config('dam.authorization.enforce_four_eyes') && $asset->submitted_by === $user->id && $user->role !== 'administrator') {
            throw new WorkflowException('You cannot approve a resource you submitted yourself.');
        }

        $this->assertPublishable($asset);

        DB::transaction(function () use ($asset, $user) {
            $asset->forceFill(['approved_by' => $user->id, 'approved_at' => now(), 'rejection_reason' => null])->save();
            $asset->state->transitionTo(Approved::class);
        });

        $this->log($asset, $user, 'approved', 'Approved for publication', ['note' => $note]);
        $this->requestPublication($asset, $user);
    }

    public function reject(DigitalAsset $asset, User $user, string $reason): void
    {
        $this->authorize($user, Capability::Review);
        $this->assertState($asset, [PendingApproval::class, Approved::class], 'rejected');

        $reason = trim($reason);

        if ($reason === '') {
            throw new WorkflowException('A reason is required when rejecting a resource.');
        }

        DB::transaction(function () use ($asset, $reason) {
            $asset->forceFill(['rejection_reason' => mb_substr($reason, 0, 2000), 'approved_by' => null, 'approved_at' => null])->save();
            $asset->state->transitionTo(ReadyForReview::class);
        });

        $this->log($asset, $user, 'rejected', 'Rejected', ['reason' => $reason]);
    }

    /** Direct publication by a user whom policy allows to skip approval. */
    public function publish(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Publish);

        if ($asset->state instanceof Approved) {
            $this->requestPublication($asset, $user);

            return;
        }

        $this->assertState($asset, [ReadyForReview::class, Unpublished::class], 'published');

        if ($this->policies->requiresApproval($asset, $user)) {
            throw new WorkflowException('This resource requires approval before it can be published.');
        }

        $this->assertPublishable($asset);
        $asset->forceFill(['approved_by' => $user->id, 'approved_at' => now()])->save();
        $this->requestPublication($asset, $user);
    }

    public function retryPublication(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Publish);
        $this->assertState($asset, [PublicationFailed::class], 'retried');
        $this->requestPublication($asset, $user);
    }

    public function unpublish(DigitalAsset $asset, User $user, ?string $reason = null): void
    {
        $this->authorize($user, Capability::Unpublish);
        $this->assertState($asset, [Published::class, PublicationPending::class, PublicationFailed::class], 'unpublished');

        DB::transaction(function () use ($asset, $user) {
            // Public access ends immediately; the Koha link is removed asynchronously.
            $asset->forceFill(['unpublished_at' => now()])->save();
            $asset->state->transitionTo(Unpublished::class);
            $this->enqueue($asset, PublicationOperation::ACTION_UNLINK, $user);
        });

        $this->log($asset, $user, 'unpublished', 'Unpublished', ['reason' => $reason]);
    }

    public function archive(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Delete);
        $this->assertState($asset, [ReadyForReview::class, ProcessingFailed::class, Unpublished::class, PublicationFailed::class], 'archived');
        $asset->state->transitionTo(Archived::class);
        $this->log($asset, $user, 'archived', 'Archived');
    }

    public function restore(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Delete);
        $this->assertState($asset, [Archived::class], 'restored');
        $asset->state->transitionTo(ReadyForReview::class);
        $this->log($asset, $user, 'restored', 'Restored from archive');
    }

    /** Re-run processing for the latest failed version. */
    public function retryProcessing(DigitalAsset $asset, User $user): void
    {
        $this->authorize($user, Capability::Edit);
        $version = $asset->versions()->where('status', VersionStatus::Failed->value)->first()
            ?? throw new WorkflowException('There is no failed version to retry.');

        $hasOriginal = $version->files()->where('role', 'original')->exists();

        if (! $hasOriginal && ! $version->quarantine_path) {
            throw new WorkflowException('The uploaded file was destroyed (for example after a failed malware scan). Please upload it again.');
        }

        if ($asset->state instanceof ProcessingFailed) {
            $asset->state->transitionTo(Validating::class);
        }

        $version->forceFill(['status' => $hasOriginal ? VersionStatus::Processing : VersionStatus::Received, 'processing_error' => null, 'processing_progress' => 0])->save();

        // A crashed worker can leave the unique-job lock behind; an explicit
        // retry must never be swallowed by it.
        foreach ([ProcessAssetVersion::class, IngestAssetVersion::class] as $job) {
            Cache::lock("laravel_unique_job:{$job}:{$version->id}")->forceRelease();
        }

        $hasOriginal
            ? ProcessAssetVersion::dispatch($version->id)->onQueue(app(MediaTypeRegistry::class)->queueFor($asset->media_type))
            : IngestAssetVersion::dispatch($version->id);

        $this->log($asset, $user, 'processing_retried', 'Processing retried', ['version' => $version->version_number]);
    }

    /** Switch a published asset to a processed replacement version (URL unchanged). */
    public function activateVersion(DigitalAsset $asset, AssetVersion $version, User $user): void
    {
        $this->authorize($user, Capability::Publish);

        if ($version->digital_asset_id !== $asset->id || $version->status !== VersionStatus::Ready || ! $version->is_viewable) {
            throw new WorkflowException('Only a successfully processed version of this resource can be activated.');
        }

        if ($asset->is_sensitive && ! $user->hasCapability(Capability::Approve)) {
            throw new WorkflowException('Replacing a sensitive resource requires a reviewer.');
        }

        DB::transaction(function () use ($asset, $version) {
            $previous = $asset->current_version_id;
            $asset->forceFill(['current_version_id' => $version->id])->save();
            $version->forceFill(['activated_at' => now()])->save();

            if ($previous && $previous !== $version->id) {
                AssetVersion::whereKey($previous)->update(['status' => VersionStatus::Superseded->value]);
            }
        });

        $this->log($asset, $user, 'version_activated', "Activated version {$version->version_number}");
    }

    /**
     * Update staff-editable details. Changes that affect the managed 856
     * of a published asset enqueue a link refresh.
     */
    public function updateDetails(DigitalAsset $asset, User $user, array $data): void
    {
        $this->authorize($user, Capability::Edit);

        if (array_key_exists('requires_approval_override', $data)
            && $data['requires_approval_override'] !== $asset->requires_approval_override
            && ! $user->hasCapability(Capability::ManageSettings)
            && ! ($data['requires_approval_override'] === true && $user->hasCapability(Capability::Approve))) {
            throw new AuthorizationException('You are not allowed to change the approval rule for this resource.');
        }

        if (array_key_exists('is_sensitive', $data) && $asset->is_sensitive && ! $data['is_sensitive'] && ! $user->hasCapability(Capability::Approve)) {
            throw new AuthorizationException('Only a reviewer can remove the sensitive flag.');
        }

        $asset->fill([
            'title' => $data['title'] ?? $asset->title,
            'description' => $data['description'] ?? $asset->description,
            'access_level' => isset($data['access_level']) ? AccessLevel::from($data['access_level']) : $asset->access_level,
            'is_sensitive' => $data['is_sensitive'] ?? $asset->is_sensitive,
            'requires_approval_override' => array_key_exists('requires_approval_override', $data) ? $data['requires_approval_override'] : $asset->requires_approval_override,
            'marc_relationship' => isset($data['marc_relationship']) ? MarcRelationship::from($data['marc_relationship']) : $asset->marc_relationship,
            'link_text' => array_key_exists('link_text', $data) ? ($data['link_text'] ?: null) : $asset->link_text,
            'public_note' => array_key_exists('public_note', $data) ? ($data['public_note'] ?: null) : $asset->public_note,
            'updated_by' => $user->id,
        ]);

        $marcChanged = $asset->isDirty(['access_level', 'marc_relationship', 'link_text', 'public_note']);
        $asset->save();

        // A published asset newly marked sensitive leaves public view until re-approved.
        if ($asset->wasChanged('is_sensitive') && $asset->is_sensitive && $asset->isPublished()) {
            $this->unpublish($asset, $user, 'Marked as sensitive');

            return;
        }

        if ($marcChanged && $asset->isPublished()) {
            $this->enqueue($asset, PublicationOperation::ACTION_LINK, $user);
        }
    }

    /** Create the outbox entry for linking the asset in Koha. */
    public function requestPublication(DigitalAsset $asset, User $user): void
    {
        DB::transaction(function () use ($asset, $user) {
            $asset->state->transitionTo(PublicationPending::class);
            $this->enqueue($asset, PublicationOperation::ACTION_LINK, $user);
        });

        $this->log($asset, $user, 'publication_requested', 'Publication to Koha requested');
    }

    private function enqueue(DigitalAsset $asset, string $action, User $user): PublicationOperation
    {
        // Older open operations for this asset are superseded by the newest intent.
        PublicationOperation::where('digital_asset_id', $asset->id)
            ->where('status', PublicationStatus::Pending->value)
            ->update(['status' => PublicationStatus::Superseded->value, 'completed_at' => now()]);

        $operation = PublicationOperation::create([
            'digital_asset_id' => $asset->id,
            'koha_instance_id' => $asset->koha_instance_id,
            'biblio_id' => $asset->biblio_id,
            'item_id' => $asset->item_id,
            'action' => $action,
            'status' => PublicationStatus::Pending,
            'available_at' => now(),
            'requested_by' => $user->id,
        ]);

        ProcessPublicationOperation::dispatch($operation->id)->afterCommit();

        return $operation;
    }

    private function assertPublishable(DigitalAsset $asset): void
    {
        $version = $asset->currentVersion;

        if (! $version || $version->status !== VersionStatus::Ready || ! $version->is_viewable) {
            throw new WorkflowException('Only successfully processed, viewable resources can be published.');
        }
    }

    /**
     * @param  list<class-string>  $states
     */
    private function assertState(DigitalAsset $asset, array $states, string $verb): void
    {
        if (! $asset->state->equals(...$states)) {
            throw new WorkflowException("A resource that is “{$asset->state->label()}” cannot be {$verb}.");
        }
    }

    private function authorize(User $user, Capability $capability): void
    {
        if (! $user->hasCapability($capability)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }

    private function log(DigitalAsset $asset, User $user, string $event, string $description, array $properties = []): void
    {
        activity('workflow')->causedBy($user)->performedOn($asset)->event($event)
            ->withProperties(array_filter($properties, fn ($v) => $v !== null) + ['state' => $asset->state->getValue()])
            ->log($description);
    }
}
