<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_agreement_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64);
            $table->unsignedInteger('version');
            $table->foreignId('seller_id')->nullable()->constrained('users');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('rop_id')->constrained('users');
            $table->unsignedSmallInteger('owner_sfp_bps');
            $table->unsignedSmallInteger('rop_sfp_bps');
            $table->unsignedSmallInteger('owner_funding_bps');
            $table->unsignedSmallInteger('rop_funding_bps');
            $table->dateTime('effective_from');
            $table->foreignId('published_by')->constrained('users');
            $table->timestamps();
            $table->unique(['scope_key', 'version']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('initial_seller_id')->nullable()->constrained('users');
            $table->string('initial_seller_role', 32)->nullable();
        });

        Schema::create('order_financial_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('seller_id')->constrained('users');
            $table->string('seller_role', 32);
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('rop_id')->constrained('users');
            $table->unsignedSmallInteger('owner_sfp_bps');
            $table->unsignedSmallInteger('rop_sfp_bps');
            $table->unsignedSmallInteger('owner_funding_bps');
            $table->unsignedSmallInteger('rop_funding_bps');
            $table->foreignId('agreement_version_id')->nullable()->constrained('financial_agreement_versions', indexName: 'order_terms_agreement_version_foreign');
            $table->string('policy_version', 32);
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->dateTime('confirmed_at');
            $table->text('confirmation_reason')->nullable();
            $table->timestamps();
        });

        foreach (['payments', 'cash_receipts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('order_financial_terms_id')->nullable()->constrained('order_financial_terms');
            });
        }

        Schema::create('user_role_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('previous_role', 32)->nullable();
            $table->string('role', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->dateTime('recorded_at');
            $table->index(['user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_histories');

        foreach (['cash_receipts', 'payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('order_financial_terms_id');
            });
        }

        Schema::dropIfExists('order_financial_terms');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initial_seller_id');
            $table->dropColumn('initial_seller_role');
        });
        Schema::dropIfExists('financial_agreement_versions');
    }
};
