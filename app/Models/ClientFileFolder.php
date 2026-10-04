<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientFileFolder extends Model
{
    protected $fillable = ['company_id', 'client_id', 'folder'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
