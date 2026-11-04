<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            // Valores monetários sempre em centavos (inteiro): sem erro de ponto flutuante.
            $table->unsignedBigInteger('monthly_budget_cents');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('traveler_id')->constrained('users');
            $table->foreignId('cost_center_id')->constrained();
            $table->foreignId('approver_id')->nullable()->constrained('users');
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->date('departure_on');
            $table->date('return_on');
            $table->unsignedBigInteger('estimated_cost_cents');
            $table->string('reason', 500);
            $table->string('status', 20);
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Índices desenhados para as consultas reais: listagem por empresa/status
            // e soma de orçamento comprometido por centro de custo no mês.
            $table->index(['company_id', 'status', 'id']);
            $table->index(['cost_center_id', 'status', 'departure_on']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('supplier', 100);
            $table->string('locator', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['travel_request_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('travel_requests');
        Schema::dropIfExists('cost_centers');
    }
};
