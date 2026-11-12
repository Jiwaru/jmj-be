<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('incident_report_id')->nullable();
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->timestamps();

            $table->foreign('incident_report_id')->references('uuid')->on('incident_report')->restrictOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE operational_team ADD CONSTRAINT operational_team_one_parent_check CHECK (num_nonnulls(incident_report_id, work_order_id) = 1)");
        DB::statement("CREATE UNIQUE INDEX operational_team_incident_unique ON operational_team (incident_report_id, user_id)");
        DB::statement("CREATE UNIQUE INDEX operational_team_wo_unique ON operational_team (work_order_id, user_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_team');
    }
};
