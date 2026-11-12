<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_logs', function (Blueprint $table) {
            // PK uuid native, BUKAN id bigint — keputusan 3 Sep yang menutup Mentok #7.
            // Aplikasi Petugas Maintenance bekerja LURING, dan foto bukti menempel PER
            // BARIS LOG. Identitas barisnya harus sudah ada di HP saat foto diambil di
            // bahu jalan; id bigint lahir di server, jadi saat itu belum ada apa pun
            // untuk ditempeli. Persis masalah tanda tangan Fase 1.
            //
            // Pola yang sama dengan incident_report, incident_ba, dan damage_ba —
            // ketiganya uuid native karena alasan yang identik. documents.documentable_id
            // varchar(36) memang dibuat untuk menampung uuid maupun bigint.
            $table->uuid('uuid')->primary();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('entry_type', 20);
            $table->string('bda_stage', 10)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('shift', 1)->nullable();
            $table->smallInteger('day_number')->default(1);
            $table->timestamp('logged_at');
            $table->text('notes')->nullable();

            // Centang APD per petugas, cuma terisi pada baris entry_type = 'apd_check'.
            // jsonb array, BUKAN 4 kolom boolean: daftar APD bisa bertambah, dan 4 kolom
            // berarti migration tiap kali kantor menambah satu item plus kolom lama yang
            // menggantung selamanya. Pola yang sama dengan incident_report.action.
            $table->jsonb('apd_items')->nullable();

            // Alasan berbentuk PILIHAN, terpisah dari `notes` yang teks bebas. Figma
            // menaruh keduanya bersebelahan ("Alasan Oper Shift" + "Catatan Serah
            // Terima") dan itu bukan kembar: alasannya bisa dihitung, catatannya tidak.
            // Satu kolom untuk dua entry_type karena bentuk datanya identik — yang
            // berbeda daftarnya, dan itu dijaga CHECK bersyarat di bawah.
            $table->string('reason', 60)->nullable();

            $table->index(['work_order_id', 'logged_at']);
        });

        $quote = fn (string $key) => collect(config($key))->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        // Dibaca dari config, bukan diketik ulang. Sejak Fase 5 nilai-nilai ini DIKIRIM
        // layar yang bekerja luring — salinan di HP yang melenceng dari CHECK menghasilkan
        // 422 di lapangan, tempat paling buruk untuk ketahuan.
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_entry_check CHECK (entry_type IN ({$quote('enums.wo_log_entry_type')}))");
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_bda_check CHECK (bda_stage IS NULL OR bda_stage IN ({$quote('enums.wo_bda_stage')}))");
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_shift_check CHECK (shift IS NULL OR shift IN ('1','2','3'))");

        // DUA penjaga untuk apd_items, dan dua-duanya perlu:
        //   1. isinya harus dari daftar resmi
        //   2. dia ada TEPAT pada baris apd_check, tidak kurang tidak lebih
        //
        // Tanpa penjaga kedua, baris 'progress' boleh membawa centang APD dan baris
        // 'apd_check' boleh kosong — gerbang yang tidak menjaga apa pun.
        //
        // `<@` MELOLOSKAN DUPLIKAT: ["Helm Safety","Helm Safety"] tetap subset. Yang
        // menolaknya `distinct` di FormRequest, bukan database. Sudah pernah kena di
        // incident_report.action 1 Sep.
        $apd = DB::getPdo()->quote(json_encode(config('enums.wo_apd_items')));
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_apd_items_check CHECK (apd_items IS NULL OR (jsonb_typeof(apd_items) = 'array' AND apd_items <@ {$apd}::jsonb))");
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_apd_on_check CHECK ((entry_type = 'apd_check') = (apd_items IS NOT NULL))");

        // CHECK bersyarat: satu kolom, dua daftar, dipilih menurut entry_type. Jenis
        // baris yang lain WAJIB null — kalau tidak, alasan oper shift bisa nyangkut di
        // baris 'complete' dan tidak ada yang tahu artinya apa.
        DB::statement(
            'ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_reason_check CHECK ('
            ."(entry_type = 'shift_handover' AND reason IN ({$quote('enums.wo_handover_reason')}))"
            ." OR (entry_type = 'obstacle' AND reason IN ({$quote('enums.wo_obstacle_reason')}))"
            ." OR (entry_type NOT IN ('shift_handover','obstacle') AND reason IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_logs');
    }
};
