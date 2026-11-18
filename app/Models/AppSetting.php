<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function getMany(array $keys): array
    {
        $rows = static::query()
            ->whereIn('key', $keys)
            ->pluck('value', 'key')
            ->all();

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $rows[$key] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
