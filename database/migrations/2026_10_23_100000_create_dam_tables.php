<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_backends', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            // local | nas | shared | s3
            $table->string('driver', 16);
            // Non-secret settings only (root, or bucket/endpoint and a credentials profile name).
            $table->json('settings');
            $table->json('allowed_media_types')->nullable();
            $table->unsignedBigInteger('capacity_bytes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 16)->default('unknown');
            $table->text('health_message')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('koha_instance_id', 64);
            $table->unsignedBigInteger('biblio_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('media_type', 16)->index();
            $table->string('state', 32)->index();
            // public | restricted
            $table->string('access_level', 16)->default('public');
            $table->boolean('is_sensitive')->default(false);
            // null = follow policy, true/false = per-asset override
            $table->boolean('requires_approval_override')->nullable();
            // resource | version | related | unspecified (MARC 856 second indicator)
            $table->string('marc_relationship', 16)->default('resource');
            $table->string('link_text')->nullable();
            $table->string('public_note')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->json('biblio_snapshot')->nullable();
            $table->timestamp('biblio_snapshot_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('marc_verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['koha_instance_id', 'biblio_id']);
            $table->index(['biblio_id', 'state']);
        });

        Schema::create('asset_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // received | validating | scanning | processing | ready | failed | superseded
            $table->string('status', 16)->index();
            $table->string('processing_stage', 32)->nullable();
            $table->unsignedTinyInteger('processing_progress')->default(0);
            $table->text('processing_error')->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            // Kept for staff only; never exposed publicly.
            $table->string('original_filename');
            $table->string('client_mime')->nullable();
            $table->string('detected_mime')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->index();
            // Relative path on the quarantine disk until the original is stored.
            $table->string('quarantine_path')->nullable();
            $table->foreignId('storage_backend_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('is_viewable')->default(false);
            $table->text('change_note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['digital_asset_id', 'version_number']);
        });

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('asset_versions')->nullOnDelete();
        });

        Schema::create('asset_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_version_id')->constrained()->cascadeOnDelete();
            // original | viewer_pdf | page_image | thumbnail | poster | image_large | image_medium | hls_master | audio
            $table->string('role', 24);
            $table->foreignId('storage_backend_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['asset_version_id', 'role']);
        });

        Schema::create('publishing_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // null = any
            $table->string('role', 32)->nullable();
            $table->string('media_type', 16)->nullable();
            $table->string('access_level', 16)->nullable();
            $table->boolean('applies_to_sensitive')->nullable();
            $table->boolean('requires_approval');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('publication_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('koha_instance_id', 64);
            $table->unsignedBigInteger('biblio_id')->index();
            // link | unlink
            $table->string('action', 16);
            // pending | running | succeeded | failed | needs_review | superseded
            $table->string('status', 16)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->string('last_error_kind', 32)->nullable();
            $table->string('marc_005_before')->nullable();
            $table->string('marc_005_after')->nullable();
            $table->char('marc_hash_before', 64)->nullable();
            $table->char('marc_hash_after', 64)->nullable();
            $table->json('field_856')->nullable();
            $table->json('diagnostics')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('event', 16);
            // HMAC of the client IP (never the raw address).
            $table->char('visitor_hash', 64)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('user_agent_family', 64)->nullable();
            $table->boolean('is_staff')->default(false);
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['digital_asset_id', 'created_at']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('media_access_events');
        Schema::dropIfExists('publication_operations');
        Schema::dropIfExists('publishing_policies');
        Schema::dropIfExists('asset_files');
        Schema::table('digital_assets', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('asset_versions');
        Schema::dropIfExists('digital_assets');
        Schema::dropIfExists('storage_backends');
    }
};
