<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Upload;
use App\Models\Instrumento;
use App\Models\UploadNoSql;
use App\Models\InstrumentoNoSql; 
use App\Jobs\ProcessarCsvJob;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:csv,txt',
        ]);

        $arquivo = $request->file('arquivo');

        if (!$arquivo->isValid()) {
            return response()->json(['erro' => 'Arquivo inválido.'], 400);
        }

        $nomeOriginal = $arquivo->getClientOriginalName();
        $hashArquivo = hash_file('sha256', $arquivo->getRealPath());

        if (UploadNoSql::where('hash', $hashArquivo)->exists()) {
            return response()->json(['erro' => 'Arquivo já foi enviado anteriormente.'], 409);
        }

        // Salva o arquivo fisicamente
        $caminho = $arquivo->storeAs('private/uploads', $nomeOriginal, 'local');

        if (!Storage::disk('local')->exists($caminho)) {
            Log::error('Arquivo não encontrado após store: ' . $caminho);
            return response()->json(['erro' => 'Falha ao salvar o arquivo.'], 500);
        }

        $uploadNoSql = UploadNoSql::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
            'uploaded_at' => now(),
        ]);

        Upload::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
            'uploaded_at' => now(),
            'mongodb_id' => $uploadNoSql->id, // Link para o MongoDB
        ]);

        // Dispara o job com o ID do MongoDB
        ProcessarCsvJob::dispatch($caminho, $uploadNoSql->id);

        Log::info('Upload recebido e job disparado: ' . $caminho);

        return response()->json([
            'mensagem' => 'Upload recebido. O processamento será feito em background.',
        ], 201);
    }

    public function historico(Request $request)
    {
        $nome = $request->input('nome', '');
        $data = $request->input('data', '');

        $cacheKey = "uploads_historico_" . md5($nome . $data);

        Log::info("🔍 Buscando histórico com cache key: {$cacheKey}");

        $uploads = Cache::remember($cacheKey, 60, function () use ($nome, $data) {
            Log::info('🔄 Cache MISS: consultando o MongoDB [historico_uploads]');

            $query = UploadNoSql::query();

            if (!empty($nome)) {
                $query->where('filename', 'like', "%{$nome}%");
            }

            if (!empty($data)) {
                $query->whereDate('uploaded_at', $data);
            }

            $resultado = $query->orderBy('uploaded_at', 'desc')->get();
            
            Log::info("📊 Histórico consultado - Total encontrado: {$resultado->count()}");
            
            return $resultado;
        });

        if (Cache::has($cacheKey)) {
            Log::info('✅ Cache HIT: histórico vindo do Redis');
        }

        return response()->json($uploads);
    }

    public function apagar($id)
    {
        $uploadNoSql = UploadNoSql::find($id);

        if (!$uploadNoSql) {
            return response()->json(['erro' => 'Upload não encontrado.'], 404);
        }

        // Apaga do MongoDB
        InstrumentoNoSql::where('upload_id', $id)->delete();

        // Apaga o arquivo físico
        if ($uploadNoSql->caminho && Storage::disk('local')->exists($uploadNoSql->caminho)) {
            Storage::disk('local')->delete($uploadNoSql->caminho);
        }

        // Apaga o registro do MongoDB
        $uploadNoSql->delete();

        $uploadMySQL = Upload::where('hash', $uploadNoSql->hash)->first();
        if ($uploadMySQL) {
            $uploadMySQL->instrumentos()->delete();
            $uploadMySQL->delete();
        }

        // Limpa cache relacionado
        Cache::forget("uploads_historico_" . md5(''));

        return response()->json(['mensagem' => 'Upload e dados associados apagados com sucesso.']);
    }
}