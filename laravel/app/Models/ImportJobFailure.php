<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJobFailure extends Model
{
    protected $fillable = ['import_job_id', 'row_number', 'errors'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
