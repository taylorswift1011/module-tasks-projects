<?php

declare(strict_types=1);

namespace Modules\TasksProjects\Application;

use Illuminate\Support\Carbon;
use Modules\TasksProjects\Models\Task;
use Modules\TasksProjects\Models\TimeEntry;

/**
 * What one invoice line says about the work behind it.
 *
 * The host renders `invoice_items.description` with `nl2br`, so the note is
 * plain text with newlines: an optional project heading, the task's own
 * description, then one line per time entry. Which of the four parts of an
 * entry line appear is a company setting, because the same module serves an
 * agency that bills "2026-09-01  1.50 h  Hero section" and a studio that bills
 * a bare list of dates.
 *
 * Nothing here reads the settings or the database: the service hands over the
 * entries, the labels and the resolved toggles, which keeps the composition
 * rules testable on their own and keeps a line's note free of a second query.
 */
final class InvoiceLineComposer
{
    /** How long a note may get before it is summarised instead. */
    public const MAX_LENGTH = 8000;

    /** How the parts of one entry line are separated. */
    public const PART_SEPARATOR = '  ';

    /** The prefix that turns the project name into a heading. */
    public const HEADING_PREFIX = '## ';

    /**
     * The line's name: the task's own number and name where the grouping is by
     * task, and the label the grouping already produced everywhere else.
     */
    public function name(string $label, ?Task $task = null): string
    {
        if ($task === null) {
            return $label;
        }

        return '#'.(int) $task->number.' '.(string) $task->name;
    }

    /**
     * The name of a line that bills a whole project for a period, such as
     * `Development-OCT2026 Hoop`, or `Development-OCT2026-NOV2026 Hoop` when the
     * work spans two months. The month is read in the company's timezone.
     *
     * @param  list<TimeEntry>  $entries
     */
    public function projectLineName(string $projectLabel, array $entries, ?string $timezone = null): string
    {
        $stamps = [];
        foreach ($entries as $entry) {
            if ($entry->started_at !== null) {
                $stamps[] = $this->localised($entry->started_at, $timezone);
            }
        }

        if ($stamps === []) {
            return $projectLabel;
        }

        usort($stamps, static fn (Carbon $left, Carbon $right): int => $left <=> $right);
        $first = strtoupper($stamps[0]->format('MY'));
        $last = strtoupper($stamps[count($stamps) - 1]->format('MY'));

        return 'Development-'.($first === $last ? $first : $first.'-'.$last).' '.$projectLabel;
    }

    /**
     * The line's note, or null when every part is switched off.
     *
     * Entries are read in the order they started. An entry that would render
     * as an empty line, because the only parts switched on are ones it does
     * not have, is left out rather than printed as a blank row.
     *
     * @param  list<TimeEntry>  $entries
     * @param  array{project_heading: bool, task_description: bool, entry_dates: bool, entry_times: bool, entry_hours: bool, entry_descriptions: bool}  $options
     * @param  string|null  $timezone  the company's timezone, so dates and times read as the work was done
     * @param  array<int, string>|null  $taskNames  task id => name; given when the line spans tasks, to list the entries under one sub-heading per task
     */
    public function description(
        array $entries,
        array $options,
        ?string $projectName = null,
        ?string $taskDescription = null,
        ?string $timezone = null,
        ?array $taskNames = null,
    ): ?string {
        $heading = [];

        if ($options['project_heading'] && trim((string) $projectName) !== '') {
            $heading[] = self::HEADING_PREFIX.trim((string) $projectName);
        }

        if ($options['task_description'] && trim((string) $taskDescription) !== '') {
            $heading[] = trim((string) $taskDescription);
        }

        $lines = [];
        $currentTask = null;
        foreach ($this->inTaskOrder($this->inStartOrder($entries), $taskNames !== null) as $entry) {
            $line = $this->entryLine($entry, $options, $timezone);

            if ($line === null) {
                continue;
            }

            if ($taskNames !== null && $currentTask !== (int) $entry->task_id) {
                $currentTask = (int) $entry->task_id;
                $name = trim($taskNames[$currentTask] ?? '');

                if ($name !== '') {
                    $lines[] = ['text' => $name, 'entry' => false];
                }
            }

            $lines[] = ['text' => $line, 'entry' => true];
        }

        $note = $this->capped($heading, $lines);

        return $note === '' ? null : $note;
    }

