<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item-level attachment (plugin 2.5+): an asset may belong to one Koha item
 * of its record. The link is the itemnumber; the snapshot only supplies the
 * display label (barcode, copy number, call number), which may change later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('biblio_id');
            $table->json('item_snapshot')->nullable()->after('biblio_snapshot_at');
            $table->timestamp('item_snapshot_at')->nullable()->after('item_snapshot');

            $table->index(['koha_instance_id', 'biblio_id', 'item_id']);
        });

        Schema::table('publication_operations', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('biblio_id');
        });
    }

    public function down(): void
    {
        Schema::table('publication_operations', function (Blueprint $table) {
            $table->dropColumn('item_id');
        });

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->dropIndex(['koha_instance_id', 'biblio_id', 'item_id']);
            $table->dropColumn(['item_id', 'item_snapshot', 'item_snapshot_at']);
        });
    }
};
