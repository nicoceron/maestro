<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('email_normalized', 254);
            $table->string('role', 32);
            $table->char('token_hash', 64)->unique();
            $table->string('pending_key', 300)->nullable()->unique();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['studio_id', 'email_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_invitations');
    }
};
