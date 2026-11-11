<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MentalPdfRecord extends Model
{
    protected $fillable = [
        'record_no',
        'patient_name',
        'form_data',
    ];

    protected $casts = [
        'form_data' => 'array',
    ];
}