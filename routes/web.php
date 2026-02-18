<?php

use App\Http\Controllers\PdfController; // Birazdan oluşturacağız
use App\Models\AylikFaaliyet;
use Barryvdh\DomPDF\Facade\Pdf;

// Basit bir rota tanımlıyoruz (Controller oluşturmadan direkt burada yapalım, pratik olsun)
Route::get('/aylik-faaliyet-pdf/{id}', function ($id) {
    $rapor = AylikFaaliyet::with('user')->findOrFail($id);
    
    // PDF ayarları
    $pdf = Pdf::loadView('pdf.aylik_faaliyet', compact('rapor'));
    return $pdf->download($rapor->user->name . '-' . $rapor->ay . '-faaliyet-raporu.pdf');
})->name('aylik-faaliyet.pdf');