<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\TimeEntryController;
use OCA\Zeitwerk\Db\ArchiveQueueMapper;
use OCA\Zeitwerk\Service\ArchiveService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\EmployeeService;
use OCA\Zeitwerk\Service\ForbiddenException;
use OCA\Zeitwerk\Service\PdfService;
use OCA\Zeitwerk\Service\PermissionService;
use OCA\Zeitwerk\Service\TimeEntryService;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The inactive-employee guard (WorkTime #486) throws ForbiddenException from
 * TimeEntryService::submitMonth(). The controller must turn that into a clean
 * HTTP 403 with the translated message, not let it escape as a 500.
 */
class TimeEntryControllerSubmitMonthTest extends TestCase {

    private TimeEntryService $timeEntryService;
    private PermissionService $permissionService;
    private ArchiveQueueMapper $archiveQueueMapper;

    protected function setUp(): void {
        $this->timeEntryService = $this->createMock(TimeEntryService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('canEditTimeEntry')->willReturn(true);
        $this->archiveQueueMapper = $this->createMock(ArchiveQueueMapper::class);
    }

    private function controller(): TimeEntryController {
        return new TimeEntryController(
            $this->createMock(IRequest::class),
            'employee-user',
            $this->timeEntryService,
            $this->permissionService,
            $this->archiveQueueMapper,
            $this->createMock(CompanySettingsService::class),
            $this->createMock(PdfService::class),
            $this->createMock(EmployeeService::class),
            $this->createMock(ArchiveService::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testSubmitMonthReturns403WhenEmployeeIsInactive(): void {
        $this->timeEntryService->method('submitMonth')
            ->willThrowException(new ForbiddenException('Für deaktivierte Mitarbeiter können keine Zeiten erfasst oder geändert werden.'));

        $response = $this->controller()->submitMonth(1, 2026, 7);

        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('deaktivierte Mitarbeiter', (string)$response->getData()['error']);
    }

    public function testSubmitMonthPassesThroughServiceResult(): void {
        $this->timeEntryService->method('submitMonth')->willReturn(['submitted' => 3, 'skipped' => 1]);

        $response = $this->controller()->submitMonth(1, 2026, 7);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(3, $response->getData()['submitted']);
    }

    public function testApproveMonthReturns400WhenMonthNotSubmitted(): void {
        $this->permissionService->method('canApprove')->willReturn(true);
        $this->timeEntryService->method('approveMonth')
            ->willThrowException(ValidationException::fromSingleError('month', 'Dieser Monat ist nicht eingereicht.'));

        $response = $this->controller()->approveMonth(1, 2026, 8);

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('nicht eingereicht', (string)$response->getData()['errors']['month'][0]);
    }

    public function testApproveMonthWithoutEntriesStillSucceeds(): void {
        $this->permissionService->method('canApprove')->willReturn(true);
        $this->timeEntryService->method('approveMonth')
            ->willReturn(['approved' => 0, 'skipped' => 0, 'monthStatus' => 'approved']);

        $response = $this->controller()->approveMonth(1, 2026, 8);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('approved', $response->getData()['monthStatus']);
        $this->assertFalse($response->getData()['archiveQueued']); // archive not configured in this test
    }

    /**
     * #Codex-P2: the archive cleanup must run whenever approved entries were
     * reverted (reopened > 0), even when the month row itself was not
     * "approved" (mixed or legacy month) — otherwise a stored/queued PDF for
     * those entries survives the reopen.
     */
    public function testReopenMonthCleansArchiveWhenEntriesWereReopened(): void {
        $this->permissionService->method('canApprove')->willReturn(true);
        $this->timeEntryService->method('reopenMonth')
            ->willReturn(['reopened' => 2, 'skipped' => 0, 'monthReopened' => false]);
        $this->archiveQueueMapper->expects($this->once())->method('deletePendingFor')->with(1, 2026, 8);

        $response = $this->controller()->reopenMonth(1, 2026, 8, 'Korrektur');

        $this->assertSame(200, $response->getStatus());
        $this->assertFalse($response->getData()['monthReopened']);
    }

    public function testReopenMonthSkipsArchiveCleanupWhenNothingChanged(): void {
        $this->permissionService->method('canApprove')->willReturn(true);
        $this->timeEntryService->method('reopenMonth')
            ->willReturn(['reopened' => 0, 'skipped' => 0, 'monthReopened' => false]);
        $this->archiveQueueMapper->expects($this->never())->method('deletePendingFor');

        $response = $this->controller()->reopenMonth(1, 2026, 8, 'Korrektur');

        $this->assertSame(200, $response->getStatus());
    }
}
