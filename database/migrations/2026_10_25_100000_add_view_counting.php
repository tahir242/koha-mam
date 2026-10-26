<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement-based view counting. Page loads become "open" events; a "view"
 * is recorded once the visitor has actually watched or read the resource,
 * and only qualifying views increase the public total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('view_count')->default(0)->after('marc_verified_at');
            $table->timestamp('last_viewed_at')->nullable()->after('view_count');

            $table->index('view_count');
        });

        Schema::table('media_access_events', function (Blueprint $table) {
            // Whether a "view" event increased the public total (false: staff, repeat, bot, …).
            $table->boolean('counted')->default(false)->after('is_staff');
            $table->string('reason', 16)->nullable()->after('counted');

            // Explicit name: the generated one is longer than MySQL/MariaDB's 64-character limit.
            $table->index(['digital_asset_id', 'event', 'counted', 'created_at'], 'media_access_events_counting_index');
        });

        // Until now every page load was stored as "view": those are opens.
        DB::table('media_access_events')->where('event', 'view')->update(['event' => 'open']);

        // Starting totals: one view per visitor per day for public (non-staff) opens.
        $starts = DB::table('media_access_events')
            ->where('event', 'open')->where('is_staff', false)->whereNotNull('visitor_hash')
            ->selectRaw('digital_asset_id, COUNT(DISTINCT visitor_hash) AS views, MAX(created_at) AS last_viewed_at')
            ->groupBy('digital_asset_id')->get();

        foreach ($starts as $row) {
            DB::table('digital_assets')->where('id', $row->digital_asset_id)
                ->update(['view_count' => $row->views, 'last_viewed_at' => $row->last_viewed_at]);
        }
    }

    public function down(): void
    {
        DB::table('media_access_events')->where('event', 'view')->delete();
        DB::table('media_access_events')->where('event', 'open')->update(['event' => 'view']);

        Schema::table('media_access_events', function (Blueprint $table) {
            $table->dropIndex('media_access_events_counting_index');
            $table->dropColumn(['counted', 'reason']);
        });

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->dropIndex(['view_count']);
            $table->dropColumn(['view_count', 'last_viewed_at']);
        });
    }
};
