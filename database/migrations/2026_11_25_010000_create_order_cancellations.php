<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('previous_status', 32);
            $table->text('reason');
            $table->foreignId('cancelled_by')->constrained('users');
            $table->dateTime('cancelled_at');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('restored_by')->nullable()->constrained('users');
            $table->dateTime('restored_at')->nullable();
            $table->uuid('restore_idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['order_id', 'id']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('order_cancellations');
    }
};
