<?php

declare(strict_types=1);

namespace Modules\TasksProjects\Application\Exceptions;

/**
 * A running timer is still being measured, so only what describes it can be
 * corrected: when it started, what it says and whether it is billable.
 */
final class RunningEntryLocked extends TasksProjectsException
{
    /** @param list<string> $fields */
    public static function forFields(int $entryId, array $fields): self
    {
        return new self(
            "Time entry {$entryId} is still running: ".implode(', ', $fields).' cannot be changed until the timer stops.',
        );
    }

    public static function startRequired(int $entryId): self
    {
        return new self("Time entry {$entryId} is still running, so it needs a start time.");
    }

    public static function startInTheFuture(int $entryId): self
    {
        return new self("A running timer cannot have started in the future (time entry {$entryId}).");
    }
}
