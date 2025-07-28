<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Instrumento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InstrumentoController extends Controller
{
    public function buscar(Request $request)
    {
        $tckr = $request->input('TckrSymb', '');
        $rptdt = $request->input('RptDt', '');
        $pagina = $request->input('page', 1);

        // Criar chave única para esta consulta específica
        $cacheKey = "instrumentos_buscar_" . md5($tckr . $rptdt . $pagina);

        Log::info("🔍 Buscando cache com chave: {$cacheKey}");

        $resultados = Cache::remember($cacheKey, 60, function () use ($tckr, $rptdt) {
            Log::info('🔄 Cache MISS: consultando o banco de dados [instrumentos_buscar]');
            
            $query = Instrumento::query();

            if (!empty($tckr)) {
                $query->where('TckrSymb', $tckr);
            }

            if (!empty($rptdt)) {
                $query->where('RptDt', $rptdt);
            }

            // IMPORTANTE: Paginação precisa ser feita DENTRO da closure
            $resultados = $query->paginate(20);
            
            Log::info("📊 Consulta executada - Total encontrado: {$resultados->total()}");
            
            return $resultados;
        });

        // Se veio do cache, loga isso
        if (Cache::has($cacheKey)) {
            Log::info('✅ Cache HIT: dados vindos do Redis');
        }

        // Transforma os resultados mantendo a estrutura de paginação
        $resultados->getCollection()->transform(function ($item) {
            return [
                'RptDt' => $item->RptDt,
                'TckrSymb' => $item->TckrSymb,
                'MktNm' => $item->MktNm,
                'SctyCtgyNm' => $item->SctyCtgyNm,
                'ISIN' => $item->ISIN,
                'CrpnNm' => $item->CrpnNm,
                'dados_completos' => json_decode($item->dados_json, true),
            ];
        });

        return response()->json($resultados);
    }
}
