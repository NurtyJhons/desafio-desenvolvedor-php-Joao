<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Upload;
use App\Models\Instrumento;
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

        // Verifica duplicidade por hash
        if (Upload::where('hash', $hashArquivo)->exists()) {
            return response()->json(['erro' => 'Arquivo já foi enviado anteriormente.'], 409);
        }

        // Salva o arquivo fisicamente
        $caminho = $arquivo->storeAs('private/uploads', $nomeOriginal, 'local');

        // Garante que o arquivo foi salvo de fato antes de continuar
        if (!Storage::disk('local')->exists($caminho)) {
            Log::error('Arquivo não encontrado após store: ' . $caminho);
            return response()->json(['erro' => 'Falha ao salvar o arquivo.'], 500);
        }

        // Cria o registro de upload no banco
        $upload = Upload::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
            'uploaded_at' => now(),
        ]);

        // Dispara o job em background
        ProcessarCsvJob::dispatch($caminho, $upload->id);

        Log::info('Upload recebido e job disparado: ' . $caminho);

        return response()->json([
            'mensagem' => 'Upload recebido. O processamento será feito em background.',
        ], 201);
    }

    public function historico(Request $request)
    {
        $query = Upload::query();

        if ($request->has('nome')) {
            $query->where('filename', 'like', '%' . $request->input('nome') . '%');
        }

        if ($request->has('data')) {
            $query->whereDate('uploaded_at', $request->input('data'));
        }

        $uploads = $query->orderBy('uploaded_at', 'desc')->get();

        return response()->json($uploads);
    }

    public function apagar($id)
    {
        $upload = Upload::find($id);

        if (!$upload) {
            return response()->json(['erro' => 'Upload não encontrado.'], 404);
        }

        // Apaga os instrumentos relacionados
        $upload->instrumentos()->delete();

        // Apaga o arquivo físico
        if ($upload->caminho && Storage::disk('local')->exists($upload->caminho)) {
            Storage::disk('local')->delete($upload->caminho);
        }

        // Apaga o registro do upload
        $upload->delete();

        return response()->json(['mensagem' => 'Upload e dados associados apagados com sucesso.']);
    }
}