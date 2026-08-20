<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestReport extends Model
{
    protected $guarded = [];

    protected $casts = ['report' => 'array'];
}
