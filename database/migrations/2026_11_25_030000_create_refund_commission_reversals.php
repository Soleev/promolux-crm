<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_commission_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained();
            $table->foreignId('accrual_id')->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->dateTime('recorded_at')->index();
            $table->timestamps();
            $table->unique(['refund_id', 'accrual_id'], 'refund_commission_refund_accrual_unique');
            $table->index('accrual_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_commission_reversals');
    }
};
