<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('beneficiary_id')->constrained('users');
            $table->string('payer', 100);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('kind', 24);
            $table->string('method', 24)->nullable();
            $table->text('reason');
            $table->date('settled_on');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            $table->index(['beneficiary_id', 'payer', 'currency', 'settled_on'], 'commission_settlements_account_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlements');
    }
};
