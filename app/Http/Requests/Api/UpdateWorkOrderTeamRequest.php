<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ganti regu pelaksana sebuah Work Order. MENGGANTI seluruhnya, bukan menambah.
 *
 * Regu berubah itu nyata — orang sakit, tukar shift, dipindah ke pekerjaan yang lebih
 * mendesak. Tanpa endpoint ini salah tunjuk cuma bisa diperbaiki lewat database, dan
 * "cuma bisa lewat database" berarti dalam praktiknya tidak pernah diperbaiki.
 *
 * Batas WAKTUNYA: selama `new`, `preparing`, `in_progress` (WorkOrder::TEAM_EDITABLE_STATUSES,
 * keputusan PO 5 Okt) — Unit perlu menambah/mengganti teknisi saat pekerjaan berjalan.
 * Saat `submitted` (sedang dievaluasi) dan `completed` dijawab 422 oleh Service.
 *
 * KOORDINATOR terkunci begitu pekerjaan diterima (bukan `new` lagi): dia cuma berganti
 * lewat oper shift yang ditulisnya sendiri. Di sini `coordinator_id` karena itu cuma
 * WAJIB selagi `new`; sesudahnya boleh dihilangkan (koordinator lama dipertahankan), dan
 * Service yang menolak kalau isinya mencoba mengganti atau mengeluarkannya.
 *
 * KELAS INI PEMILIK ATURAN REGU — berapa orang, boleh kembar atau tidak, batas kiriman.
 * StoreWorkOrderRequest memakai crewRules() yang sama, bukan menyalinnya, karena dua daftar
 * yang seharusnya identik selalu berakhir berbeda.
 *
 * Yang BUKAN miliknya: definisi siapa yang boleh jadi pelaksana. Itu ada di
 * User::petugasMaintenance(), karena endpoint daftar petugas yang mengisi dropdown-nya harus
 * membaca predikat yang sama persis — dropdown yang lebih longgar dari validatornya
 * menyodorkan orang yang ditolak 422 justru pada saat dipilih.
 */
class UpdateWorkOrderTeamRequest extends FormRequest
{
    public function rules(): array
    {
        return self::crewRules(
            $this->input('assignee_ids'),
            $this->route('workOrder')?->repair_status === 'new',
        );
    }

    public function messages(): array
    {
        return self::crewMessages();
    }

    /**
     * Minimal SATU orang. ERD: "JANGAN pernah menulis nol baris" — Work Order yang
     * ditugaskan tanpa pelaksana tidak muncul di daftar siapa pun, dan tidak ada yang
     * pernah tahu dia ada.
     *
     * `distinct` mengubah orang yang sama dikirim dua kali jadi 422 berpesan. Tanpa itu
     * yang menjawab unique index (work_order_id, user_id) — 500 dengan pesan constraint
     * Postgres yang tidak menjelaskan apa-apa ke pemakainya.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function crewRules(mixed $dikirim = null, bool $wajibKoordinator = true): array
    {
        $rules = [
            // max 50 itu BATAS BIAYA REQUEST, bukan aturan besar regu. Regu perbaikan satu
            // titik lokasi tidak pernah sedekat pun ke 50 orang; yang dijaga di sini
            // penguatan request, bukan pertanyaan berapa orang yang boleh ditugaskan.
            'assignee_ids' => ['required', 'array', 'min:1', 'max:50'],
        ];

        // ATURAN PER-ELEMEN SENGAJA TIDAK DIPASANG kalau daftarnya sudah kelewat panjang,
        // dan ini bukan kerapian — `max:50` SENDIRIAN tidak menolong sama sekali. Laravel
        // tetap menjalankan aturan `assignee_ids.*` untuk SETIAP elemen walau aturan
        // arraynya sudah gagal, jadi kiriman 2.000 entri tetap menembakkan 2.000 query
        // `exists` dan membalas 155 KB berisi 2.000 pesan error. Diukur: 5,2 detik untuk
        // satu request kecil. Dengan penjaga ini: satu pesan, nol query.
        if (! is_array($dikirim) || count($dikirim) <= 50) {
            $rules['assignee_ids.*'] = [
                'integer',
                'distinct',
                // Objek Exists, BUKAN string 'exists:users,id'. Laravel hanya menerapkan
                // closure milik aturan ini kalau dia sampai ke validator sebagai OBJEK —
                // ValidatesAttributes::getExistCount() menyalin queryCallbacks() di balik
                // penjaga `instanceof Exists`. Diubah jadi string, allowlist-nya hilang
                // tanpa error dan siapa pun lolos jadi pelaksana.
                Rule::exists('users', 'id')->where(User::petugasMaintenance()),
            ];
        }

        // Koordinator regu — WAJIB untuk regu lebih dari satu orang (keputusan PO 5 Okt).
        // Regu satu orang tidak perlu menyebutnya: Service menjadikan orang itu
        // koordinator. Kalau disebut, harus salah satu pelaksana — koordinator yang tidak
        // ada di regunya tidak melihat WO-nya sama sekali.
        $rules['coordinator_id'] = [
            // <= 50: kiriman raksasa sudah ditolak `assignee_ids.max` dengan SATU pesan —
            // jangan ditambah pesan kedua dari sini.
            Rule::requiredIf($wajibKoordinator && is_array($dikirim) && count($dikirim) > 1 && count($dikirim) <= 50),
            'nullable', 'integer', Rule::in(is_array($dikirim) ? $dikirim : []),
        ];

        return $rules;
    }

    /** @return array<string, string> */
    public static function crewMessages(): array
    {
        return [
            'assignee_ids.required' => 'Work Order harus punya pelaksana. Tunjuk minimal satu petugas maintenance.',
            'assignee_ids.array' => 'Work Order harus punya pelaksana. Tunjuk minimal satu petugas maintenance.',
            'assignee_ids.min' => 'Work Order harus punya pelaksana. Tunjuk minimal satu petugas maintenance.',
            'assignee_ids.*.distinct' => 'Ada petugas yang ditunjuk dua kali.',
            'assignee_ids.*.exists' => 'Pelaksana harus petugas maintenance yang akunnya aktif.',
            'coordinator_id.required' => 'Pilih koordinator regu.',
            'coordinator_id.in' => 'Koordinator harus salah satu pelaksana yang ditunjuk.',
        ];
    }
}
