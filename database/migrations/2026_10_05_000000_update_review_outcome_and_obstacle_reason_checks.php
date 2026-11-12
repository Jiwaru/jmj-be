<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan PO 5 Okt: outcome evaluasi tinggal 'Diterima' + 'Dikerjakan Ulang', dan
 * alasan kendala bertambah 'Arus Lalu Lintas Padat'.
 *
 * TIDAK MENGUBAH DATA. Dua CHECK di bawah dibangun dari config SAAT MIGRATE, jadi
 * mengubah config saja tidak cukup — validasi dan database jadi berbeda daftar, dan
 * alasan baru dijawab 500 oleh database.
 *
 * Outcome dipasang NOT VALID: baris lama 'Dikembalikan ke Regu' dibiarkan sebagai
 * riwayat (tidak diperiksa), sementara evaluasi BARU wajib mengikuti daftar baru.
 * Aman karena work_order_reviews cuma ditambah, tidak pernah diubah.
 */
return new class extends Migration
{
    private const OUTCOME_LAMA = ['Diterima', 'Dikerjakan Ulang', 'Dikembalikan ke Regu'];

    private const ALASAN_KENDALA_LAMA = ['Material Belum Tersedia', 'Cuaca Tidak Mendukung', 'Akses Lokasi Tertutup', 'Peralatan Rusak', 'Lainnya'];

    public function up(): void
    {
        $this->outcome(config('enums.wo_review_outcome'));
        $this->reason(config('enums.wo_obstacle_reason'));
    }

    public function down(): void
    {
        $this->outcome(self::OUTCOME_LAMA);
        $this->reason(self::ALASAN_KENDALA_LAMA);
    }

    private function outcome(array $daftar): void
    {
        DB::statement('ALTER TABLE work_order_reviews DROP CONSTRAINT work_order_reviews_outcome_check');
        DB::statement("ALTER TABLE work_order_reviews ADD CONSTRAINT work_order_reviews_outcome_check CHECK (outcome IN ({$this->quote($daftar)})) NOT VALID");
    }

    /** Ekspresi sama persis dengan migration pembuatnya, cuma daftar kendalanya yang berganti. */
    private function reason(array $kendala): void
    {
        DB::statement('ALTER TABLE work_order_logs DROP CONSTRAINT work_order_logs_reason_check');
        DB::statement(
            'ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_reason_check CHECK ('
            ."(entry_type = 'shift_handover' AND reason IN ({$this->quote(config('enums.wo_handover_reason'))}))"
            ." OR (entry_type = 'obstacle' AND reason IN ({$this->quote($kendala)}))"
            ." OR (entry_type NOT IN ('shift_handover','obstacle') AND reason IS NULL)) NOT VALID"
        );
    }

    private function quote(array $daftar): string
    {
        return collect($daftar)->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');
    }
};
