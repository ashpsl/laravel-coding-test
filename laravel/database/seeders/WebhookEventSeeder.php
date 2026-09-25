<?php

namespace Database\Seeders;

use App\Models\WebhookEvent;
use Illuminate\Database\Seeder;

class WebhookEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WebhookEvent::factory(5)->create();
    }
}
