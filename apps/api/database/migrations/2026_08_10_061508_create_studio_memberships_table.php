<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('studio_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('teacher')->index();
            $table->string('status', 24)->default('invited')->index();
            $table->string('job_title', 120)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->unique(['studio_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_memberships');
    }
};
