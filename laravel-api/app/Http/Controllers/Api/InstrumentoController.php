<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InstrumentoNoSql; 
use Illuminate\Support\Facades\Cache;

class InstrumentoController extends Controller
{
    public function buscar(Request $request)
    {
        $tckr = $request->input('TckrSymb', '');
        $rptdt = $request->input('RptDt', '');
        $pagina = $request->input('page', 1);
        $itensPorPagina = min($request->input('per_page', 50), 500);

        $cacheKey = "instrumentos_buscar_mongodb_" . md5($tckr . $rptdt . $pagina . $itensPorPagina);

        $resultados = Cache::remember($cacheKey, 60, function () use ($tckr, $rptdt, $itensPorPagina) {
            $query = InstrumentoNoSql::query();

            if (!empty($tckr)) {
                $query->where('TckrSymb', $tckr);
            }

            if (!empty($rptdt)) {
                $query->where('RptDt', $rptdt);
            }

            return $query->paginate($itensPorPagina);
        });

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
