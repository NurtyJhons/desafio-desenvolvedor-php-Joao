<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Upload;
use App\Models\Instrumento;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        // Validação do arquivo
        $request->validate([
            'arquivo' => 'required|file|mimes:csv,txt',
        ]);

        $arquivo = $request->file('arquivo');
        $nomeOriginal = $arquivo->getClientOriginalName();
        $hashArquivo = hash_file('sha256', $arquivo->getRealPath());

        // Verifica duplicidade
        if (Upload::where('hash', $hashArquivo)->exists()) {
            return response()->json(['erro' => 'Arquivo já foi enviado anteriormente.'], 409);
        }

        // Salva o arquivo localmente
        $caminho = $arquivo->storeAs('uploads', $nomeOriginal, 'local');

        // Cria o registro de upload
        $upload = Upload::create([
            'filename' => $nomeOriginal,
            'hash' => $hashArquivo,
            'caminho' => $caminho,
            'uploaded_at' => now(),
        ]);

        // DEBUG: Log do início do processamento
        Log::info("Iniciando processamento do arquivo: " . $nomeOriginal);

        // Processa o CSV
        $totalProcessados = $this->processarCSV($arquivo->getRealPath(), $upload->id);

        Log::info("Total de registros processados: " . $totalProcessados);

        return response()->json([
            'mensagem' => 'Upload concluído com sucesso.',
            'total_registros' => $totalProcessados
        ], 201);
    }

    private function processarCSV($caminhoArquivo, $uploadId)
    {
        $handle = fopen($caminhoArquivo, 'r');
        if (!$handle) {
            throw new \Exception('Não foi possível abrir o arquivo');
        }

        $header = null;
        $batch = [];
        $batchSize = 500;
        $totalProcessados = 0;
        $linhaAtual = 0;

        while (($linha = fgetcsv($handle, 10000, ';')) !== false) {
            $linhaAtual++;
            
            // DEBUG: Log das primeiras linhas
            if ($linhaAtual <= 5) {
                Log::info("Linha {$linhaAtual}: " . json_encode($linha));
            }

            // Pula linha de status (primeira linha) ou linhas vazias
            if ($linhaAtual == 1 || empty($linha[0]) || trim($linha[0]) == '') {
                continue;
            }

            // Segunda linha deve ser o cabeçalho
            if ($linhaAtual == 2) {
                // Se a linha vem como uma string única (por causa do ;), explode manualmente
                if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
                    $header = array_map('trim', explode(';', $linha[0]));
                } else {
                    $header = array_map('trim', $linha);
                }
                Log::info("Cabeçalho encontrado: " . json_encode($header));
                continue;
            }

            // Se não temos header ainda, algo está errado
            if (!$header) {
                continue;
            }

            // Verifica se a linha tem dados válidos
            if (empty(trim($linha[0]))) {
                continue;
            }

            // Se a linha vem como uma string única, explode manualmente
            if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
                $linha = explode(';', $linha[0]);
            }

            // Verifica se tem o número correto de colunas
            if (count($linha) != count($header)) {
                Log::warning("Linha {$linhaAtual} tem " . count($linha) . " colunas, esperado " . count($header));
                continue;
            }

            // Combina header com dados
            $registro = array_combine($header, array_map('trim', $linha));
            
            // DEBUG: Log do primeiro registro
            if ($linhaAtual == 3) {
                Log::info("Primeiro registro: " . json_encode($registro));
            }

            // Converte data do formato brasileiro para ISO
            $dataISO = null;
            if (isset($registro['RptDt']) && !empty($registro['RptDt'])) {
                try {
                    // Primeiro tenta formato yyyy-mm-dd (já ISO)
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $registro['RptDt'])) {
                        $dataISO = $registro['RptDt'];
                    } else {
                        // Tenta formato dd/mm/yyyy
                        $dataISO = \DateTime::createFromFormat('d/m/Y', $registro['RptDt']);
                        if ($dataISO) {
                            $dataISO = $dataISO->format('Y-m-d');
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Erro ao converter data: " . $registro['RptDt']);
                }
            }

            $batch[] = [
                'upload_id' => $uploadId,
                'RptDt' => $dataISO,
                'TckrSymb' => $registro['TckrSymb'] ?? null,
                'MktNm' => $registro['MktNm'] ?? null,
                'SctyCtgyNm' => $registro['SctyCtgyNm'] ?? null,
                'ISIN' => $registro['ISIN'] ?? null,
                'CrpnNm' => $registro['CrpnNm'] ?? null,
                'dados_json' => json_encode($registro),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $batchSize) {
                try {
                    Instrumento::insert($batch);
                    $totalProcessados += count($batch);
                    Log::info("Lote inserido: " . count($batch) . " registros");
                } catch (\Exception $e) {
                    Log::error("Erro ao inserir lote: " . $e->getMessage());
                }
                $batch = [];
            }
        }

        // Insere o último lote
        if (!empty($batch)) {
            try {
                Instrumento::insert($batch);
                $totalProcessados += count($batch);
                Log::info("Último lote inserido: " . count($batch) . " registros");
            } catch (\Exception $e) {
                Log::error("Erro ao inserir último lote: " . $e->getMessage());
            }
        }

        fclose($handle);
        return $totalProcessados;
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