<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MentalPdfMhg2 extends Model
{
    protected $fillable = ['mental_pdf_record_id', 'form_data'];

    protected $casts = ['form_data' => 'array'];

    public function record(): BelongsTo
    {
        return $this->belongsTo(MentalPdfRecord::class, 'mental_pdf_record_id');
    }
}