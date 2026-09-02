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

    protected function setUp(): void {
        $this->timeEntryService = $this->createMock(TimeEntryService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->permissionService->method('canEditTimeEntry')->willReturn(true);
    }

    private function controller(): TimeEntryController {
        return new TimeEntryController(
            $this->createMock(IRequest::class),
            'employee-user',
            $this->timeEntryService,
            $this->permissionService,
            $this->createMock(ArchiveQueueMapper::class),
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
}
