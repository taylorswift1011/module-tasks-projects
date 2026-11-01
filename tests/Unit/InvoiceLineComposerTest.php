<?php

declare(strict_types=1);

namespace Modules\TasksProjects\Tests\Unit;

use Illuminate\Support\Carbon;
use Modules\TasksProjects\Application\InvoiceLineComposer;
use Modules\TasksProjects\Models\TimeEntry;
use Modules\TasksProjects\Support\ModuleSettings;
use Modules\TasksProjects\Tests\Support\MemorySettingsStore;
use Modules\TasksProjects\Tests\TestCase;

final class InvoiceLineComposerTest extends TestCase
{
    private const OPTIONS = [
        'project_heading' => false, 'task_description' => false, 'entry_dates' => true,
        'entry_times' => true, 'entry_hours' => true, 'entry_descriptions' => true,
    ];

    private InvoiceLineComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new InvoiceLineComposer;
    }

    private function entry(int $id, int $taskId, string $startUtc, int $minutes, ?string $description = null): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');
        $entry = new TimeEntry([
            'task_id' => $taskId, 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes, 'description' => $description,
        ]);
        $entry->forceFill(['id' => $id]);

        return $entry;
    }

    public function test_dates_and_times_are_shown_in_the_company_timezone(): void
    {
        // 12:19 on 1 September in Auckland is 00:19 UTC the same day, and 21:53 UTC on 31 August is 09:53 on 1 September.
        $note = $this->composer->description([
            $this->entry(1, 1, '2026-09-01 00:19:00', 8),
            $this->entry(2, 1, '2026-08-31 21:53:00', 114),
        ], self::OPTIONS, null, null, 'Pacific/Auckland');

        self::assertSame(
            "2026-09-01  09:53-11:47  1.90 h\n2026-09-01  12:19-12:27  0.13 h",
            $note,
        );
        self::assertSame("2026-08-31  21:53-23:47  1.90 h\n2026-09-01  00:19-00:27  0.13 h", $this->composer->description([
            $this->entry(1, 1, '2026-09-01 00:19:00', 8),
            $this->entry(2, 1, '2026-08-31 21:53:00', 114),
        ], self::OPTIONS));
    }

    public function test_a_multi_line_entry_description_stays_on_one_line(): void
    {
        $note = $this->composer->description(
            [$this->entry(1, 1, '2026-09-01 00:00:00', 60, "first line\n\nsecond   line\r\nthird")],
            ['entry_times' => false, 'entry_hours' => false] + self::OPTIONS,
        );

        self::assertSame('2026-09-01  first line second   line third', $note);
    }

    public function test_a_line_that_spans_tasks_lists_the_entries_under_each_task(): void
    {
        $note = $this->composer->description([
            $this->entry(1, 10, '2026-09-01 00:00:00', 60, 'a'),
            $this->entry(2, 20, '2026-09-02 00:00:00', 60, 'b'),
            $this->entry(3, 10, '2026-09-03 00:00:00', 60, 'c'),
        ], ['entry_times' => false, 'entry_hours' => false] + self::OPTIONS, null, null, null, [10 => 'hoop2 #5264', 20 => 'Development']);

        self::assertSame(
            "hoop2 #5264\n2026-09-01  a\n2026-09-03  c\nDevelopment\n2026-09-02  b",
            $note,
        );
    }

    public function test_a_project_line_is_named_for_the_months_it_covers_in_the_company_timezone(): void
    {
        $oct = $this->entry(1, 1, '2026-09-30 22:00:00', 30);     // 1 October in Auckland
        $nov = $this->entry(2, 1, '2026-11-02 00:00:00', 30);

        self::assertSame('Development-OCT2026 Hoop', $this->composer->projectLineName('Hoop', [$oct], 'Pacific/Auckland'));
        self::assertSame('Development-SEP2026 Hoop', $this->composer->projectLineName('Hoop', [$oct]));
        self::assertSame('Development-OCT2026-NOV2026 Hoop', $this->composer->projectLineName('Hoop', [$nov, $oct], 'Pacific/Auckland'));
    }

    public function test_a_long_note_drops_whole_entries_and_never_leaves_a_dangling_task_heading(): void
    {
        $entries = [];
        for ($i = 1; $i <= 200; $i++) {
            $entries[] = $this->entry($i, $i <= 150 ? 10 : 20, '2026-09-01 00:00:00', 60, str_repeat('x', 60));
        }

        $note = $this->composer->description($entries, self::OPTIONS, null, null, null, [10 => 'one', 20 => 'two']);
        $lines = explode("\n", (string) $note);

        self::assertLessThanOrEqual(InvoiceLineComposer::MAX_LENGTH, mb_strlen((string) $note));
        self::assertMatchesRegularExpression('/^and \d+ more entries$/', end($lines));
        self::assertNotSame('two', $lines[count($lines) - 2], 'a sub-heading must not be left with no entries under it');
    }

    public function test_the_tax_type_and_timezone_come_from_the_settings(): void
    {
        $store = new MemorySettingsStore;
        $settings = new ModuleSettings($store);

        self::assertNull($settings->invoiceTaxTypeId(9));
        self::assertSame('UTC', $settings->timezone(9));

        $store->putCompany(9, ModuleSettings::PREFIX.'invoice_tax_type_id', '1');
        $store->putCompany(9, 'time_zone', 'Pacific/Auckland');
        self::assertSame(1, $settings->invoiceTaxTypeId(9));
        self::assertSame('Pacific/Auckland', $settings->timezone(9));

        $store->putCompany(9, ModuleSettings::PREFIX.'invoice_tax_type_id', '0');
        $store->putCompany(9, 'time_zone', 'Not/AZone');
        self::assertNull($settings->invoiceTaxTypeId(9));
        self::assertSame('UTC', $settings->timezone(9));
    }
}
