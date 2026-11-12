<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_ba', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('damage_ba_number', 60)->unique();
            $table->uuid('damage_report_id')->unique();
            $table->foreignId('supervisor_id')->constrained('users')->restrictOnDelete();
            $table->char('shift', 1)->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->string('amount_in_words', 255)->nullable();
            $table->string('validation_status', 20)->default('submitted');
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('damage_report_id', 'fk_dba_report')->references('uuid')->on('incident_ba')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE damage_ba ADD CONSTRAINT damage_ba_shift_check CHECK (shift IS NULL OR shift IN ('1','2','3'))");
        DB::statement("ALTER TABLE damage_ba ADD CONSTRAINT damage_ba_validation_check CHECK (validation_status IN ('submitted','validated','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_ba');
    }
};
