<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox: o evento é gravado na mesma transação da mudança
        // de estado e publicado depois pelo relay. Ver docs/adr/0002.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 50);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->text('last_error')->nullable();

            $table->index(['published_at', 'available_at']);
        });

        // Inbox do consumidor: garante processamento único em entrega "at least once".
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->uuid('message_id');
            $table->string('consumer', 100);
            $table->timestamp('processed_at');

            $table->primary(['message_id', 'consumer']);
        });

        Schema::create('notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('channel', 20);
            $table->string('subject');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
        Schema::dropIfExists('processed_messages');
        Schema::dropIfExists('outbox_messages');
    }
};
