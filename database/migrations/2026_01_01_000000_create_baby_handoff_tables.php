<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('families', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('baby_name', 60);
            $t->boolean('test_sync')->default(false);
            $t->unsignedBigInteger('revision')->default(0);
            $t->foreignId('on_duty_id')->constrained('users');
            $t->timestamp('on_duty_since');
            $t->timestamps();
        });
        Schema::create('family_members', function (Blueprint $t) {
            $t->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['family_id', 'user_id']);
        });
        Schema::create('family_invites', function (Blueprint $t) {
            $t->string('code_hash', 64)->primary();
            $t->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('redeemed_at')->nullable();
        });
        Schema::create('care_events', function (Blueprint $t) {
            $t->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $t->uuid('id');
            $t->unsignedBigInteger('revision');
            $t->foreignId('author_id')->constrained('users');
            $t->string('type', 10);
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->json('details');
            $t->boolean('deleted')->default(false);
            $t->primary(['family_id','id']);
            $t->unique(['family_id','revision']);
        });
        Schema::create('sync_operations', function (Blueprint $t) {
            $t->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('id');
            $t->json('result');
            $t->primary(['family_id','user_id','id']);
        });
        Schema::create('handoffs', function (Blueprint $t) {
            $t->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $t->uuid('id');
            $t->foreignId('sender_id')->constrained('users');
            $t->foreignId('recipient_id')->constrained('users');
            $t->json('snapshot');
            $t->string('note', 500)->nullable();
            $t->timestamp('created_at');
            $t->timestamp('accepted_at')->nullable();
            $t->primary(['family_id','id']);
        });
    }

    public function down(): void
    {
        foreach (['handoffs','sync_operations','care_events','family_invites','family_members','families','personal_access_tokens','users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
