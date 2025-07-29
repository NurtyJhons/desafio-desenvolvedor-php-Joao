<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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

        $caminho = $arquivo->storeAs('private/uploads', $nomeOriginal, 'local');

        if (!Storage::disk('local')->exists($caminho)) {
            Log::error('Falha ao salvar arquivo: ' . $caminho);
            return response()->json(['erro' => 'Falha ao salvar o arquivo.'], 500);
        }

        $uploadNoSql = UploadNoSql::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
            'uploaded_at' => now(),
        ]);

        ProcessarCsvJob::dispatch($caminho, $uploadNoSql->id);

        return response()->json([
            'mensagem' => 'Upload recebido. O processamento será feito em background.',
        ], 201);
    }

    public function historico(Request $request)
    {
        $nome = $request->input('nome', '');
        $data = $request->input('data', '');
        $cacheKey = "uploads_historico_" . md5($nome . $data);

        $uploads = Cache::remember($cacheKey, 60, function () use ($nome, $data) {
            $query = UploadNoSql::query();

            if (!empty($nome)) {
                $query->where('filename', 'like', "%{$nome}%");
            }

            if (!empty($data)) {
                $query->whereDate('uploaded_at', $data);
            }

            return $query->orderBy('uploaded_at', 'desc')->get();
        });

        return response()->json($uploads);
    }

    public function apagar($id)
    {
        $uploadNoSql = UploadNoSql::find($id);

        if (!$uploadNoSql) {
            return response()->json(['erro' => 'Upload não encontrado.'], 404);
        }

        // Apaga instrumentos relacionados
        InstrumentoNoSql::where('upload_id', $id)->delete();

        // Apaga o arquivo físico
        if ($uploadNoSql->caminho && Storage::disk('local')->exists($uploadNoSql->caminho)) {
            Storage::disk('local')->delete($uploadNoSql->caminho);
        }

        $uploadNoSql->delete();

        // Limpa cache
        Cache::forget("uploads_historico_" . md5(''));

        return response()->json(['mensagem' => 'Upload e dados associados apagados com sucesso.']);
    }
}