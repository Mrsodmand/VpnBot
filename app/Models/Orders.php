<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DATA_EXHAUSTED = 'data_exhausted';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected function casts()
    {
        return [
            'detail' => 'array',
            'expire_at' => 'datetime',
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
        'reminded',
        'panel_id',
        'inbound_id',
        'detail',
        'system_type',
        'expire_at',
    ];
}
