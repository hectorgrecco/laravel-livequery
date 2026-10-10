<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/demo/login/{email}', function (string $email) {
    $user = User::query()->where('email', $email)->firstOrFail();
    Auth::login($user);

    return redirect('/orders');
});

Route::post('/demo/logout', function () {
    Auth::logout();

    return redirect('/');
});

Route::get('/', fn () => view('welcome'));
Volt::route('/orders', 'orders')->middleware('auth');
Volt::route('/demo/away', 'away')->middleware('auth');

Route::get('/demo/queue-empty', function () {
    return response()->json(['empty' => DB::table('jobs')->count() === 0]);
})->middleware('auth');
