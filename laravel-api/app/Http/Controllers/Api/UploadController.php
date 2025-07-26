<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Upload;
use App\Models\Instrumento;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        // Validação do arquivo
        $request->validate([
            'arquivo' => 'required|file|mimes:csv,txt,xlsx,xls',
        ]);

        $arquivo = $request->file('arquivo');
        $nomeOriginal = $arquivo->getClientOriginalName();
        $conteudoArquivo = file_get_contents($arquivo->getPathname());
        $hashArquivo = hash('sha256', $conteudoArquivo);

        // Verifica duplicidade
        if (Upload::where('hash', $hashArquivo)->exists()) {
            return response()->json(['erro' => 'Arquivo já foi enviado anteriormente.'], 409);
        }

        // Salva o arquivo (opcional: para backup)
        $caminho = $arquivo->storeAs('uploads', $nomeOriginal, 'local');

        // Cria o registro de upload
        $upload = Upload::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
        ]);

        // Lê o CSV e salva na tabela instrumentos
        $dados = array_map('str_getcsv', file($arquivo));

        $cabecalho = array_map('trim', $dados[0]);
        unset($dados[0]); // pra remover cabeçalho

        $linhasParaInserir = [];

        foreach ($dados as $dadosLinha) {
            if (count($dadosLinha) !== count($cabecalho)) {
                continue;
            }

            $registro = array_combine($cabecalho, $dadosLinha);

            // Cria o registro na tabela instrumentos
            Instrumento::create([
                'upload_id' => $upload->id,
                'TckrSymb' => $registro['TckrSymb'] ?? null,
                'RptDt' => isset($registro['RptDt']) ? date('Y-m-d', strtotime($registro['RptDt'])) : null, // formata a data
                'MktNm' => $registro['MktNm'] ?? null,
                'SctyCtgyNm' => $registro['SctyCtgyNm'] ?? null,
                'ISIN' => $registro['ISIN'] ?? null,
                'CrpnNm' => $registro['CrpnNm'] ?? null,
                'dados_json' => json_encode($registro),  // armazena tudo
            ]);
        }

        Instrumento::insert($linhasParaInserir);

        return response()->json(['mensagem' => 'Upload concluído com sucesso.'], 201);
    }

    public function historico(Request $request)
    {
        $query = Upload::query();

        if ($request->has('nome')) {
            $query->where('nome', 'like', '%' . $request->input('nome') . '%');
        }

        if ($request->has('data')) {
            $query->whereDate('created_at', $request->input('data'));
        }

        $uploads = $query->orderBy('created_at', 'desc')->get();

        return response()->json($uploads);
    }

}