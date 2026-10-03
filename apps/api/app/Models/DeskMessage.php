<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeskMessage extends Model
{
    protected $guarded = [];

    protected $casts = ['meta' => 'array'];
}
