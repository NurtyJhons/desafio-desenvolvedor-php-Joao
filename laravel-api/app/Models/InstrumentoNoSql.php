<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class InstrumentoNoSql extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'instrumentos_nosql';
    
    public $timestamps = true;

    protected $fillable = [
        'upload_id',
        'RptDt',
        'TckrSymb',
        'MktNm',
        'SctyCtgyNm',
        'ISIN',
        'CrpnNm',
        'dados_json',
    ];

    protected $dates = ['RptDt'];

    public function upload()
    {
        return $this->belongsTo(UploadNoSql::class, 'upload_id');
    }
}