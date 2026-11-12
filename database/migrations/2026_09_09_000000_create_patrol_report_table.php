<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patrol_report', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('officer_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('patrolled_at');
            $table->string('route', 5)->nullable();
            $table->boolean('is_damaged')->default(false);

            // Kolom kerusakan — nullable, WAJIB diisi FormRequest kalau is_damaged=true.
            // Tidak ada catalog_price_id/damage_percentage/subtotal seperti damaged_items:
            // temuan patroli tidak menagih siapa pun, harganya baru muncul saat Unit
            // Maintenance menerbitkan Work Order (Alur 4.3, sama seperti Aus Normal/Preventif).
            $table->foreignId('asset_catalog_id')->nullable()->constrained('asset_catalog')->restrictOnDelete();
            $table->string('location_type', 20)->nullable();
            $table->smallInteger('km')->nullable();
            $table->smallInteger('m')->nullable();
            $table->string('lane', 20)->nullable();
            $table->text('damage_description')->nullable();

            $table->string('sync_status', 20)->default('draft');
            $table->timestamps();

            $table->index('officer_id');
        });

        // Sama seperti incident_report: satu sumber di config/enums.php, dipakai lagi
        // di FormRequest dan bundel luring. Diketik ulang di sini berarti CHECK dan
        // validasi bisa berbeda satu huruf.
        $in = fn (array $v) => collect($v)->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        $route = $in(config('enums.route'));
        $locationType = $in(config('enums.location_type'));
        $lane = $in(config('enums.lane'));

        DB::statement("ALTER TABLE patrol_report ADD CONSTRAINT patrol_report_route_check CHECK (route IS NULL OR route IN ({$route}))");
        DB::statement("ALTER TABLE patrol_report ADD CONSTRAINT patrol_report_location_type_check CHECK (location_type IS NULL OR location_type IN ({$locationType}))");
        DB::statement("ALTER TABLE patrol_report ADD CONSTRAINT patrol_report_lane_check CHECK (lane IS NULL OR lane IN ({$lane}))");
        DB::statement("ALTER TABLE patrol_report ADD CONSTRAINT patrol_report_sync_status_check CHECK (sync_status IN ('draft','queued','sent'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patrol_report');
    }
};
