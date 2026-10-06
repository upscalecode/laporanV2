<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/index.html'));
foreach (['index', 'login', 'spk', 'filling', 'press', 'apd', 'laporan', 'setting'] as $page) {
    Route::view('/'.$page.'.html', 'pages.'.$page)->name($page);
}
