<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_received_and_stored(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_001',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000, 'currency' => 'usd'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_001']);
    }

    public function test_webhook_requires_event_id(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'type'    => 'payment.succeeded',
            'payload' => ['amount' => 1000],
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_requires_payload(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_002',
            'type'     => 'payment.succeeded',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_event_id_cannot_exceed_255_characters(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => str_repeat('a', 256),
            'type' => 'payment.succeeded',
            'payload' => ['amount' => 1000],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('event_id');
    }

    public function test_webhook_status_can_be_retrieved(): void
    {
        $event = WebhookEvent::create([
            'event_id' => 'evt_003',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => 'completed',
        ]);

        $response = $this->getJson("/api/webhooks/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'event_id' => 'evt_003',
            'status'   => 'completed',
        ]);
    }

    public function test_webhook_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/webhooks/99999');

        $response->assertStatus(404);
    }

    public function test_webhook_duplicate_ids_with_same_provider(): void
    {
        Queue::fake();

        $response1 = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_004',
            'type' => 'payment.succeeded',
            'payload' => ['amount' => 500],
        ]);

        $response2 = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_004',
            'type' => 'payment.succeeded',
            'payload' => ['amount' => 100],
        ]);

        $response1->assertStatus(202);
        $response2->assertStatus(200);
        $this->assertSame($response1->json('id'), $response2->json('id'));

        Queue::assertPushed(ProcessWebhookJob::class, 1);

        $this->assertDatabaseCount('webhook_events', 1);

        $event = WebhookEvent::sole();
        $this->assertSame('stripe', $event->provider);
        $this->assertSame('evt_004', $event->event_id);
        $this->assertSame('payment.succeeded', $event->type);
        $this->assertSame(['amount' => 500], $event->payload);
    }

    public function test_webhook_duplicate_ids_with_different_provider(): void
    {
        Queue::fake();

        $duplicate = [
            'event_id' => 'evt_005',
            'type' => 'payment.succeeded',
            'payload' => ['amount' => 500],
        ];

        $response1 = $this->postJson('/api/webhooks/stripe', $duplicate);
        $response2 = $this->postJson('/api/webhooks/worldpay', $duplicate);

        $response1->assertStatus(202);
        $response2->assertStatus(202);

        Queue::assertPushed(ProcessWebhookJob::class, 2);

        $this->assertDatabaseCount('webhook_events', 2);
    }
}
