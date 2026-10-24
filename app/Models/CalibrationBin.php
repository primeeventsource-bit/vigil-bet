<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalibrationBin extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bin_index' => 'integer',
            'p_low' => 'float',
            'p_high' => 'float',
            'n' => 'integer',
            'mean_predicted' => 'float',
            'observed_rate' => 'float',
        ];
    }
}
