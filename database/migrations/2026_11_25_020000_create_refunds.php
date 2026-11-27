<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 29)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('reason');
            $table->enum('method', ['cash', 'bank_transfer']);
            $table->enum('status', ['created', 'approved', 'completed', 'rejected', 'cancelled'])->default('created');
            $table->enum('source', ['SFP', 'Promolux'])->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('executed_by')->nullable()->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('executed_at')->nullable()->index();
            $table->dateTime('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->uuid('approval_key')->nullable()->unique();
            $table->uuid('execution_key')->nullable()->unique();
            $table->uuid('closure_key')->nullable()->unique();
            $table->string('document')->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status', 'currency']);
        });

        Schema::create('refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained();
            $table->foreignId('payment_id')->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['refund_id', 'payment_id']);
            $table->index('payment_id');
        });

        Schema::create('refund_execution_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('idempotency_key')->unique();
            $table->text('reason');
            $table->timestamps();
            $table->index('refund_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_execution_issues');
        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('refunds');
    }
};
