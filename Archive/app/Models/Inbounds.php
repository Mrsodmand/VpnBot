<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inbounds extends Model
{
    public function panel()
    {
        return $this->belongsTo(Panels::class);
    }
}
