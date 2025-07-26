<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Instrumento;

class InstrumentoController extends Controller
{
    public function buscar(Request $request)
    {
        $query = Instrumento::query();

        if ($request->has('TckrSymb')) {
            $query->where('TckrSymb', $request->input('TckrSymb'));
        }

        if ($request->has('RptDt')) {
            $query->where('RptDt', $request->input('RptDt'));
        }

        // Paginação com 20 por página por padrão
        $resultados = $query->paginate(20);

        // Formata os resultados
        $resultados->getCollection()->transform(function ($item) {
            return [
                'TckrSymb' => $item->TckrSymb,
                'RptDt' => $item->RptDt,
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
