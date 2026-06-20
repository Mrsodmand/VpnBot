<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'status',
        'user_id',
        'order_id',
        'admin_id',
        'method',
        'ref_id',
        'type',
        'price',
        'status',
        'detail',
        'expired_at',
    ];

    protected function casts()
    {
        return [
            'detail' => 'array'
        ];
    }
}
