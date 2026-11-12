<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams and team memberships (090-g Phase 3).
 *
 * Membership is a relational graph: it is queried in both directions ("my
 * teams" and "who is on this team"), so a pivot table beats a JSON blob.
 *
 * `role` is a database-level enum — MySQL ENUM, SQLite `varchar ... check`,
 * both compiled by Laravel's own grammar — so the constrained role set holds
 * even for writes that bypass the application.
 *
 * `profession_slug` lives on the membership, not the team: it answers "who on
 * this team is the designer?" without a second join. It is a catalogue slug,
 * not a foreign key, because the catalogue is versioned reference data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64)->default('default');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id']);
        });

        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64)->default('default');
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'manager', 'member', 'viewer'])->default('member');
            $table->string('profession_slug', 120)->nullable();
            $table->foreignId('invited_by')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['team_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
