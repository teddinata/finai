<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/telescope-login', function () {
    return response()->make('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Telescope Login</title>
        <style>
            body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f3f4f6; }
            .box { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.1); width: 300px; }
            input { width: 100%; padding: 8px; margin: 8px 0 16px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { width: 100%; padding: 10px; background: #28aee9; color: white; border: none; border-radius: 4px; cursor: pointer; }
            .error { color: red; font-size: 14px; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2 style="margin-top:0">Telescope Login</h2>
            ' . (session("error") ? '<p class="error">'.session("error").'</p>' : '') . '
            <form method="POST" action="/telescope-login">
                ' . csrf_field() . '
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Password</label>
                <input type="password" name="password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    ');
});

Route::post('/telescope-login', function (Request $request) {
    if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
        return redirect('/telescope');
    }
    return redirect('/telescope-login')->with('error', 'Email atau password salah');
})->middleware('web');
