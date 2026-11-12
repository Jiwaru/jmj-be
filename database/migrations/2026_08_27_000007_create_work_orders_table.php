<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->unique();
            $table->uuid('incident_report_id')->nullable();
            $table->uuid('damage_minutes_id')->nullable();
            $table->foreignId('asset_catalog_id')->nullable()->constrained('asset_catalog')->restrictOnDelete();
            $table->string('trigger_source', 20);
            $table->string('trigger_note', 255)->nullable();
            $table->string('priority', 10)->default('Sedang');
            $table->text('damage_description');
            $table->string('location_type', 20)->default('Jalur Utama');
            $table->smallInteger('km')->nullable();
            $table->smallInteger('m')->nullable();
            $table->integer('total_m')->nullable();
            $table->string('route', 5)->nullable();
            $table->string('lane', 20)->nullable();
            $table->char('shift', 1)->nullable();
            $table->string('warranty_status', 20)->nullable();
            $table->decimal('cost_amount', 15, 2)->nullable();
            $table->string('repair_status', 20)->default('new');
            $table->timestamp('sla_deadline')->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('incident_report_id', 'fk_wo_incident')->references('uuid')->on('incident_report')->restrictOnDelete();
            $table->foreign('damage_minutes_id', 'fk_wo_minutes')->references('uuid')->on('damage_ba')->restrictOnDelete();

            $table->index('repair_status');
            $table->index(['route', 'total_m']);
        });

        // Dibaca dari config, sama seperti location_type dan lane di bawah: daftar yang
        // sama dipakai Rule::in() di StoreWorkOrderRequest, dan satu huruf beda sudah
        // cukup untuk membuat CHECK menolak apa yang validasi terima.
        $trigger = collect(config('enums.wo_trigger_source'))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_trigger_check CHECK (trigger_source IN ({$trigger}))");
        $priority = collect(config('enums.wo_priority'))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_priority_check CHECK (priority IN ({$priority}))");
        // Dibaca dari config, BUKAN diketik ulang: kosakata yang sama dengan
        // incident_report.location_type, jadi sumbernya juga harus satu.
        $location = collect(config('enums.location_type'))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_location_check CHECK (location_type IN ({$location}))");
        // Kosakata `lane` sama persis dengan incident_report, jadi sumbernya juga
        // harus satu. Kalau diketik ulang di sini, satu kata bisa punya dua arti di
        // dua tabel dan tidak ada yang menjaganya tetap sama.
        $lane = collect(config('enums.lane'))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_lane_check CHECK (lane IS NULL OR lane IN ({$lane}))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_route_check CHECK (route IS NULL OR route IN ('A','B'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_shift_check CHECK (shift IS NULL OR shift IN ('1','2','3'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_warranty_check CHECK (warranty_status IS NULL OR warranty_status IN ('under_warranty','out_of_warranty'))");
        // `preparing` masuk 3 Sep bersama Fase 5: antara petugas MENERIMA tugas dan
        // benar-benar mulai bekerja ada keadaan nyata — regu menarik material dari
        // gudang, dan gerbang APD belum dilewati. Tanpa nilai ini, WO yang baru
        // diterima tidak bisa dibedakan dari WO yang sedang dikerjakan, dan gerbang
        // APD tidak punya keadaan untuk dijaga.
        //
        // TETAP INGGRIS seperti kelima saudaranya: repair_status diturunkan server,
        // tidak pernah dikirim layar. Itu garis pemisah kosakata yang dipakai sistem ini.
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_repair_status_check CHECK (repair_status IN ('new','preparing','in_progress','submitted','rework','completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
