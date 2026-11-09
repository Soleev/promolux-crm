<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('manager')->index();
            $t->boolean('active')->default(true);
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('manager_id')->nullable()->constrained('users');
            $t->json('contacts')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('source')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('deals', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('client_id')->constrained();
            $t->foreignId('manager_id')->nullable()->constrained('users');
            $t->string('status')->default('new');
            $t->string('currency', 3)->default('UZS');
            $t->text('description')->nullable();
            $t->text('lost_reason')->nullable();
            $t->json('items')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('deal_id')->nullable()->unique()->constrained();
            $t->foreignId('manager_id')->nullable()->constrained('users');
            $t->string('currency', 3)->default('UZS');
            $t->string('status')->default('new');
            $t->date('due_at')->nullable();
            $t->text('description')->nullable();
            $t->string('attachment')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('price_minor');
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('manager_id')->constrained('users');
            $t->foreignId('client_id')->nullable()->constrained();
            $t->foreignId('deal_id')->nullable()->constrained();
            $t->foreignId('order_id')->nullable()->constrained();
            $t->dateTime('due_at');
            $t->string('status')->default('open');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('manager_id')->nullable()->constrained('users');
            $t->date('paid_at')->index();
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('recipient')->default('SFP');
            $t->string('method')->nullable();
            $t->text('notes')->nullable();
            $t->string('document')->nullable();
            $t->string('calculation_status');
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('payments');
            $t->timestamps();
        });
        Schema::create('accruals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained();
            $t->foreignId('beneficiary_id')->constrained('users');
            $t->string('kind');
            $t->string('payer');
            $t->unsignedInteger('rate_bps');
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->timestamps();
            $t->unique(['payment_id', 'kind']);
        });
        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique();
            $t->string('payer');
            $t->foreignId('recipient_id')->constrained('users');
            $t->date('paid_at')->index();
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->text('notes')->nullable();
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('payouts');
            $t->timestamps();
        });
        Schema::create('allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_id')->constrained();
            $t->foreignId('accrual_id')->constrained();
            $t->bigInteger('amount_minor');
            $t->timestamps();
            $t->unique(['payout_id', 'accrual_id']);
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->date('paid_at');
            $t->string('category');
            $t->bigInteger('amount_minor');
            $t->string('currency', 3)->default('UZS');
            $t->string('payer')->default('SFP');
            $t->foreignId('order_id')->nullable()->constrained();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->string('event');
            $t->json('changes')->nullable();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['activities', 'expenses', 'allocations', 'payouts', 'accruals', 'payments', 'tasks', 'order_items', 'orders', 'deals', 'clients'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
