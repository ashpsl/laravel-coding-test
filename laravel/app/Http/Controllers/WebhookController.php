<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function receive(Request $request, string $provider): JsonResponse
    {
        $request->merge(['provider' => $provider]);

        $request->validate([
            'provider' => 'required|string|max:255',
            'event_id' => 'required|string|max:255',
            'type' => 'required|string',
            'payload' => 'required|array',
        ]);

        $event = WebhookEvent::createOrFirst([
            'event_id' => $request->input('event_id'),
            'provider' => $provider,
        ], [
            'type' => $request->input('type'),
            'payload' => $request->input('payload'),
            'status' => 'pending',
        ]);

        if ($event->wasRecentlyCreated) {
            ProcessWebhookJob::dispatch($event)->afterCommit();
        }

        return response()->json([
            'id'     => $event->id,
            'status' => $event->status,
        ], $event->wasRecentlyCreated ? 202 : 200);
    }

    public function show(int $id): JsonResponse
    {
        $event = WebhookEvent::findOrFail($id);

        return response()->json([
            'id'            => $event->id,
            'event_id'      => $event->event_id,
            'provider'      => $event->provider,
            'type'          => $event->type,
            'status'        => $event->status,
            'error_message' => $event->error_message,
            'created_at'    => $event->created_at,
        ]);
    }
}
