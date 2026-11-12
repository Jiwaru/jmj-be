<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Satu baris di garis waktu Work Order, dikirim aplikasi Petugas Maintenance yang LURING.
 *
 * SATU FORM UNTUK ENAM AKSI, dibedakan `entry_type` — alasannya sama persis dengan
 * `POST /work-orders` yang dibedakan `trigger_source` (Alur 4.3): dua URL berarti dua
 * layar dan dua tempat yang harus diubah tiap kali aturan berubah. Yang mana boleh dari
 * status apa itu urusan Service (WorkOrder::LOG_TRANSITIONS), bukan urusan kelas ini.
 *
 * Kelas ini menjawab satu pertanyaan saja: kolom mana yang wajib, terlarang, dan boleh
 * berisi apa untuk tiap jenis baris.
 */
class SyncWorkOrderLogRequest extends FormRequest
{
    public function rules(): array
    {
        $jenis = $this->input('entry_type');

        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'entry_type' => ['required', Rule::in(config('enums.wo_log_entry_type'))],

            // logged_at DARI HP, bukan now() server. Di aplikasi luring dua waktu itu
            // bisa berjarak berjam-jam: bukti "before" diambil jam 09:15 di bahu jalan
            // dan baru sampai server jam 17:00 waktu petugas kembali ke kantor. Yang
            // punya arti buat siapa pun yang membacanya belakangan adalah yang pertama.
            //
            // `before_or_equal:now` menjaga jam HP yang ngawur — pekerjaan yang dicatat
            // "besok" merusak urutan Activity Log dan bikin day_number negatif. Kalau
            // di lapangan ternyata ada HP yang jamnya maju semenit-dua, longgarkan jadi
            // '+15 minutes' seperti SyncIncidentReportRequest. JANGAN dibuang.
            'logged_at' => ['required', 'date', 'before_or_equal:now'],

            // Tahap bukti cuma punya arti pada baris progres. Di baris lain dia
            // `prohibited`, bukan diabaikan: yang mengirimnya mengira kolom itu dipakai,
            // dan kalau diterima lalu dibuang dia tidak akan pernah tahu.
            'bda_stage' => $jenis === 'progress'
                ? ['required', Rule::in(config('enums.wo_bda_stage'))]
                : ['prohibited'],

            // Gerbang APD, dicentang per petugas. `size` = seluruh daftar: keputusan
            // Rafiq 3 Sep, tidak lengkap berarti tidak berangkat. Aturannya sengaja DI
            // SINI dan bukan di skema — kalau kantor melonggarkannya nanti, yang berubah
            // satu baris ini, dan baris log lama tetap menceritakan apa yang dicentang.
            'apd_items' => $jenis === 'apd_check'
                ? ['required', 'array', 'size:'.count(config('enums.wo_apd_items'))]
                : ['prohibited'],
            // `distinct` yang menolak ["Helm Safety","Helm Safety"], BUKAN database:
            // CHECK containment `<@` meloloskan duplikat karena tetap subset. Tanpa
            // baris ini, dua centang yang sama lolos `size:4` dan gerbangnya terbuka
            // dengan dua APD yang tidak pernah dipakai.
            'apd_items.*' => ['distinct', Rule::in(config('enums.wo_apd_items'))],

            // Satu kolom, dua daftar. Dipisah menurut entry_type persis seperti CHECK
            // bersyarat di migration — dua tempat, tapi dua-duanya membaca config yang
            // sama, jadi tidak ada daftar yang bisa melenceng sendiri.
            'reason' => match ($jenis) {
                'shift_handover' => ['required', Rule::in(config('enums.wo_handover_reason'))],
                'obstacle' => ['required', Rule::in(config('enums.wo_obstacle_reason'))],
                default => ['prohibited'],
            },

            // "Judul" di Catat Progress dan kartu Activity Log. Desain menandainya wajib,
            // tapi di sini NULLABLE: antrean luring dari aplikasi versi lama yang belum
            // mengirimnya akan 422 selamanya. FE yang mewajibkannya di form.
            'title' => ['nullable', 'string', 'max:120'],

            // Penerima oper shift. Harus anggota regu WO ini — orang di luar regu tidak
            // bisa melihat WO-nya (scopeVisibleTo), jadi oper ke dia sama dengan oper ke
            // kekosongan. Nullable: aplikasi lama belum mengirimnya.
            'handover_to_id' => $jenis === 'shift_handover'
                ? [
                    'nullable', 'integer',
                    Rule::notIn([$this->user()?->id]),
                    Rule::exists('operational_team', 'user_id')->where('work_order_id', (int) $this->input('work_order_id')),
                ]
                : ['prohibited'],

            // 'Lainnya' tidak punya kolom pendamping seperti causer_other — `notes` di
            // baris yang sama sudah teks bebas, dan dia jadi WAJIB begitu alasannya
            // "Lainnya". Alasan "Lainnya" tanpa penjelasan sama sekali tidak memberi
            // tahu siapa pun apa yang terjadi.
            'notes' => [
                $this->input('reason') === 'Lainnya' ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'apd_items.required' => 'Centang kelengkapan APD dulu sebelum berangkat.',
            'apd_items.size' => 'Seluruh APD harus dicentang. Tidak lengkap berarti tidak berangkat.',
            'apd_items.*.distinct' => 'Ada APD yang dicentang dua kali.',
            'bda_stage.required' => 'Catatan progres harus menyebut tahapnya: before, during, atau after.',
            'bda_stage.prohibited' => 'Tahap before/during/after cuma dipakai saat mencatat progres.',
            'reason.required' => 'Pilih alasannya dulu.',
            'reason.prohibited' => 'Alasan cuma dipakai saat oper shift atau lapor kendala.',
            'notes.required' => 'Alasan "Lainnya" harus dijelaskan di catatan.',
            'handover_to_id.exists' => 'Penerima oper shift harus anggota regu pekerjaan ini.',
            'handover_to_id.not_in' => 'Pekerjaan tidak bisa dioper ke diri sendiri.',
            'handover_to_id.prohibited' => 'Penerima cuma dipakai saat oper shift.',
            'logged_at.before_or_equal' => 'Waktu pencatatan tidak boleh di masa depan. Periksa jam di perangkat Anda.',
        ];
    }
}
