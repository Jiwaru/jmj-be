<?php

namespace App\Exceptions;

/**
 * Aturan bisnis dilanggar — dilempar dari Service, tidak pernah dari Controller.
 *
 * Service sengaja tidak tahu HTTP: yang menerjemahkan ini jadi response 422
 * ada satu tempat, di bootstrap/app.php. Jangan menangkapnya di controller
 * untuk membentuk response sendiri — itu mengembalikan logika HTTP ke Service
 * lewat pintu belakang.
 *
 * Meng-extend \DomainException bawaan PHP supaya masih tertangkap `catch
 * (\DomainException)`, tapi kelas app sendiri supaya exception serupa dari
 * vendor tidak ikut jadi 422.
 */
class DomainException extends \DomainException
{
    /** @param string|null $field kolom yang disalahkan, biar bentuknya sama dengan error validasi Laravel */
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
