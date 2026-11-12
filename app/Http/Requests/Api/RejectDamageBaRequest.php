<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan BA Kerusakan — cuma alasannya.
 *
 * Bandingkan dengan penolakan BA Kejadian yang ikut membawa penanda tangan: BA
 * Kerusakan tidak punya isian kepala sama sekali. supervisor_id diambil dari token,
 * shift disalin dari BA Kejadian, dan nilainya dihitung server. Modal pengesahannya
 * di layar memang cuma tombol, tanpa form.
 */
class RejectDamageBaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
