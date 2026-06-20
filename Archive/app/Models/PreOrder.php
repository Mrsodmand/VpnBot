<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreOrder extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    protected $fillable = [
        'status','count_left'
    ];
}
