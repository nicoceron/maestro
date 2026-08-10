<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'name']);
        });

        Schema::create('people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('preferred_name', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('pronouns', 60)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'last_name', 'first_name']);
            $table->index(['studio_id', 'email']);
        });

        Schema::create('household_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('household_id');
            $table->foreignUlid('person_id');
            $table->string('role', 24);
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('receives_billing')->default(false);
            $table->timestamps();

            $table->foreign(['studio_id', 'household_id'])
                ->references(['studio_id', 'id'])
                ->on('households')
                ->cascadeOnDelete();
            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
            $table->unique(['studio_id', 'household_id', 'person_id']);
            $table->index(['studio_id', 'person_id']);
        });

        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('person_id');
            $table->string('status', 24)->default('active');
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('school_grade', 60)->nullable();
            $table->json('learning_preferences')->nullable();
            $table->timestamps();

            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
            $table->unique(['studio_id', 'person_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'status']);
        });

        Schema::create('guardian_relationships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('household_id');
            $table->foreignUlid('guardian_person_id');
            $table->foreignUlid('student_person_id');
            $table->string('relationship', 32);
            $table->boolean('is_legal_guardian')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('is_authorized_pickup')->default(false);
            $table->json('portal_permissions');
            $table->timestamps();

            $table->foreign(['studio_id', 'household_id'])
                ->references(['studio_id', 'id'])
                ->on('households')
                ->cascadeOnDelete();
            $table->foreign(['studio_id', 'guardian_person_id'])
                ->references(['studio_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
            $table->foreign(['studio_id', 'student_person_id'])
                ->references(['studio_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
            $table->unique([
                'studio_id',
                'household_id',
                'guardian_person_id',
                'student_person_id',
            ], 'guardian_relationship_pair_unique');
            $table->index(['studio_id', 'student_person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_relationships');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('people');
        Schema::dropIfExists('households');
    }
};
