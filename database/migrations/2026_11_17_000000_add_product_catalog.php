<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        foreach (['catalog_suppliers', 'catalog_technologies', 'catalog_equipment'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('search_name')->index();
            $table->foreignId('category_id')->constrained('catalog_categories')->restrictOnDelete();
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('unit');
            $table->foreignId('supplier_id')->nullable()->constrained('catalog_suppliers')->restrictOnDelete();
            $table->foreignId('technology_id')->nullable()->constrained('catalog_technologies')->restrictOnDelete();
            $table->json('equipment_ids')->nullable();
            $table->json('alternative_ids')->nullable();
            $table->json('fields')->nullable();
            $table->boolean('area_pricing')->default(false);
            $table->unsignedBigInteger('reference_minor')->nullable();
            $table->boolean('negotiable')->default(true);
            $table->boolean('active')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('catalog_products')->restrictOnDelete();
            $table->json('catalog_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('catalog_snapshot');
        });
        foreach (['catalog_products', 'catalog_equipment', 'catalog_technologies', 'catalog_suppliers', 'catalog_categories'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
