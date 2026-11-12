<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Keempat dari pasangan incident_report_id/damage_minutes_id — sama pola:
            // WO dari temuan patroli menunjuk laporan asalnya untuk keterlacakan, dan
            // supaya laporan itu bisa hilang dari maintenance/patrol-queue begitu WO-nya
            // terbit (whereDoesntHave('workOrder')).
            $table->uuid('patrol_report_id')->nullable()->after('damage_minutes_id');
            $table->foreign('patrol_report_id', 'fk_wo_patrol')->references('uuid')->on('patrol_report')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign('fk_wo_patrol');
            $table->dropColumn('patrol_report_id');
        });
    }
};
