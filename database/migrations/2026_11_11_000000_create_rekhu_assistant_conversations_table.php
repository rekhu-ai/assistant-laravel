<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's conversations, so a user finds them in the chat's list days later: who it belongs to (a hash of the
 * owner), its chat (scope), its title (the first question) and the name the user gave it, how many questions it has, the
 * user's questions in lowercase for the search, the package's state of it, and until when it is kept.
 */
return new class extends Migration
{
    /** The table, in the connection and under the name of the package's config. */
    public function up(): void
    {
        Schema::connection(config('rekhu-assistant.conversations.connection'))->create(config('rekhu-assistant.conversations.table', 'rekhu_assistant_conversations'), function (Blueprint $table) {
            $table->char('owner_hash', 40);
            $table->string('id', 64);
            $table->string('scope', 64)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('name', 80)->nullable();
            $table->unsignedInteger('questions')->default(0);
            $table->text('search')->nullable();
            $table->longText('data');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->primary(['owner_hash', 'id']);
            $table->index(['owner_hash', 'scope', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('rekhu-assistant.conversations.connection'))->dropIfExists(config('rekhu-assistant.conversations.table', 'rekhu_assistant_conversations'));
    }
};
