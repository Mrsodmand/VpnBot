<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected function casts()
    {
        return [
            'detail' => 'array'
        ];
    }
}
