<?php

namespace App\Http\Requests\Api;

/**
 * Penolakan BA Kejadian.
 *
 * Membawa isian yang SAMA dengan pengesahan (`duty_period`), plus alasannya — bukan
 * kelebihan: baris yang ditulis adalah baris `incident_ba` yang sama, dan
 * officer_1_id/officer_2_id di situ NOT NULL — diisi dari `incident_report` oleh
 * service, sama seperti pengesahan. Di layar, form BA-nya memang satu dan
 * tombolnya dua.
 */
class RejectIncidentBaRequest extends ValidateIncidentBaRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
