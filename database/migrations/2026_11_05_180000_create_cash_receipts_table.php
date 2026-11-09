<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('collector_id')->constrained('users');
            $table->foreignId('manager_id')->nullable()->constrained('users');
            $table->boolean('managed_sale');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('rop_id')->nullable()->constrained('users');
            $table->date('collected_at');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status')->default('pending')->index();
            $table->foreignId('cashier_id')->nullable()->constrained('users');
            $table->foreignId('payment_id')->nullable()->unique()->constrained();
            $table->date('handed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipts');
    }
};
