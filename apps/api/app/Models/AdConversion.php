<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One result reported back to an ad platform. See App\Domain\Ads\Conversions. */
class AdConversion extends Model
{
    protected $guarded = [];

    protected $casts = ['match' => 'array', 'clicked_at' => 'datetime', 'happened_at' => 'datetime',
        'sent_at' => 'datetime', 'value_eur' => 'float'];
}
