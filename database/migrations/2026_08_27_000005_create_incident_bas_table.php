<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_ba', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('incident_ba_number', 60)->unique();
            $table->uuid('incident_report_id')->unique();
            $table->foreignId('officer_1_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('officer_2_id')->constrained('users')->restrictOnDelete();
            $table->string('duty_period', 60)->nullable();
            $table->char('shift', 1)->nullable();
            $table->string('validation_status', 20)->default('submitted');
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('incident_report_id', 'fk_iba_report')->references('uuid')->on('incident_report')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE incident_ba ADD CONSTRAINT incident_ba_shift_check CHECK (shift IS NULL OR shift IN ('1','2','3'))");
        DB::statement("ALTER TABLE incident_ba ADD CONSTRAINT incident_ba_validation_check CHECK (validation_status IN ('submitted','validated','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_ba');
    }
};
