<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only fetch groups that have duplicates, keeping the earliest row (lowest id) of each.
        // Uses get() rather than lazy(): deleting rows removes groups from this result set,
        // which would shift offset-based pagination and skip groups.
        $duplicates = DB::table('webhook_events')
            ->select('event_id', 'provider', DB::raw('MIN(id) as keep_id'))
            ->groupBy('event_id', 'provider')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('webhook_events')
                ->select('id', 'event_id', 'provider', 'status', 'created_at')
                ->where('event_id', $duplicate->event_id)
                ->where('provider', $duplicate->provider)
                ->where('id', '>', $duplicate->keep_id)
                ->get();

            // Deletion is irreversible, so log enough to identify each deleted row.
            // Payloads are excluded as they may contain sensitive data.
            Log::warning('Deleting duplicate webhook events', [
                'event_id' => $duplicate->event_id,
                'provider' => $duplicate->provider,
                'kept_id' => $duplicate->keep_id,
                'deleted' => $rows->all(),
            ]);

            DB::table('webhook_events')->whereIn('id', $rows->pluck('id'))->delete();
        }

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->unique(['event_id', 'provider']);
        });
    }

    public function down(): void
    {
        // Duplicate rows removed in up() are not restored; see the migration log output.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'provider']);
        });
    }
};
