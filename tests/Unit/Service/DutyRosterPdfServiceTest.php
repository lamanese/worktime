<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\DutyRosterPdfService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DutyRosterPdfServiceTest extends TestCase {

    private CompanySettingsService $settings;
    private IRootFolder $rootFolder;
    private IUserManager $userManager;
    private AuditLogService $audit;
    private LoggerInterface $logger;
    private DutyRosterPdfService $service;
    /** @var array<string,string> */
    private array $settingValues = [];

    protected function setUp(): void {
        $this->settings = $this->createMock(CompanySettingsService::class);
        $this->settings->method('get')->willReturnCallback(fn (string $key) => $this->settingValues[$key] ?? null);
        $this->settings->method('getCompanyName')->willReturn('Muster AG');
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Hanna HR');
        $this->userManager->method('get')->willReturn($user);
        $this->audit = $this->createMock(AuditLogService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        $this->service = new DutyRosterPdfService($this->settings, $this->rootFolder, $this->userManager, $this->audit, $this->logger, $l10n);
    }

    private function week(bool $locked = false): array {
        return [
            'weekStart' => '2026-09-14',
            'weekEnd' => '2026-09-20',
            'locked' => $locked,
            'lockedBy' => $locked ? 'Hanna HR' : null,
            'lockedAt' => $locked ? '2026-09-15T08:00:00+02:00' : null,
            'days' => array_map(static fn (int $i) => [
                'date' => (new DateTime('2026-09-14'))->modify("+$i day")->format('Y-m-d'),
                'isWeekend' => $i >= 5,
                'isToday' => false,
            ], range(0, 6)),
            'rows' => [
                [
                    'employee' => ['id' => 1, 'userId' => 'anna', 'fullName' => 'Anna Muster'],
                    'jobs' => [
                        ['id' => 1, 'date' => '2026-09-15', 'startTime' => '13:00', 'title' => 'Spät', 'durationMinutes' => 90, 'onCall' => false],
                        ['id' => 2, 'date' => '2026-09-15', 'startTime' => '08:30', 'title' => 'CarTech', 'durationMinutes' => null, 'onCall' => true],
                        ['id' => 3, 'date' => '2026-09-16', 'startTime' => null, 'title' => 'Lager', 'durationMinutes' => 60, 'onCall' => false],
                    ],
                    'absences' => [['date' => '2026-09-17', 'type' => 'vacation', 'typeName' => 'Urlaub', 'status' => 'approved', 'scope' => 1.0]],
                    'holidays' => [['date' => '2026-09-18', 'name' => 'Testtag']],
                ],
            ],
        ];
    }

    public function testFilenameUsesIsoWeekAndIsoYear(): void {
        $this->assertSame('KW38-2026.pdf', DutyRosterPdfService::filename(new DateTime('2026-09-14')));
        $this->assertSame('KW53-2026.pdf', DutyRosterPdfService::filename(new DateTime('2026-12-28')));
        $this->assertSame('KW01-2027.pdf', DutyRosterPdfService::filename(new DateTime('2027-01-04')));
    }

    public function testRenderProducesPdfEvenWhenEmpty(): void {
        $pdf = $this->service->render($this->week(true), 'hr1');
        $this->assertStringStartsWith('%PDF-', $pdf);

        $empty = $this->week();
        $empty['rows'] = [];
        $this->assertStringStartsWith('%PDF-', $this->service->render($empty, 'hr1'));
    }

    public function testCellTextOrdersJobsAndLabelsAbsences(): void {
        $method = new \ReflectionMethod(DutyRosterPdfService::class, 'cellText');
        $row = $this->week()['rows'][0];

        $this->assertSame("08:30 CarTech · Abruf\n13:00 Spät (1:30 h)", $method->invoke($this->service, $row, '2026-09-15'));
        $this->assertSame('Lager (1 h)', $method->invoke($this->service, $row, '2026-09-16'));
        $this->assertSame('[Urlaub]', $method->invoke($this->service, $row, '2026-09-17'));
        $this->assertSame('[Feiertag: Testtag]', $method->invoke($this->service, $row, '2026-09-18'));
        $this->assertSame('', $method->invoke($this->service, $row, '2026-09-19'));
    }

    public function testExportSkipsArchiveWithoutArchiveUser(): void {
        $this->settingValues = [CompanySetting::KEY_PDF_ARCHIVE_PATH => '/Zeitwerk/Archiv'];
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->audit->expects($this->once())->method('log')
            ->with('hr1', 'export_pdf', 'duty_job', null, null, ['weekStart' => '2026-09-14', 'archive' => 'skipped', 'path' => null]);

        $result = $this->service->export($this->week(), 'hr1');

        $this->assertSame('skipped', $result['archive']);
        $this->assertNull($result['path']);
        $this->assertSame('KW38-2026.pdf', $result['filename']);
        $this->assertStringStartsWith('%PDF-', $result['pdf']);
    }

    public function testExportWritesIntoArchiveSubfolderAndReplacesExisting(): void {
        $this->settingValues = [
            CompanySetting::KEY_PDF_ARCHIVE_USER => 'archiv',
            CompanySetting::KEY_PDF_ARCHIVE_PATH => '/Zeitwerk/Archiv/',
        ];
        $existing = $this->createMock(File::class);
        $existing->expects($this->once())->method('delete');
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturnCallback(function (string $path) use ($existing) {
            return match ($path) {
                'Zeitwerk', 'Zeitwerk/Archiv' => $this->createMock(Folder::class),
                'Zeitwerk/Archiv/Dienstplan/KW38-2026.pdf' => $existing,
                default => throw new FilesNotFoundException($path),
            };
        });
        $folder->expects($this->once())->method('newFolder')->with('Zeitwerk/Archiv/Dienstplan')->willReturn($this->createMock(Folder::class));
        $folder->expects($this->once())->method('newFile')
            ->with('Zeitwerk/Archiv/Dienstplan/KW38-2026.pdf', $this->stringStartsWith('%PDF-'))
            ->willReturn($this->createMock(File::class));
        $this->rootFolder->method('getUserFolder')->with('archiv')->willReturn($folder);

        $result = $this->service->export($this->week(), 'hr1');

        $this->assertSame('saved', $result['archive']);
        $this->assertSame('/Zeitwerk/Archiv/Dienstplan/KW38-2026.pdf', $result['path']);
    }

    public function testExportReportsFailedArchiveButStillReturnsPdf(): void {
        $this->settingValues = [
            CompanySetting::KEY_PDF_ARCHIVE_USER => 'archiv',
            CompanySetting::KEY_PDF_ARCHIVE_PATH => '/Zeitwerk/Archiv',
        ];
        $this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('storage offline'));
        $this->logger->expects($this->once())->method('warning');
        $this->audit->expects($this->once())->method('log')
            ->with('hr1', 'export_pdf', 'duty_job', null, null, ['weekStart' => '2026-09-14', 'archive' => 'failed', 'path' => null]);

        $result = $this->service->export($this->week(), 'hr1');

        $this->assertSame('failed', $result['archive']);
        $this->assertStringStartsWith('%PDF-', $result['pdf']);
    }
}
