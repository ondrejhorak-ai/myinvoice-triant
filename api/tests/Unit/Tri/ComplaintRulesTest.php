<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\ComplaintRules;
use PHPUnit\Framework\TestCase;

final class ComplaintRulesTest extends TestCase
{
    public function testCreateRequiresTitleAndJob(): void
    {
        $row = ComplaintRules::normalizeCreate([
            'job_id'      => '12',
            'title'       => '  Prasklý lak  ',
            'description' => '  ',
        ]);
        $this->assertSame(12, $row['job_id']);
        $this->assertSame('Prasklý lak', $row['title']);
        $this->assertNull($row['description']);
    }

    public function testCreateWithoutTitleFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TITLE_REQUIRED');
        ComplaintRules::normalizeCreate(['job_id' => 1, 'title' => '  ']);
    }

    public function testCreateWithoutJobFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_JOB');
        ComplaintRules::normalizeCreate(['title' => 'X']);
    }

    public function testCloseSetsClosedAtAndReopenClearsIt(): void
    {
        $closed = ComplaintRules::applyStatus('closed', '2026-08-14 11:00:00');
        $this->assertSame('closed', $closed['status']);
        $this->assertSame('2026-08-14 11:00:00', $closed['closed_at']);

        $open = ComplaintRules::applyStatus('open');
        $this->assertSame('open', $open['status']);
        $this->assertNull($open['closed_at']);
    }

    public function testInvalidStatusFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_STATUS');
        ComplaintRules::applyStatus('done');
    }

    public function testEventTypesForOpenCloseReopen(): void
    {
        $this->assertSame('complaint_closed', ComplaintRules::eventTypeForTransition('open', 'closed'));
        $this->assertSame('complaint_reopened', ComplaintRules::eventTypeForTransition('closed', 'open'));
        $this->assertNull(ComplaintRules::eventTypeForTransition('open', 'open'));
    }

    public function testCommentTrimsAndRejectsEmpty(): void
    {
        $this->assertSame('Lak praskl', ComplaintRules::normalizeComment("  Lak praskl  "));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COMMENT_REQUIRED');
        ComplaintRules::normalizeComment('   ');
    }
}
