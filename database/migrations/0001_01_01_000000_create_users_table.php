<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff identities federated from Koha. No passwords are stored: Koha
     * remains the source of truth for authentication.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('koha_instance_id', 64);
            $table->unsignedBigInteger('koha_patron_id');
            $table->string('koha_userid', 75);
            $table->string('display_name')->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('role', 32)->default('cataloguer')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamps();

            $table->unique(['koha_instance_id', 'koha_patron_id']);
            $table->index(['koha_instance_id', 'koha_userid']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
