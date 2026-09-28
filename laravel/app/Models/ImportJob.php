<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    protected $fillable = [
        'filename',
        'status',
        'total_rows',
        'processed_rows',
        'error_message',
    ];

    public function importJobFailures(): HasMany
    {
        return $this->hasMany(ImportJobFailure::class);
    }
}
