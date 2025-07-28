<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InstrumentoNoSql; 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InstrumentoController extends Controller
{
    public function buscar(Request $request)
    {
        $tckr = $request->input('TckrSymb', '');
        $rptdt = $request->input('RptDt', '');
        $pagina = $request->input('page', 1);

        $cacheKey = "instrumentos_buscar_mongodb_" . md5($tckr . $rptdt . $pagina);

        Log::info("🔍 Buscando cache com chave: {$cacheKey}");

        $resultados = Cache::remember($cacheKey, 60, function () use ($tckr, $rptdt) {
            Log::info('🔄 Cache MISS: consultando o MongoDB [instrumentos_buscar]');
            
            $query = InstrumentoNoSql::query(); // ← MongoDB

            if (!empty($tckr)) {
                $query->where('TckrSymb', $tckr);
            }

            if (!empty($rptdt)) {
                $query->where('RptDt', $rptdt);
            }

            $resultados = $query->paginate(20);
            
            Log::info("📊 Consulta executada - Total encontrado: {$resultados->total()}");
            
            return $resultados;
        });

        if (Cache::has($cacheKey)) {
            Log::info('✅ Cache HIT: dados vindos do Redis');
        }

        $resultados->getCollection()->transform(function ($item) {
            return [
                'RptDt' => $item->RptDt,
                'TckrSymb' => $item->TckrSymb,  
                'MktNm' => $item->MktNm,
                'SctyCtgyNm' => $item->SctyCtgyNm,
                'ISIN' => $item->ISIN,
                'CrpnNm' => $item->CrpnNm,
                'dados_completos' => is_string($item->dados_json) 
                    ? json_decode($item->dados_json, true) 
                    : $item->dados_json,
            ];
        });

        return response()->json($resultados);
    }
}
