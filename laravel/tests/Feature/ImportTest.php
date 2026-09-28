<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_upload_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,secret"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));

        $job = ImportJob::find($response->json('id'));
        $this->assertStringStartsWith('imports/', $job->filename);
        Storage::disk('local')->assertExists($job->filename);
    }

    public function test_upload_requires_a_file(): void
    {
        $response = $this->postJson('/api/imports', []);

        $response->assertStatus(422);
    }

    public function test_import_status_can_be_retrieved(): void
    {
        $job = ImportJob::create([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);

        $response = $this->getJson("/api/imports/{$job->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);
    }

    public function test_import_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/imports/99999');

        $response->assertStatus(404);
    }

    public function test_invalid_rows_do_not_fail_the_whole_import(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\n"
            ."John Doe,john@example.com,password123\n"
            ."Bad Row,not-an-email,password123\n"
            .'Jane Doe,jane@example.com,password123'
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
        $this->assertEquals('completed', ImportJob::find($response->json('id'))->status);
    }

    public function test_failure_record_does_not_leak_row_data(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nBad Row,leaky-address,SuperSecret123"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $failure = ImportJob::find($response->json('id'))->importJobFailures()->sole();

        $this->assertEquals(2, $failure->row_number);
        $this->assertNotEmpty($failure->errors);
        $this->assertStringContainsString('email', $failure->errors[0]);

        $raw = $failure->getRawOriginal('errors');
        $this->assertStringNotContainsString('leaky-address', $raw);
        $this->assertStringNotContainsString('SuperSecret123', $raw);
    }

    public function test_row_with_extra_columns_is_recorded_as_failure(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,password123,extra"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $job = ImportJob::find($response->json('id'));

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(1, $job->importJobFailures()->count());
        $this->assertEquals(2, $job->importJobFailures()->sole()->row_number);
        $this->assertDatabaseMissing('users', ['email' => 'john@example.com']);
    }

    public function test_import_row_counts_are_correct(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\n"
            ."User One,one@example.com,password123\n"
            ."Bad Email,not-an-email,password123\n"
            ."User Two,two@example.com,password123\n"
            ."Short Password,short@example.com,short\n"
            ."Extra Column,extra@example.com,password123,extra\n"
            .'User Three,three@example.com,password123'
        );

        $id = $this->postJson('/api/imports', ['file' => $csv])->json('id');

        $response = $this->getJson("/api/imports/{$id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status'         => 'completed',
            'total_rows'     => 6,
            'processed_rows' => 6,
            'failed_rows'    => 3,
        ]);
    }

    public function test_uploaded_file_is_deleted_after_successful_import(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,password123"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $job = ImportJob::find($response->json('id'));

        $this->assertEquals('completed', $job->status);
        Storage::disk('local')->assertMissing($job->filename);
    }
}
