<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/demo/login/{user}', function (User $user) {
    Auth::login($user);

    return redirect('/orders');
});

Route::post('/demo/logout', function () {
    Auth::logout();

    return redirect('/');
});

Route::get('/', fn () => view('welcome'));
Volt::route('/orders', 'orders')->middleware('auth');

Route::get('/demo/queue-empty', function () {
    return response()->json(['empty' => DB::table('jobs')->count() === 0]);
})->middleware('auth');

Route::get('/demo/away', fn () => 'Away');
