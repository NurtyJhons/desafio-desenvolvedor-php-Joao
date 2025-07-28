<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class UploadNoSql extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'uploads_nosql';
    
    public $timestamps = true;

    protected $fillable = ['filename', 'hash', 'uploaded_at', 'caminho'];
    protected $dates = ['uploaded_at'];

    public function instrumentos()
    {
        return $this->hasMany(InstrumentoNoSql::class, 'upload_id');
    }
}