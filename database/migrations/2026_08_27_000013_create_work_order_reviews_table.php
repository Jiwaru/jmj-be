<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->boolean('meets_quality_standard')->default(false);
            $table->boolean('quantity_matches_log')->default(false);
            $table->string('outcome', 30);
            $table->text('rejection_notes')->nullable();
            $table->timestamp('reviewed_at');

            $table->index('work_order_id');
        });

        // Dibaca dari config, BUKAN diketik ulang: daftar yang sama dipakai Rule::in()
        // di ReviewWorkOrderRequest, dan satu huruf beda sudah cukup untuk membuat CHECK
        // menolak apa yang validasi terima.
        $outcome = collect(config('enums.wo_review_outcome'))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
        DB::statement("ALTER TABLE work_order_reviews ADD CONSTRAINT work_order_reviews_outcome_check CHECK (outcome IN ({$outcome}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_reviews');
    }
};
