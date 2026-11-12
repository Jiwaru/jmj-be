<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data lama disesuaikan dengan keputusan PO 5 Okt. Jalan SESUDAH migration CHECK
 * (2026_10_05_000000), dan TIDAK BISA DI-ROLLBACK isinya: setelah UPDATE tidak ada cara
 * tahu baris mana yang dulunya 'Dikembalikan ke Regu' atau WO mana yang dulunya tanpa
 * koordinator. down() sengaja kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Outcome lama → 'Dikerjakan Ulang' (perlakuannya memang sama persis). Sesudah
        //    itu CHECK yang dipasang NOT VALID divalidasi penuh — berlaku untuk SEMUA baris.
        DB::table('work_order_reviews')
            ->where('outcome', 'Dikembalikan ke Regu')
            ->update(['outcome' => 'Dikerjakan Ulang']);

        DB::statement('ALTER TABLE work_order_reviews VALIDATE CONSTRAINT work_order_reviews_outcome_check');
        DB::statement('ALTER TABLE work_order_logs VALIDATE CONSTRAINT work_order_logs_reason_check');

        // 2. Koordinator untuk WO yang belum punya. Diambil dari FAKTA, tidak ditebak:
        //    a. orang yang menerima tugas (`received_by`), kalau dia masih di regu;
        //    b. kalau belum ada, anggota tunggal regu satu orang.
        //    Sisanya (regu > 1 orang, belum diterima) dibiarkan — statusnya pasti `new`,
        //    jadi Unit masih bisa menunjuk koordinator lewat ganti regu.
        $tanpaKoordinator = 'NOT EXISTS (SELECT 1 FROM operational_team c WHERE c.work_order_id = operational_team.work_order_id AND c.is_coordinator)';

        DB::statement(
            'UPDATE operational_team SET is_coordinator = true FROM work_orders w '
            .'WHERE operational_team.work_order_id = w.id AND operational_team.user_id = w.received_by '
            ."AND {$tanpaKoordinator}"
        );

        DB::statement(
            'UPDATE operational_team SET is_coordinator = true '
            .'WHERE work_order_id IS NOT NULL '
            .'AND (SELECT count(*) FROM operational_team t WHERE t.work_order_id = operational_team.work_order_id) = 1 '
            ."AND {$tanpaKoordinator}"
        );
    }

    public function down(): void
    {
        // Tidak bisa dikembalikan — lihat docblock.
    }
};
