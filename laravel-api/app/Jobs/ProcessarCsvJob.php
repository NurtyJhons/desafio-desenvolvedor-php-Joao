<?php

namespace App\Jobs;

use App\Models\Instrumento;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage; 

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
        Log::info("Job iniciado para Upload ID: {$this->uploadId}");

        $handle = Storage::disk('local')->readStream($this->caminhoArquivo);
        if (!$handle) {
            Log::error("Não foi possível abrir o arquivo");
            return;
        }

        $header = null;
        $batch = [];
        $batchSize = 500;
        $linhaAtual = 0;

        while (($linha = fgetcsv($handle, 10000, ';')) !== false) {
            $linhaAtual++;

            if ($linhaAtual == 1 || empty($linha[0]) || trim($linha[0]) == '') {
                continue;
            }

            if ($linhaAtual == 2) {
                if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
                    $header = array_map('trim', explode(';', $linha[0]));
                } else {
                    $header = array_map('trim', $linha);
                }
                continue;
            }

            if (!$header || empty(trim($linha[0]))) {
                continue;
            }

            if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
                $linha = explode(';', $linha[0]);
            }

            if (count($linha) != count($header)) {
                Log::warning("Linha {$linhaAtual} inválida");
                continue;
            }

            $registro = array_combine($header, array_map('trim', $linha));

            $dataISO = null;
            if (!empty($registro['RptDt'])) {
                try {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $registro['RptDt'])) {
                        $dataISO = $registro['RptDt'];
                    } else {
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
                'upload_id' => $this->uploadId,
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
                    Log::info("Lote inserido: " . count($batch));
                } catch (\Exception $e) {
                    Log::error("Erro ao inserir lote: " . $e->getMessage());
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            try {
                Instrumento::insert($batch);
                Log::info("Último lote inserido: " . count($batch));
            } catch (\Exception $e) {
                Log::error("Erro ao inserir último lote: " . $e->getMessage());
            }
        }

        fclose($handle);
        Log::info("Job finalizado para Upload ID: {$this->uploadId}");
    }
}
