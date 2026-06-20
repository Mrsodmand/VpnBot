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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = [
        'id',
        'user_id',
        'remark',
        'uid',
        'sub_id',
        'plan',
        'status',
        'panel_id',
        'inbound_id',
        'detail',
        'system_type',
        'expire_at',
    ];
}
