<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_report', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            // Nullable: nomor baru terbit saat MCS Supervisor mengesahkan, bukan saat
            // petugas mengirim. HP tidak boleh mengalokasikan nomor sendiri — dua
            // petugas luring bisa mengambil urutan yang sama dan yang kedua nyangkut
            // di unique constraint. PostgreSQL mengizinkan banyak NULL dalam unique
            // index, jadi antrean laporan belum-bernomor tidak saling tabrak.
            $table->string('report_number', 30)->nullable()->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('date_time');
            $table->smallInteger('km')->nullable();
            $table->smallInteger('m')->nullable();
            $table->string('lane', 20)->nullable();
            $table->string('route', 5)->nullable();
            // Jenis elemen jalan tempat kejadian. Nilainya SAMA PERSIS dengan
            // work_orders.location_type — bukan daftar kedua.
            //
            // Bukan sekadar mengisi slot "Ramp ......" di formulir BA: unit
            // maintenance harus tahu titiknya. Kalau insidennya di ramp keluar dan
            // kolom ini salah, regu perbaikan dikirim ke jalur utama (Alur 4.2).
            //
            // default 'Jalur Utama' supaya kiriman aplikasi versi lama tetap masuk —
            // dan itu memang nilai yang benar untuk mayoritas kejadian.
            $table->string('location_type', 20)->default('Jalur Utama');
            $table->string('weather_condition', 20)->nullable();
            // Penyebab kejadian — pilihan tetap, bukan lagi ketikan bebas.
            $table->string('causer', 80);
            // Terisi HANYA kalau causer = 'Lainnya'. Dijaga CHECK biconditional di bawah.
            $table->string('causer_other', 120)->nullable();
            $table->text('incident_explanation')->nullable();
            // Tindakan yang diambil — satu-satunya pilihan jamak di form ini, makanya
            // jsonb dan bukan teks berkoma: nilainya sendiri mengandung '/' dan '(',
            // dan memisah string yang salah menghasilkan tindakan yang tidak pernah ada.
            $table->jsonb('action')->nullable();
            // NOT NULL: is_asset_broken diturunkan dari kolom ini, jadi kosong berarti
            // server tidak punya dasar untuk mengisi percabangan Fase 2.
            $table->string('analyze', 40);
            // Tindak lanjut yang DISARANKAN petugas. Bukan pemicu apa pun — Work Order
            // terbit dari validasi damage_ba di Fase 3. Kalau dipasang ke sini,
            // kerusakan tabrak lari tidak akan pernah jadi pekerjaan.
            $table->string('conclusion', 50)->nullable();
            // Diturunkan server dari `analyze`, tidak diterima dari HP. Syarat yang
            // dibaca MCS di Fase 2.1 untuk memutuskan perlu tidaknya damage_ba terbit.
            $table->boolean('is_asset_broken')->default(false);
            $table->boolean('is_identified')->default(false);
            $table->decimal('additional_cost', 15, 2)->default(0);
            $table->decimal('total_estimation', 15, 2)->default(0);
            // Bentuk penyelesaian — SK Pasal 4 ayat 1 cuma mengenal dua cara.
            // Keringanan bukan cara ketiga, dia anak kalimat di dalam cara (a),
            // makanya diwakili boolean terpisah. Nullable karena insiden tanpa
            // kerusakan dan kasus tabrak lari tidak pernah punya penyelesaian.
            $table->string('settlement_type', 30)->nullable();
            $table->boolean('relief_requested')->default(false);
            // Nominal yang disanggupi pengguna jalan, diisi di Step 5 bareng
            // unggahan dokumen keringanan. Nullable: cuma terisi kalau
            // relief_requested = true. Angka ini BUKAN angka yang mengikat —
            // yang mengikat ada di surat bermaterai yang disahkan kelurahan.
            $table->decimal('pledged_amount', 15, 2)->nullable();
            $table->string('sync_status', 20)->default('draft');
            // 'new' cuma hidup di database lokal HP (laporan yang masih diketik).
            // Di server tidak akan pernah ada baris ber-status 'new': begitu sync
            // sukses langsung 'pending_verification', karena kalau tidak masuk
            // antrean MCS tidak ada yang meng-ACC dan report_number tidak terbit.
            $table->string('report_status', 30)->default('new');
            $table->timestamps();

            $table->index(['route', 'km']);
            $table->index('report_status');
            $table->index('user_id');
        });

        // Daftar nilainya di config/enums.php — satu sumber untuk CHECK, Rule::in(),
        // dan bundel luring. Diketik ulang di sini berarti CHECK dan validasi bisa
        // berbeda satu huruf, dan itu ketahuannya di produksi.
        $in = fn (array $v) => collect($v)->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        $lane = $in(config('enums.lane'));
        $route = $in(config('enums.route'));
        $weather = $in(config('enums.weather'));
        $causer = $in(config('enums.causer'));
        $analyze = $in(config('enums.analyze'));
        $conclusion = $in(config('enums.conclusion'));
        $settlement = $in(config('enums.settlement'));
        $locationType = $in(config('enums.location_type'));
        $actions = DB::getPdo()->quote(json_encode(config('enums.action')));

        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_lane_check CHECK (lane IS NULL OR lane IN ({$lane}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_route_check CHECK (route IS NULL OR route IN ({$route}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_weather_check CHECK (weather_condition IS NULL OR weather_condition IN ({$weather}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_causer_check CHECK (causer IN ({$causer}))");
        // `analyze` dan `action` WAJIB dikutip: ANALYZE itu perintah SQL PostgreSQL
        // dan ACTION keyword-nya. Laravel mengutip identifier sendiri di CREATE TABLE,
        // tapi DB::statement mentah tidak — tanpa kutip, ini syntax error saat migrate.
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_analyze_check CHECK (\"analyze\" IN ({$analyze}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_conclusion_check CHECK (conclusion IS NULL OR conclusion IN ({$conclusion}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_settlement_check CHECK (settlement_type IS NULL OR settlement_type IN ({$settlement}))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_location_type_check CHECK (location_type IN ({$locationType}))");

        // Dua arah, bukan satu: 'Lainnya' WAJIB punya teks, dan selain 'Lainnya' teksnya
        // WAJIB kosong. Kalau cuma dijaga satu arah, causer_other bisa nyangkut berisi
        // teks lama setelah petugas mengganti pilihannya ke kategori betulan.
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_causer_other_check CHECK ((causer = 'Lainnya') = (causer_other IS NOT NULL))");

        // `<@` = "terkandung di dalam". Menolak elemen apa pun di luar daftar.
        // Duplikat lolos di sini (["A","A"] tetap subset) — yang menjaganya `distinct`
        // di SyncIncidentReportRequest.
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_action_check CHECK (\"action\" IS NULL OR (jsonb_typeof(\"action\") = 'array' AND \"action\" <@ {$actions}::jsonb))");

        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_sync_status_check CHECK (sync_status IN ('draft','queued','sent'))");
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_report_status_check CHECK (report_status IN ('new','pending_verification','verified','rejected','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report');
    }
};
