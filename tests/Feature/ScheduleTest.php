<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_horizon_snapshot_runs_only_with_the_redis_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->assertFalse($this->horizonSnapshot()->filtersPass($this->app));

        config(['queue.default' => 'redis']);
        $this->assertTrue($this->horizonSnapshot()->filtersPass($this->app));
    }

    private function horizonSnapshot(): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command, 'horizon:snapshot'));
    }
}
