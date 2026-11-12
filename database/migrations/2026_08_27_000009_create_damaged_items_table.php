<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damaged_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('incident_report_id');
            $table->foreign('incident_report_id', 'fk_di_incident')->references('uuid')->on('incident_report')->cascadeOnDelete();
            $table->foreignId('asset_catalog_id')->constrained('asset_catalog')->restrictOnDelete();
            $table->foreignId('catalog_price_id')->constrained('catalog_prices')->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('damage_percentage', 5, 2);
            $table->decimal('subtotal', 15, 2);
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index('incident_report_id');
            $table->index('work_order_id');
        });

        DB::statement("ALTER TABLE damaged_items ADD CONSTRAINT damaged_items_pct_check CHECK (damage_percentage BETWEEN 0 AND 100)");
    }

    public function down(): void
    {
        Schema::dropIfExists('damaged_items');
    }
};