    /**
     * One entry, as the parts the company asked for.
     *
     * @param  array{project_heading: bool, task_description: bool, entry_dates: bool, entry_times: bool, entry_hours: bool, entry_descriptions: bool}  $options
     */
    private function entryLine(TimeEntry $entry, array $options, ?string $timezone = null): ?string
    {
        $parts = [];

        // The company's own date format lives in the host and is not reachable
        // from a module, so the note states the ISO date, which reads the same
        // in every locale. Times are stored in UTC, so they are shown in the
        // company's timezone: an evening's work belongs to that day.
        if ($options['entry_dates'] && $entry->started_at !== null) {
            $parts[] = $this->localised($entry->started_at, $timezone)->format('Y-m-d');
        }

        if ($options['entry_times'] && $entry->started_at !== null && $entry->ended_at !== null) {
            $parts[] = $this->localised($entry->started_at, $timezone)->format('H:i')
                .'-'.$this->localised($entry->ended_at, $timezone)->format('H:i');
        }

        if ($options['entry_hours']) {
            $parts[] = number_format((int) $entry->duration_minutes / 60, 2, '.', '').' h';
        }

        if ($options['entry_descriptions'] && trim((string) $entry->description) !== '') {
            // One entry is one line of the note, however it was typed.
            $parts[] = trim((string) preg_replace('/\s*\R\s*/u', ' ', trim((string) $entry->description)));
        }

        return $parts === [] ? null : implode(self::PART_SEPARATOR, $parts);
    }

    /**
     * Keep the note inside the cap, dropping whole entry lines from the end.
     *
     * A truncated note says how many entries it stopped listing, so a client
     * reading a month of ten-minute entries sees a readable summary instead of
     * a wall of text cut mid-sentence. The heading lines are never counted as
     * entries, nor are the task sub-headings, and the summary line itself has
     * to fit inside the cap too.
     *
     * @param  list<string>  $heading
     * @param  list<array{text: string, entry: bool}>  $lines
     */
    private function capped(array $heading, array $lines): string
    {
        $texts = static fn (array $rows): array => array_map(static fn (array $row): string => $row['text'], $rows);
        $whole = implode("\n", [...$heading, ...$texts($lines)]);

        if (mb_strlen($whole) <= self::MAX_LENGTH) {
            return $whole;
        }

        $entries = count(array_filter($lines, static fn (array $row): bool => $row['entry']));
        $kept = $lines;

        while ($kept !== []) {
            array_pop($kept);

            // A sub-heading left at the end with nothing under it is dropped too.
            while ($kept !== [] && ! $kept[count($kept) - 1]['entry']) {
                array_pop($kept);
            }

            $dropped = $entries - count(array_filter($kept, static fn (array $row): bool => $row['entry']));
            $summary = 'and '.$dropped.' more '.($dropped === 1 ? 'entry' : 'entries');
            $note = implode("\n", [...$heading, ...$texts($kept), $summary]);

            if (mb_strlen($note) <= self::MAX_LENGTH) {
                return $note;
            }
        }

        return mb_substr(implode("\n", $heading), 0, self::MAX_LENGTH);
    }

    /**
     * @param  list<TimeEntry>  $entries
     * @return list<TimeEntry>
     */
    private function inStartOrder(array $entries): array
    {
        usort($entries, static fn (TimeEntry $left, TimeEntry $right): int => [
            $left->started_at?->getTimestamp() ?? 0, (int) $left->id,
        ] <=> [
            $right->started_at?->getTimestamp() ?? 0, (int) $right->id,
        ]);

        return $entries;
    }

    /**
     * Entries grouped by task, tasks in the order their first entry started,
     * when the line spans tasks; otherwise untouched.
     *
     * @param  list<TimeEntry>  $entries  already in start order
     * @return list<TimeEntry>
     */
    private function inTaskOrder(array $entries, bool $byTask): array
    {
        if (! $byTask) {
            return $entries;
        }

        $groups = [];
        foreach ($entries as $entry) {
            $groups[(int) $entry->task_id][] = $entry;
        }

        return array_merge(...array_values($groups));
    }

    private function localised(Carbon $moment, ?string $timezone): Carbon
    {
        return $timezone === null ? $moment : $moment->copy()->setTimezone($timezone);
    }
}
