<?php

use App\Http\Controllers\Auth\KohaSsoController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PublicViewerController;
use App\Http\Controllers\UploadController;
use App\Livewire\Admin;
use App\Livewire\ApprovalQueue;
use App\Livewire\AssetDetail;
use App\Livewire\AssetLibrary;
use App\Livewire\BiblioWorkspace;
use App\Livewire\Dashboard;
use App\Livewire\Publications;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| No route serves an original file and there is no download endpoint.
*/

Route::view('/', 'public.home')->name('home');

Route::get('/view/{uuid}', [PublicViewerController::class, 'show'])
    ->middleware('throttle:viewer')
    ->name('viewer.show');

// Engaged-view report from the viewer page (signed single-use token, so no CSRF token).
Route::post('/view/{uuid}/engagement', [PublicViewerController::class, 'engage'])
    ->middleware('throttle:views')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('viewer.engagement');

// Written to the Koha item's URI field (952$u) for item-level media.
Route::get('/item/{item}', [PublicViewerController::class, 'item'])
    ->whereNumber('item')
    ->middleware('throttle:viewer')
    ->name('viewer.item');

Route::middleware(['signed', 'throttle:media'])->group(function () {
    Route::get('/media/{asset:uuid}/{file}', [MediaController::class, 'file'])
        ->whereNumber('file')
        ->name('media.file');

    Route::get('/media/{asset:uuid}/{file}/hls/{path}', [MediaController::class, 'hls'])
        ->whereNumber('file')
        ->where('path', '[A-Za-z0-9_/\.]+')
        ->name('media.hls');
});

Route::get('/covers/{biblio}', CoverController::class)->whereNumber('biblio')->middleware('throttle:viewer')->name('covers.show');

Route::get('/health.json', HealthController::class)->name('health.json');

/*
|--------------------------------------------------------------------------
| Staff authentication (Koha SSO only — there is no password login)
|--------------------------------------------------------------------------
*/

Route::get('/sso/koha/start', [KohaSsoController::class, 'start'])->middleware('throttle:sso')->name('sso.koha.start');
Route::get('/sso/koha', KohaSsoController::class)->middleware('throttle:sso')->name('sso.koha');
Route::get('/.well-known/koha-media-manager', [KohaSsoController::class, 'wellKnown'])->name('sso.well-known');
Route::view('/login', 'auth.login')->name('login');
Route::post('/logout', [KohaSsoController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Staff application
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'staff'])->prefix('staff')->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');
    Route::livewire('/library', AssetLibrary::class)->name('library');
    Route::livewire('/biblios/{biblio}', BiblioWorkspace::class)->whereNumber('biblio')->name('biblios.show');
    Route::livewire('/assets/{asset:uuid}', AssetDetail::class)->name('assets.show');
    Route::livewire('/approvals', ApprovalQueue::class)->middleware('can:media.review')->name('approvals');
    Route::livewire('/publications', Publications::class)->name('publications');

    Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:uploads')->name('uploads.store');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/integration', Admin\KohaIntegration::class)->middleware('can:media.manage_settings')->name('integration');
        Route::livewire('/storage', Admin\StorageBackends::class)->middleware('can:media.manage_storage')->name('storage');
        Route::livewire('/policies', Admin\PublishingPolicies::class)->middleware('can:media.manage_settings')->name('policies');
        Route::livewire('/settings', Admin\SettingsPage::class)->middleware('can:media.manage_settings')->name('settings');
        Route::livewire('/staff', Admin\StaffRoles::class)->middleware('can:media.manage_settings')->name('staff');
        Route::livewire('/processing', Admin\Processing::class)->middleware('can:media.manage_settings')->name('processing');
        Route::livewire('/audit', Admin\AuditLog::class)->middleware('can:media.view_audit')->name('audit');
    });
});
