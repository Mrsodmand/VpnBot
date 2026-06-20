<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name' , 'status','id','price_per_gb'
    ];

    public function countries()
    {
        return $this->hasMany(Countries::class,'type');
    }
}
