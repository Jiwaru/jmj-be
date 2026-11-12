<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Urutannya mengikat tiga kali:
     *   catalog_prices.changed_by FK NOT NULL ke users  → UserSeeder duluan
     *   damaged_items butuh harga katalog               → CatalogSeeder sebelum insiden
     *   Work Order terbit dari damage_ba tervalidasi    → IncidentSeeder sebelum WO
     *
     * Yang ketiga bukan sekadar urutan FK — itu alurnya sendiri: pemicu Work Order adalah
     * pengesahan BA Kerusakan, jadi tidak ada cara membuat WO contoh sebelum ada laporan
     * contoh yang BA-nya sudah disahkan.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CatalogSeeder::class,
            IncidentSeeder::class,
            PatrolReportSeeder::class,
            WorkOrderSeeder::class,
            StagingTopUpSeeder::class,
        ]);
    }
}
