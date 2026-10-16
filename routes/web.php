<?php

use App\Http\Controllers\PdfRecordController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('pdf-records.index'));

Route::get('/pdf-records', [PdfRecordController::class, 'index'])->name('pdf-records.index');
Route::post('/pdf-records', [PdfRecordController::class, 'store'])->name('pdf-records.store');
Route::get('/pdf-records/{record}/edit', [PdfRecordController::class, 'edit'])->name('pdf-records.edit');
Route::put('/pdf-records/{record}', [PdfRecordController::class, 'update'])->name('pdf-records.update');
Route::get('/pdf-records/{record}/preview', [PdfRecordController::class, 'preview'])->name('pdf-records.preview');