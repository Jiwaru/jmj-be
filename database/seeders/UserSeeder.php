<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Wajib jalan PERTAMA: catalog_prices.changed_by FK NOT NULL ke users.
 *
 * Keempat role diisi supaya seluruh endpoint bisa dicoba tanpa insert manual.
 * Dua field_officer sengaja — supaya penolakan 403 "laporan orang lain" bisa
 * diuji dari Postman, bukan cuma dari test.
 *
 * TIGA maintenance_officer, juga sengaja: regu perbaikan diwakili baris-baris
 * operational_team, jadi dengan satu akun saja "regu 3 orang" tidak bisa di-seed dan
 * layar daftar pelaksana tidak pernah terlihat dengan lebih dari satu nama.
 */
class UserSeeder extends Seeder
{
    /** Password seragam. Seeder ini alat pengembangan, bukan data produksi. */
    private const PASSWORD = 'password';

    public function run(): void
    {
        $users = [
            ['JMTO001', 'Bagus Prakoso', 'Petugas Patroli', 'jmto', 'field_officer'],
            ['JMTO002', 'Rian Saputra', 'Petugas Patroli', 'jmto', 'field_officer'],
            ['JMTO003', 'Dewi Anggraini', 'MCS Supervisor', 'jmto', 'mcs_supervisor'],
            ['JMJ001', 'Hendra Wijaya', 'Unit Maintenance', 'jmj', 'maintenance_unit'],
            ['JMTM001', 'Slamet Riyadi', 'Petugas Maintenance', 'jmtm', 'maintenance_officer'],
            ['JMTM002', 'Yusuf Alamsyah', 'Petugas Maintenance', 'jmtm', 'maintenance_officer'],
            ['JMTM003', 'Bambang Sutrisno', 'Petugas Maintenance', 'jmtm', 'maintenance_officer'],
        ];

        foreach ($users as [$code, $name, $position, $entity, $role]) {
            User::updateOrCreate(['identity_code' => $code], [
                'name' => $name,
                'position' => $position,
                'entity' => $entity,
                'role' => $role,
                'is_active' => true,
                'password' => self::PASSWORD,
            ]);
        }
    }
}
