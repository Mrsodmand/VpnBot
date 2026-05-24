<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerInbound extends Model
{
    protected $fillable = ['user_id', 'inbound_id'];
}
