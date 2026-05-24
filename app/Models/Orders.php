<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    protected function casts()
    {
        return[
            'detail' => 'array',
        ];
    }

    protected $fillable = [
        'user_id',
        'remark',
        'uid',
        'sub_id',
        'plan',
        'status',
        'panel_id',
        'inbound_id',
        'detail',
    ];
}
