<?php

use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\InstrumentoController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/upload', [UploadController::class, 'upload']);
    Route::get('/uploads', [UploadController::class, 'historico']);
    Route::delete('/upload/{id}', [UploadController::class, 'apagar']);
    Route::get('/instrumentos_buscar', [InstrumentoController::class, 'buscar']);
});

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// Registro
Route::post('/register', function (Request $request) {
    $request->validate([
        'name' => 'required',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:6',
    ]);

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => bcrypt($request->password),
    ]);

    return response()->json($user, 201);
});

// Login
Route::post('/login', function (Request $request) {
    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['As credenciais estão incorretas.'],
        ]);
    }

    return response()->json([
        'token' => $user->createToken('token_api')->plainTextToken,
    ]);
});
