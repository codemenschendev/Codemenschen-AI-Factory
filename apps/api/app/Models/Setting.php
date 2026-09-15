<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** A key/value switch the operator changes at runtime. Read through a short cache. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = Cache::remember("setting:{$key}", 30, fn () => static::find($key)?->value);

        return $row['v'] ?? $default;
    }

    public static function write(string $key, mixed $value, ?string $by = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'updated_by' => $by]);
        Cache::forget("setting:{$key}");
    }
}
