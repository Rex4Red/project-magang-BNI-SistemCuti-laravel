<?php

use Illuminate\Support\Facades\Route;

Route::get('/{path?}', function () {
    $index = public_path('build/index.html');
    abort_unless(is_file($index), 503, 'Jalankan npm run build untuk menyiapkan tampilan.');

    return response()->file($index);
})->where('path', '.*');
