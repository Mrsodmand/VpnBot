<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Countries extends Model
{
    public function Service()
    {
        return $this->belongsTo(Service::class,'type');
    }
}
