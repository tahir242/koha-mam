<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_horizon_snapshot_runs_only_with_the_redis_queue(): void
    {
        config(['queue.default' => 'database', 'dam.processing.queue_connection' => null]);
        $this->assertFalse($this->horizonSnapshot()->filtersPass($this->app));

        config(['queue.default' => 'redis']);
        $this->assertTrue($this->horizonSnapshot()->filtersPass($this->app));
    }

    public function test_horizon_snapshot_runs_when_only_the_media_queue_uses_redis(): void
    {
        config(['queue.default' => 'database', 'dam.processing.queue_connection' => 'redis-media']);

        $this->assertTrue($this->horizonSnapshot()->filtersPass($this->app));
    }

    private function horizonSnapshot(): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command, 'horizon:snapshot'));
    }
}
