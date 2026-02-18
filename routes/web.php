<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/telescope-login', function () {
    return view('telescope-login');
});

Route::post('/telescope-login', function (Request $request) {
    if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
        return redirect('/telescope');
    }
    return back()->withErrors(['email' => 'Invalid credentials']);
});
