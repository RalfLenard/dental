<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfRecord extends Model
{
    protected $fillable = [
        'record_no',
        'patient_name',
        'form_data',
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
        ];
    }
}
