<?php

namespace App\Http\Requests\Api;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penerbitan Work Order — SATU endpoint, DUA cabang.
 *
 * Konsep menyebutnya "satu tombol, satu kolom sumber_pemicu": temuan patroli, aus
 * normal, dan preventif terjadwal tidak melalui Fase 1-3 sama sekali, tapi mereka
 * menghasilkan pekerjaan yang bentuknya persis sama. Dua URL berarti dua layar, dua
 * daftar, dan dua tempat yang harus diubah setiap kali aturan WO berubah.
 *
 * Bedanya cuma DARI MANA isinya datang, dan itu yang dijaga di sini:
 *
 *   incident           →  laporannya yang memberi lokasi dan komponen
 *   patrol/preventive/wear →  orangnya yang mengetik lokasi dan menunjuk jenis aset
 *
 * `prohibited_if`, bukan diabaikan diam-diam. Kiriman yang membawa `asset_catalog_id`
 * untuk WO dari insiden itu tanda si pengirim mengira kolom itu dipakai — dan kalau
 * diterima lalu dibuang, dia tidak akan pernah tahu komponennya sebenarnya dibaca dari
 * `damaged_items`.
 */
class StoreWorkOrderRequest extends FormRequest
{
    public function rules(): array
    {
        // Ditulis sekali, dipakai enam kali di bawah.
        //
        // Nilainya disisipkan dari const, bukan diketik: string aturan Laravel dipecah
        // pada KOMA, jadi nilai enum yang suatu saat berkoma akan terbaca sebagai dua
        // argumen dan aturan ini diam-diam berhenti menjaga apa pun. Dengan const,
        // yang mengubah nilainya setidaknya mengubahnya di satu tempat.
        $insiden = WorkOrder::TRIGGER_INSIDEN;
        $patroli = WorkOrder::TRIGGER_PATROLI;
        $manual = "prohibited_if:trigger_source,{$insiden}";
        $wajibManual = "required_unless:trigger_source,{$insiden}";

        return [
            'trigger_source' => ['required', Rule::in(config('enums.wo_trigger_source'))],

            // ---- cabang insiden -------------------------------------------------
            // damage_minutes_id TIDAK diterima dari klien: BA Kerusakan laporan itu cuma
            // ada satu (damage_ba.damage_report_id unique), jadi server bisa
            // menemukannya sendiri. Menerimanya berarti membuka kemungkinan WO menunjuk
            // BA milik perkara lain.
            'incident_report_id' => [
                "required_if:trigger_source,{$insiden}",
                "prohibited_unless:trigger_source,{$insiden}",
                'uuid',
                Rule::exists('incident_report', 'uuid'),
            ],

            // ---- cabang manual --------------------------------------------------
            // WAJIB di sini, DILARANG di cabang insiden. Alasannya di ERD: WO manual
            // tidak punya damaged_items untuk dibaca, jadi jenis asetnya harus disebut;
            // WO dari insiden punya, dan dua sumber kebenaran yang bisa saling
            // bertentangan lebih buruk daripada satu kolom kosong.
            'asset_catalog_id' => [$wajibManual, $manual, 'integer', Rule::exists('asset_catalog', 'id')],
            'trigger_note' => [$manual, 'nullable', 'string', 'max:255'],

            // Opsional bahkan untuk trigger Temuan Patroli — Unit Maintenance boleh tetap
            // menerbitkan WO manual tanpa laporan patroli sumber, sama seperti sekarang
            // untuk Preventif/Aus Normal. Guard "sudah dipakai WO lain" ada di Service,
            // bukan di sini — butuh lock baris, bukan cuma query exists.
            'patrol_report_id' => ["prohibited_unless:trigger_source,{$patroli}", 'nullable', 'uuid', Rule::exists('patrol_report', 'uuid')],

            // Lokasi disalin server untuk WO insiden — laporannya sudah menyimpannya,
            // dan mengetiknya ulang membuka kemungkinan regu dikirim ke KM yang berbeda
            // dari yang tertulis di Berita Acara.
            //
            // km + route wajib, m + lane boleh kosong: aturan yang sama persis dengan
            // laporan insiden. Pasangan (route, km) itu satu-satunya jangkar analisis
            // titik rawan, karena tidak ada tabel aset terpasang.
            'location_type' => [$wajibManual, $manual, Rule::in(config('enums.location_type'))],
            // max 32767 itu BATAS KOLOM, bukan batas ruas jalan. `km` bertipe smallint;
            // tanpa batas atas, `km: 40000` lolos validasi lalu mati di Postgres dengan
            // SQLSTATE 22003 — 500, dan pesan SQL mentah bocor ke klien.
            //
            // Sengaja TIDAK dibatasi panjang ruas (±97 km). Mentok #2 belum dijawab:
            // belum jelas KM dihitung dari Kartasura atau lanjutan KM Trans-Jawa, dan
            // kalau ternyata yang kedua, batas 97 menolak data yang sah.
            'km' => [$wajibManual, $manual, 'integer', 'min:0', 'max:32767'],
            'm' => [$manual, 'nullable', 'integer', 'min:0', 'max:999'],
            'route' => [$wajibManual, $manual, Rule::in(config('enums.route'))],
            'lane' => [$manual, 'nullable', Rule::in(config('enums.lane'))],
            // Titik peta. WO insiden menyalinnya dari laporan, WO patroli dari laporan
            // patrolinya kalau tidak dikirim; selain itu boleh kosong — peta tidak tampil.
            'latitude' => [$manual, 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => [$manual, 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            // ---- dua-duanya -----------------------------------------------------
            // Untuk WO insiden boleh kosong: server menyusun kalimat baku dari lokasi
            // dan jumlah komponen laporannya. Untuk WO manual tidak ada yang bisa
            // disusun — "lampu PJU KM 45+200 mati" cuma ada di kepala yang melapor.
            'damage_description' => [$wajibManual, 'nullable', 'string', 'max:2000'],

            'priority' => ['required', Rule::in(config('enums.wo_priority'))],

            // WAJIB, dan itu keputusan sadar. Kolomnya nullable di database, tapi WO
            // tanpa target tidak pernah bisa terlambat — dia hilang dari kartu
            // "terlambat" di dashboard sambil tetap menggantung. Yang tidak punya
            // tenggat bukan pekerjaan, itu daftar keinginan.
            //
            // sla_deadline SENGAJA TIDAK DI SINI dan dibiarkan NULL: menurunkannya dari
            // priority butuh angka jam per tingkat yang belum pernah disebut kantor.
            //
            // BERJAM sejak 1 Okt. Kiriman tanggal saja tetap diterima dan dibaca 23:59:59
            // (lihat prepareForValidation) — web Unit Maintenance versi lama tidak patah.
            'target_date' => ['required', 'date', 'after:now'],

            // Aturan regu dibaca dari UpdateWorkOrderTeamRequest, TIDAK disalin: dua
            // daftar yang seharusnya identik selalu berakhir berbeda, dan yang di sini
            // longgar sementara yang di sana ketat berarti regu yang tidak boleh diganti
            // masih boleh ditunjuk pertama kali.
        ] + UpdateWorkOrderTeamRequest::crewRules($this->input('assignee_ids'));
    }

    /**
     * "2026-10-05" dibaca paling lambat hari itu, bukan pukul 00:00 — tanpa ini target
     * dari form lama maju hampir sehari, dan target "hari ini" langsung ditolak.
     */
    protected function prepareForValidation(): void
    {
        $target = $this->input('target_date');

        if (is_string($target) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
            $this->merge(['target_date' => $target.' 23:59:59']);
        }
    }

    public function messages(): array
    {
        return [
            'target_date.after' => 'Target pengerjaan harus di masa depan.',
            'asset_catalog_id.prohibited_if' => 'Work Order dari insiden membaca komponennya dari daftar kerusakan laporan, bukan dari jenis aset yang dipilih.',
            'incident_report_id.prohibited_unless' => 'Hanya Work Order dari insiden yang menunjuk laporan.',
            'incident_report_id.required_if' => 'Work Order dari insiden harus menunjuk laporan yang kerusakannya dikerjakan.',
        ] + UpdateWorkOrderTeamRequest::crewMessages();
    }
}
