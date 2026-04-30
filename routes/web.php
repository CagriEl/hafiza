<?php

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\CoordinationAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Backward-compatible auth entrypoints for old bookmarks/links.
Route::redirect('/login', '/admin/login')->name('login');
Route::redirect('/dashboard', '/admin')->name('dashboard');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/aylik-faaliyet-pdf/{id}', function ($id) {
        $rapor = AylikFaaliyet::with('user')->findOrFail($id);

        $user = Auth::user();
        $allowed = $user instanceof User
            && (
                $user->canViewReportDataForOwnerId((int) $rapor->user_id)
                || in_array((int) $rapor->id, CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $user->id), true)
            );

        abort_unless($allowed, 403);

        $pdf = Pdf::loadView('pdf.aylik_faaliyet', compact('rapor'));

        return $pdf->download($rapor->user->name.'-'.$rapor->ay.'-faaliyet-raporu.pdf');
    })->name('aylik-faaliyet.pdf');
});
