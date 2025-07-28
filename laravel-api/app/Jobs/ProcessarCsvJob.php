<?php

namespace App\Jobs;

use App\Models\Instrumento;
use App\Models\InstrumentoNoSql;
use App\Models\UploadNoSql;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
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
        Log::info("Job iniciado para Upload ID: {$this->uploadId}");

        // Verifica se o upload existe no MongoDB
        $upload = UploadNoSql::find($this->uploadId);
        if (!$upload) {
            Log::error("Upload não encontrado no MongoDB: {$this->uploadId}");
            return;
        }

        $handle = Storage::disk('local')->readStream($this->caminhoArquivo);
        if (!$handle) {
            Log::error("Não foi possível abrir o arquivo: {$this->caminhoArquivo}");
            return;
        }

        $header = null;
        $batch = [];
        $batchMongoDB = [];
        $batchSize = 500;
        $linhaAtual = 0;
        $totalInseridos = 0;

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
                Log::info("Header encontrado: " . implode(', ', $header));
                continue;
            }

            if (!$header || empty(trim($linha[0]))) {
                continue;
            }

            if (count($linha) == 1 && strpos($linha[0], ';') !== false) {
                $linha = explode(';', $linha[0]);
            }

            if (count($linha) != count($header)) {
                Log::warning("Linha {$linhaAtual} inválida - Header: " . count($header) . " colunas, Linha: " . count($linha) . " colunas");
                continue;
            }

            $registro = array_combine($header, array_map('trim', $linha));

            // Conversão de data
            $dataFormatada = null;
            if (!empty($registro['RptDt'])) {
                try {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $registro['RptDt'])) {
                        $dataFormatada = Carbon::createFromFormat('Y-m-d', $registro['RptDt']);
                    } else {
                        $dataFormatada = Carbon::createFromFormat('d/m/Y', $registro['RptDt']);
                    }
                } catch (\Exception $e) {
                    Log::warning("Erro ao converter data na linha {$linhaAtual}: " . $registro['RptDt'] . " - " . $e->getMessage());
                    $dataFormatada = null;
                }
            }

            // Para MySQL
            $batch[] = [
                'upload_id' => $this->uploadId,
                'RptDt' => $dataFormatada ? $dataFormatada->format('Y-m-d') : null,
                'TckrSymb' => $registro['TckrSymb'] ?? null,
                'MktNm' => $registro['MktNm'] ?? null,
                'SctyCtgyNm' => $registro['SctyCtgyNm'] ?? null,
                'ISIN' => $registro['ISIN'] ?? null,
                'CrpnNm' => $registro['CrpnNm'] ?? null,
                'dados_json' => json_encode($registro),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Para MongoDB
            $batchMongoDB[] = [
                'upload_id' => $this->uploadId,
                'RptDt' => $dataFormatada ? $dataFormatada->toDateString() : null,
                'TckrSymb' => $registro['TckrSymb'] ?? null,
                'MktNm' => $registro['MktNm'] ?? null,
                'SctyCtgyNm' => $registro['SctyCtgyNm'] ?? null,
                'ISIN' => $registro['ISIN'] ?? null,
                'CrpnNm' => $registro['CrpnNm'] ?? null,
                'dados_json' => $registro,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $batchSize) {
                try {
                    // Insert no MySQL
                    if (!empty($batch)) {
                        Instrumento::insert($batch);
                    }
                    
                    // Insert no MongoDB - usando insertMany para melhor performance
                    if (!empty($batchMongoDB)) {
                        $collection = InstrumentoNoSql::raw(function($collection) use ($batchMongoDB) {
                            return $collection->insertMany($batchMongoDB);
                        });
                        $totalInseridos += count($batchMongoDB);
                    }
                    
                    Log::info("Lote inserido: " . count($batch) . " registros (Total MongoDB: {$totalInseridos})");
                    
                } catch (\Exception $e) {
                    Log::error("Erro ao inserir lote: " . $e->getMessage());
                    Log::error("Stack trace: " . $e->getTraceAsString());
                    
                    // Tenta inserir um por um no MongoDB se falhar em batch
                    if (!empty($batchMongoDB)) {
                        foreach ($batchMongoDB as $item) {
                            try {
                                InstrumentoNoSql::create($item);
                                $totalInseridos++;
                            } catch (\Exception $itemError) {
                                Log::error("Erro ao inserir item individual no MongoDB: " . $itemError->getMessage());
                            }
                        }
                    }
                }
                $batch = [];
                $batchMongoDB = [];
            }
        }

        // Processa o último lote
        if (!empty($batch)) {
            try {
                // Insert no MySQL
                Instrumento::insert($batch);
                
                // Insert no MongoDB
                if (!empty($batchMongoDB)) {
                    $collection = InstrumentoNoSql::raw(function($collection) use ($batchMongoDB) {
                        return $collection->insertMany($batchMongoDB);
                    });
                    $totalInseridos += count($batchMongoDB);
                }
                
                Log::info("Último lote inserido: " . count($batch) . " registros (Total MongoDB: {$totalInseridos})");
                
            } catch (\Exception $e) {
                Log::error("Erro ao inserir último lote: " . $e->getMessage());
                
                // Tenta inserir um por um no MongoDB se falhar em batch
                if (!empty($batchMongoDB)) {
                    foreach ($batchMongoDB as $item) {
                        try {
                            InstrumentoNoSql::create($item);
                            $totalInseridos++;
                        } catch (\Exception $itemError) {
                            Log::error("Erro ao inserir item individual no MongoDB: " . $itemError->getMessage());
                        }
                    }
                }
            }
        }

        fclose($handle);
        
        // Verifica quantos registros foram inseridos
        $countMongoDB = InstrumentoNoSql::where('upload_id', $this->uploadId)->count();
        
        Log::info("Job finalizado para Upload ID: {$this->uploadId}");
        Log::info("Total de registros inseridos no MongoDB: {$countMongoDB}");
        
        if ($countMongoDB == 0) {
            Log::error("ATENÇÃO: Nenhum registro foi inserido no MongoDB!");
        }
    }
}
