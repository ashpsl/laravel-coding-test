<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\ImportJobFailure;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ImportJob $importJob) {}

    public function handle(): void
    {
        $this->importJob->update(['status' => 'processing']);

        $path = Storage::path($this->importJob->filename);

        if (! file_exists($path)) {
            $this->importJob->update([
                'status'        => 'failed',
                'error_message' => 'File not found.',
            ]);
            return;
        }

        $this->importJob->update(['total_rows' => $this->countRows($path)]);

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $rowNumber = 1;
        $processed = 0;
        $failures = [];

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $processed++;

            if (count($data) !== count($header)) {
                $failures[] = [
                    'import_job_id' => $this->importJob->id,
                    'row_number' => $rowNumber,
                    'errors' => json_encode(['Column count does not match the header']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            $row = array_combine($header, $data);

            $validator = Validator::make($row, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email',
                'password' => 'required|string|min:8',
            ]);

            if ($validator->fails()){
                $failures[] = [
                    'import_job_id' => $this->importJob->id,
                    'row_number' => $rowNumber,
                    'errors' => json_encode($validator->errors()->all()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            try {
                User::create([
                    'name'     => $row['name'],
                    'email'    => $row['email'],
                    'password' => bcrypt($row['password']),
                ]);
            } catch (\Throwable $e) {
                Log::error('Import row failed', [
                    'import_job_id' => $this->importJob->id,
                    'row' => $rowNumber,
                    'exception' => $e::class,
                ]);

                $failures[] = [
                    'import_job_id' => $this->importJob->id,
                    'row_number' => $rowNumber,
                    'errors' => json_encode(['Could not be imported']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($processed % 100 === 0) {
                $this->importJob->update(['processed_rows' => $processed]);
            }

            if (count($failures) >= 100) {
                ImportJobFailure::insert($failures);
                $failures = [];
            }
        }

        if ($failures !== []) {
            ImportJobFailure::insert($failures);
        }

        fclose($handle);

        $this->importJob->update([
            'status'         => 'completed',
            'processed_rows' => $processed,
        ]);

        Storage::delete($this->importJob->filename);
    }

    public function failed(\Throwable $e): void
    {
        Storage::delete($this->importJob->filename);
        
        Log::error('Import failed', [
            'import_job_id' => $this->importJob->id,
            'exception' => $e::class,
        ]);

        $this->importJob->update([
            'status'        => 'failed',
            'error_message' => 'The import could not be completed.',
        ]);
    }

    private function countRows(string $path): int
    {
        $handle = fopen($path, 'r');
        $lines = 0;

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return max($lines - 1, 0);
    }
}
