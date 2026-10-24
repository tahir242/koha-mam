<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\PublishingPolicy;
use App\Models\User;
use App\Publishing\AssetWorkflow;
use App\Publishing\PublishingPolicyResolver;
use App\Publishing\WorkflowException;
use App\States\Asset\Approved;
use App\States\Asset\PendingApproval;
use App\States\Asset\Processing;
use App\States\Asset\PublicationPending;
use App\States\Asset\Published;
use App\States\Asset\ReadyForReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function readyAsset(array $attributes = []): DigitalAsset
    {
        return DigitalAsset::factory()->processed($this->localBackend())->inState(ReadyForReview::class)->create($attributes)->fresh();
    }

    public function test_regular_staff_must_submit_for_approval(): void
    {
        $asset = $this->readyAsset();
        $staff = User::factory()->create();

        app(AssetWorkflow::class)->submit($asset, $staff);

        $this->assertTrue($asset->fresh()->state->equals(PendingApproval::class));
        $this->assertSame($staff->id, $asset->fresh()->submitted_by);
    }

    public function test_regular_staff_cannot_publish_directly(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AssetWorkflow::class)->publish($this->readyAsset(), User::factory()->create());
    }

    public function test_trusted_cataloguer_publishes_directly_when_policy_allows(): void
    {
        PublishingPolicy::create(['name' => 'Trusted', 'role' => 'trusted_cataloguer', 'requires_approval' => false, 'priority' => 100]);
        $asset = $this->readyAsset();

        app(AssetWorkflow::class)->submit($asset, User::factory()->role('trusted_cataloguer')->create());

        $this->assertTrue($asset->fresh()->state->equals(PublicationPending::class));
    }

    public function test_sensitive_resources_always_require_review(): void
    {
        PublishingPolicy::create(['name' => 'Trusted', 'role' => 'trusted_cataloguer', 'requires_approval' => false]);
        $asset = $this->readyAsset(['is_sensitive' => true]);
        $trusted = User::factory()->role('trusted_cataloguer')->create();

        $this->assertTrue(app(PublishingPolicyResolver::class)->requiresApproval($asset, $trusted));

        $this->expectException(WorkflowException::class);
        app(AssetWorkflow::class)->publish($asset, $trusted);
    }

    public function test_media_type_policy_with_higher_priority_wins(): void
    {
        PublishingPolicy::create(['name' => 'Trusted', 'role' => 'trusted_cataloguer', 'requires_approval' => false, 'priority' => 100]);
        PublishingPolicy::create(['name' => 'Video reviewed', 'media_type' => 'video', 'requires_approval' => true, 'priority' => 200]);
        $trusted = User::factory()->role('trusted_cataloguer')->create();
        $resolver = app(PublishingPolicyResolver::class);

        $this->assertTrue($resolver->requiresApproval($this->readyAsset(['media_type' => 'video']), $trusted));
        $this->assertFalse($resolver->requiresApproval($this->readyAsset(['media_type' => 'document']), $trusted));
    }

    public function test_per_asset_override_requires_authorisation(): void
    {
        $asset = $this->readyAsset();

        $this->expectException(AuthorizationException::class);
        app(AssetWorkflow::class)->updateDetails($asset, User::factory()->create(), ['requires_approval_override' => false]);
    }

    public function test_per_asset_override_applies(): void
    {
        $asset = $this->readyAsset();
        $admin = User::factory()->role('administrator')->create();

        app(AssetWorkflow::class)->updateDetails($asset, $admin, ['requires_approval_override' => false]);

        $this->assertFalse(app(PublishingPolicyResolver::class)->requiresApproval($asset->fresh(), $admin));
    }

    public function test_reviewer_approval_triggers_publication(): void
    {
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);
        $workflow->submit($asset, User::factory()->create());

        $workflow->approve($asset->fresh(), User::factory()->role('reviewer')->create());

        $this->assertTrue($asset->fresh()->state->equals(PublicationPending::class));
        $this->assertNotNull($asset->fresh()->approved_by);
    }

    public function test_four_eyes_prevents_self_approval(): void
    {
        $reviewer = User::factory()->role('reviewer')->create();
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);
        $workflow->submit($asset, $reviewer);

        $this->expectException(WorkflowException::class);
        $workflow->approve($asset->fresh(), $reviewer);
    }

    public function test_cataloguer_cannot_approve(): void
    {
        $asset = $this->readyAsset();
        app(AssetWorkflow::class)->submit($asset, User::factory()->create());

        $this->expectException(AuthorizationException::class);
        app(AssetWorkflow::class)->approve($asset->fresh(), User::factory()->create());
    }

    public function test_rejection_requires_reason_and_allows_resubmission(): void
    {
        $asset = $this->readyAsset();
        $workflow = app(AssetWorkflow::class);
        $staff = User::factory()->create();
        $reviewer = User::factory()->role('reviewer')->create();
        $workflow->submit($asset, $staff);

        try {
            $workflow->reject($asset->fresh(), $reviewer, '  ');
            $this->fail('Empty reason must be rejected');
        } catch (WorkflowException) {
        }

        $workflow->reject($asset->fresh(), $reviewer, 'Wrong edition attached');
        $this->assertTrue($asset->fresh()->state->equals(ReadyForReview::class));
        $this->assertSame('Wrong edition attached', $asset->fresh()->rejection_reason);

        $workflow->submit($asset->fresh(), $staff);
        $this->assertTrue($asset->fresh()->state->equals(PendingApproval::class));
    }

    public function test_unprocessed_assets_cannot_be_published(): void
    {
        $asset = DigitalAsset::factory()->inState(ReadyForReview::class)->create()->fresh();

        $this->expectException(WorkflowException::class);
        app(AssetWorkflow::class)->submit($asset, User::factory()->create());
    }

    public function test_invalid_state_transitions_are_impossible(): void
    {
        $asset = DigitalAsset::factory()->inState(Processing::class)->create()->fresh();

        $this->assertFalse($asset->state->canTransitionTo(Published::class));
        $this->expectException(TransitionNotFound::class);
        $asset->state->transitionTo(Published::class);
    }

    public function test_approved_cannot_jump_to_published_without_koha(): void
    {
        $asset = DigitalAsset::factory()->inState(Approved::class)->create()->fresh();

        $this->assertFalse($asset->state->canTransitionTo(Published::class));
    }

    public function test_marking_published_asset_sensitive_unpublishes_it(): void
    {
        $asset = $this->readyAsset();
        $asset->forceFill(['state' => Published::getMorphClass()])->saveQuietly();
        $reviewer = User::factory()->role('reviewer')->create();

        app(AssetWorkflow::class)->updateDetails($asset->fresh(), $reviewer, ['is_sensitive' => true]);

        $this->assertFalse($asset->fresh()->isPublished());
    }
}
