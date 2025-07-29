<?php

namespace App\Jobs;

use App\Models\InstrumentoNoSql;
use App\Models\UploadNoSql;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProcessarCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $caminhoArquivo;
    protected $uploadId;

    public function __construct($caminhoArquivo, $uploadId)
    {
        $this->caminhoArquivo = $caminhoArquivo;
        $this->uploadId = $uploadId;
    }

    public function handle()
    {
        $upload = UploadNoSql::find($this->uploadId);
        if (!$upload) {
            Log::error("Upload não encontrado: {$this->uploadId}");
            return;
        }

        $handle = Storage::disk('local')->readStream($this->caminhoArquivo);
        if (!$handle) {
            Log::error("Erro ao abrir arquivo: {$this->caminhoArquivo}");
            return;
        }

        $header = null;
        $batch = [];
        $linhaAtual = 0;
        $totalInseridos = 0;

        while (($linha = fgetcsv($handle, 10000, ';')) !== false) {
            $linhaAtual++;

            // Pula linha vazia ou primeira linha
            if ($linhaAtual == 1 || empty(trim($linha[0] ?? ''))) continue;

            // Processa header na linha 2
            if ($linhaAtual == 2) {
                $header = $this->processarHeader($linha);
                continue;
            }

            if (!$header) continue;

            // Processa linha de dados
            $registro = $this->processarLinha($linha, $header, $linhaAtual);
            if (!$registro) continue;

            $batch[] = $this->criarRegistroMongoDB($registro);

            // Processa lote quando atingir 500 registros
            if (count($batch) >= 500) {
                $totalInseridos += $this->inserirLote($batch);
                $batch = [];
            }
        }

        // Processa último lote
        if (!empty($batch)) {
            $totalInseridos += $this->inserirLote($batch);
        }

        fclose($handle);
        
        // Log final apenas com informações essenciais
        if ($totalInseridos === 0) {
            Log::error("Nenhum registro inserido para upload {$this->uploadId}");
        }

        $this->limparCache();
    }

    private function processarHeader($linha)
    {
        if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
            $header = explode(';', $linha[0]);
        } else {
            $header = $linha;
        }
        
        return array_map([$this, 'corrigirUtf8'], array_map('trim', $header));
    }

    private function processarLinha($linha, $header, $numeroLinha)
    {
        if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
            $linha = explode(';', $linha[0]);
        }

        if (count($linha) != count($header)) return null;

        $linha = array_map([$this, 'corrigirUtf8'], array_map('trim', $linha));
        return array_combine($header, $linha);
    }

    private function criarRegistroMongoDB($registro)
    {
        return [
            'upload_id' => $this->uploadId,
            'RptDt' => $this->formatarData($registro['RptDt'] ?? null),
            'TckrSymb' => $registro['TckrSymb'] ?? null,
            'MktNm' => $registro['MktNm'] ?? null,
            'SctyCtgyNm' => $registro['SctyCtgyNm'] ?? null,
            'ISIN' => $registro['ISIN'] ?? null,
            'CrpnNm' => $registro['CrpnNm'] ?? null,
            'dados_json' => $registro,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function formatarData($data)
    {
        if (empty($data)) return null;

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                return Carbon::createFromFormat('Y-m-d', $data)->toDateString();
            } else {
                return Carbon::createFromFormat('d/m/Y', $data)->toDateString();
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    private function inserirLote($batch)
    {
        try {
            InstrumentoNoSql::raw(function($collection) use ($batch) {
                return $collection->insertMany($batch);
            });
            return count($batch);
        } catch (\Exception $e) {
            // Fallback: inserir um por um
            $inseridos = 0;
            foreach ($batch as $item) {
                try {
                    InstrumentoNoSql::create($item);
                    $inseridos++;
                } catch (\Exception $itemError) {
                    // Última tentativa com limpeza de caracteres
                    try {
                        $item = $this->limparCaracteres($item);
                        InstrumentoNoSql::create($item);
                        $inseridos++;
                    } catch (\Exception $finalError) {
                        // Silencioso - apenas não insere este registro
                    }
                }
            }
            return $inseridos;
        }
    }

    private function corrigirUtf8($texto)
    {
        if (empty($texto)) return $texto;

        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        $substituicoes = [
            '▒' => 'Ç', '▓' => 'Ã', '┤' => 'Á', '▐' => 'É',
            '▌' => 'Ê', '▄' => 'Í', '█' => 'Ó', '▀' => 'Ú'
        ];

        return mb_convert_encoding(
            str_replace(array_keys($substituicoes), array_values($substituicoes), $texto),
            'UTF-8', 'UTF-8'
        );
    }

    private function limparCaracteres($array)
    {
        if (!is_array($array)) {
            return preg_replace('/[^\x20-\x7E\x{00A0}-\x{00FF}\x{0100}-\x{017F}\x{0180}-\x{024F}]/u', '', $array);
        }

        $resultado = [];
        foreach ($array as $chave => $valor) {
            $chaveCorrigida = preg_replace('/[^\x20-\x7E\x{00A0}-\x{00FF}\x{0100}-\x{017F}\x{0180}-\x{024F}]/u', '', $chave);
            $resultado[$chaveCorrigida] = is_array($valor) 
                ? $this->limparCaracteres($valor)
                : preg_replace('/[^\x20-\x7E\x{00A0}-\x{00FF}\x{0100}-\x{017F}\x{0180}-\x{024F}]/u', '', $valor);
        }

        return $resultado;
    }

    private function limparCache()
    {
        try {
            $redis = Cache::getRedis();
            $prefix = config('database.redis.options.prefix', '');
            $keys = $redis->keys($prefix . "*instrumentos_buscar_mongodb_*");
            
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            
        }
    }
}
