<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductParameterDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_en',
        'slug',
        'input_type',
        'values',
        'values_en',
        'is_variant',
        'is_required',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'values' => 'array',
        'values_en' => 'array',
        'is_variant' => 'boolean',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function nameForLanguage(string $language): string
    {
        if (mb_strtolower(trim($language)) === 'en' && filled($this->name_en)) {
            return trim((string) $this->name_en);
        }

        return (string) $this->name;
    }

    /**
     * English values are stored at the same indexes as their Polish counterparts.
     * Missing English entries deliberately fall back to Polish without changing order.
     *
     * @return list<string>
     */
    public function valuesForLanguage(string $language): array
    {
        $polishValues = collect((array) $this->values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->values();

        if (mb_strtolower(trim($language)) !== 'en') {
            return $polishValues->all();
        }

        $englishValues = (array) $this->values_en;

        return $polishValues
            ->map(function (string $polishValue, int $index) use ($englishValues): string {
                $englishValue = trim((string) ($englishValues[$index] ?? ''));

                return $englishValue !== '' ? $englishValue : $polishValue;
            })
            ->all();
    }

    public function valueForLanguage(mixed $value, string $language): string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strtolower(trim($language)) !== 'en') {
            return $value;
        }

        $index = collect((array) $this->values)
            ->search(fn (mixed $polishValue): bool => mb_strtolower(trim((string) $polishValue)) === mb_strtolower($value));

        if ($index === false) {
            return $value;
        }

        $englishValues = (array) $this->values_en;
        $englishValue = trim((string) ($englishValues[(int) $index] ?? ''));

        return $englishValue !== '' ? $englishValue : $value;
    }
}
