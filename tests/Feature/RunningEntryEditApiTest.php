<?php

declare(strict_types=1);

namespace Modules\TasksProjects\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\TasksProjects\Models\TimeEntry;
use Modules\TasksProjects\Support\Abilities;
use Modules\TasksProjects\Support\Authorizes;
use Modules\TasksProjects\Tests\TestCase;

final class RunningEntryEditApiTest extends TestCase
{
    private const COMPANY = 9;

    private const OTHER_USER = 8;

    private function running(int $userId = self::DEFAULT_USER): TimeEntry
    {
        $task = $this->makeTask(self::COMPANY);

        return $this->makeEntry(self::COMPANY, (int) $task->id, [
            'user_id' => $userId,
            'running_user_id' => $userId,
            'started_at' => Carbon::parse('2026-09-01 02:32:00'),
            'ended_at' => null,
            'duration_minutes' => 0,
            'rate' => 0,
            'amount' => 0,
        ]);
    }

    private function edit(TimeEntry $entry, array $body): TestResponse
    {
        return $this->asCompany(self::COMPANY)->putJson('/api/v1/tasks-projects/time-entries/'.$entry->id, $body);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 06:00:00');
    }

    public function test_the_start_of_a_running_timer_can_be_corrected_and_it_keeps_running(): void
    {
        $entry = $this->running();

        $this->edit($entry, ['started_at' => '2026-09-01 01:15:00', 'description' => 'Fixing the importer', 'billable' => false])
            ->assertOk()
            ->assertJsonPath('data.is_running', true)
            ->assertJsonPath('data.description', 'Fixing the importer')
            ->assertJsonPath('data.billable', false);

        $fresh = $entry->fresh();
        self::assertSame('2026-09-01 01:15:00', $fresh->started_at->toDateTimeString());
        self::assertSame(self::DEFAULT_USER, (int) $fresh->running_user_id);
        self::assertNull($fresh->ended_at);
        self::assertSame(0, (int) $fresh->amount);
    }

    public function test_stopping_after_a_correction_measures_from_the_corrected_start(): void
    {
        $entry = $this->running();
        $this->edit($entry, ['started_at' => '2026-09-01 04:00:00'])->assertOk();

        $this->asCompany(self::COMPANY)->postJson('/api/v1/tasks-projects/timer/stop')
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 120)
            ->assertJsonPath('data.is_running', false);
    }

    public function test_the_end_the_length_the_rate_and_the_task_of_a_running_timer_are_refused(): void
    {
        $entry = $this->running();
        $other = $this->makeTask(self::COMPANY);

        $this->edit($entry, ['ended_at' => '2026-09-01 05:00:00'])->assertStatus(422)->assertJsonPath('error', 'running_entry_locked');
        $this->edit($entry, ['duration_minutes' => 30])->assertStatus(422);
        $this->edit($entry, ['rate' => 9000])->assertStatus(422);
        $this->edit($entry, ['task_id' => $other->id])->assertStatus(422);

        self::assertSame(self::DEFAULT_USER, (int) $entry->fresh()->running_user_id);
    }

    public function test_a_running_timer_cannot_start_in_the_future_or_lose_its_start(): void
    {
        $entry = $this->running();

        $this->edit($entry, ['started_at' => '2026-09-01 07:00:00'])->assertStatus(422);
        $this->edit($entry, ['started_at' => null])->assertStatus(422);

        self::assertSame('2026-09-01 02:32:00', $entry->fresh()->started_at->toDateTimeString());
    }

    public function test_someone_who_may_edit_all_time_can_correct_a_members_running_timer_and_others_cannot(): void
    {
        $theirs = $this->running(self::OTHER_USER);

        $this->edit($theirs, ['started_at' => '2026-09-01 03:00:00'])->assertOk();

        $this->authorization->deny(Authorizes::id(Abilities::EDIT_ALL_TIME));
        $this->edit($theirs, ['started_at' => '2026-09-01 03:30:00'])->assertForbidden();
        self::assertSame('2026-09-01 03:00:00', $theirs->fresh()->started_at->toDateTimeString());
    }
}
