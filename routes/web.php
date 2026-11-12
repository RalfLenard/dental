<?php

use App\Http\Controllers\PdfRecordController;
use App\Http\Controllers\MentalPdfRecordController;
use App\Http\Controllers\MentalPdfMhg2Controller;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('pdf-records.index'));

Route::get('/pdf-records', [PdfRecordController::class, 'index'])->name('pdf-records.index');
Route::post('/pdf-records', [PdfRecordController::class, 'store'])->name('pdf-records.store');
Route::get('/pdf-records/{record}/edit', [PdfRecordController::class, 'edit'])->name('pdf-records.edit');
Route::put('/pdf-records/{record}', [PdfRecordController::class, 'update'])->name('pdf-records.update');
Route::get('/pdf-records/{record}/preview', [PdfRecordController::class, 'preview'])->name('pdf-records.preview');


// Paste into routes/web.php (inside the same middleware group as your dental pdf-records routes)

Route::get('/mental-pdf-records', [MentalPdfRecordController::class, 'index'])->name('mental-pdf-records.index');
Route::post('/mental-pdf-records', [MentalPdfRecordController::class, 'store'])->name('mental-pdf-records.store');
Route::get('/mental-pdf-records/{record}/edit', [MentalPdfRecordController::class, 'edit'])->name('mental-pdf-records.edit');
Route::put('/mental-pdf-records/{record}', [MentalPdfRecordController::class, 'update'])->name('mental-pdf-records.update');
Route::get('/mental-pdf-records/{record}/preview', [MentalPdfRecordController::class, 'preview'])->name('mental-pdf-records.preview');


Route::post('mental-pdf-records/{record}/mhg2', [MentalPdfMhg2Controller::class, 'store'])->name('mental-pdf-records.mhg2.store');
Route::put('mental-pdf-records/{record}/mhg2', [MentalPdfMhg2Controller::class, 'update'])->name('mental-pdf-records.mhg2.update');
Route::get('mental-pdf-records/{record}/mhg2/preview', [MentalPdfMhg2Controller::class, 'preview'])->name('mental-pdf-records.mhg2.preview');