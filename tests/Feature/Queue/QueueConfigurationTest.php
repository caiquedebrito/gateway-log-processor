<?php

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeQueuedJob;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
  use RefreshDatabase;

  public function test_job_can_be_dispatched_to_logs_queue(): void
  {
    Queue::fake();

    FakeQueuedJob::dispatch()->onQueue('logs');

    Queue::assertPushedOn('logs', FakeQueuedJob::class);
  }

  public function test_job_can_be_dispatched_to_reports_queue(): void
  {
    Queue::fake();

    FakeQueuedJob::dispatch()->onQueue('reports');

    Queue::assertPushedOn('reports', FakeQueuedJob::class);
  }
}
