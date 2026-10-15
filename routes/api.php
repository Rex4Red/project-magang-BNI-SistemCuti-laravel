<?php

use App\Domain\CutiError;
use App\Http\Controllers\CutiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::controller(CutiController::class)->group(function () {
    Route::get('health', 'health');
    Route::get('config', 'configuration');
    Route::post('login', 'login');
    Route::get('verify-certificate', 'verify');
    Route::get('me', 'me');
    Route::post('me/password', 'password');
    Route::post('logout', 'logout');
    Route::get('dashboard', 'dashboard');
    Route::post('leave-requests/preview', 'preview');
    Route::post('leave-requests', 'submit');
    Route::get('leave-requests/{id}', 'detail');
    Route::get('leave-requests/{id}/replacements', 'replacements');
    Route::post('leave-requests/{id}/{action}', 'action');
    Route::post('drafts', 'draft');
    Route::delete('drafts/{id}', 'deleteDraft');
    Route::get('reports.{format}', 'report')->whereIn('format', ['xlsx', 'xls', 'csv']);
    Route::get('admin', 'admin');
});

foreach (['employee', 'outlets', 'positions', 'replacement-rules', 'calendar', 'quota'] as $action) {
    Route::post('admin/'.$action, fn (Request $request, CutiController $controller) => $controller->adminAction($request, $action));
}
Route::post('admin/calendar/sync-2027', fn (Request $request, CutiController $controller) => $controller->adminAction($request, 'sync-2027'));
Route::post('admin/jobs/{id}/retry', fn (Request $request, CutiController $controller, string $id) => $controller->adminAction($request, 'retry', $id));
foreach (['employee' => 'deleteEmployee', 'outlets' => 'deleteOutlet', 'positions' => 'deletePosition'] as $path => $action) {
    Route::delete('admin/'.$path.'/{id}', fn (Request $request, CutiController $controller, string $id) => $controller->adminAction($request, $action, $id));
}
Route::any('{path}', fn () => throw new CutiError('Endpoint tidak ditemukan.', 404))->where('path', '.*');
