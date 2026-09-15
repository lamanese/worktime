<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Db\DutyJobTemplateMapper;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\DutyJobTemplateService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class DutyJobTemplateServiceTest extends TestCase {

    private DutyJobTemplateMapper $mapper;
    private AuditLogService $auditLogService;
    private DutyJobTemplateService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(DutyJobTemplateMapper::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        $this->service = new DutyJobTemplateService($this->mapper, $this->auditLogService, $l10n);
    }

    private function makeTemplate(int $id, string $title, bool $visible = true): DutyJobTemplate {
        $t = new DutyJobTemplate();
        $t->setId($id);
        $t->setTitle($title);
        $t->setIsVisible($visible);
        return $t;
    }

    private function validPayload(array $overrides = []): array {
        return array_merge([
            'title' => 'HU Werkstatt Nord',
            'startTime' => '08:00',
            'durationMinutes' => 120,
            'note' => 'Pruefplakette mitnehmen',
            'onCall' => false,
            'isVisible' => true,
        ], $overrides);
    }

    public function testFindAllAndFindVisibleDelegateToMapper(): void {
        $all = [$this->makeTemplate(1, 'A'), $this->makeTemplate(2, 'B', false)];
        $this->mapper->method('findAll')->willReturn($all);
        $this->mapper->method('findVisible')->willReturn([$all[0]]);

        $this->assertCount(2, $this->service->findAll());
        $visible = $this->service->findVisible();
        $this->assertCount(1, $visible);
        $this->assertSame('A', $visible[0]->getTitle());
    }

    public function testCreateTrimsNormalizesAndAudits(): void {
        $this->mapper->method('insert')->willReturnCallback(function (DutyJobTemplate $t): DutyJobTemplate {
            $t->setId(11);
            return $t;
        });
        $this->auditLogService->expects($this->once())
            ->method('logCreate')
            ->with('admin', 'duty_job_template', 11, $this->anything());

        $template = $this->service->create($this->validPayload([
            'title' => '  HU Werkstatt Nord  ',
            'note' => '   ',
            'startTime' => '',
            'durationMinutes' => '',
        ]), 'admin');

        $this->assertSame('HU Werkstatt Nord', $template->getTitle());
        $this->assertNull($template->getNote());
        $this->assertNull($template->getStartTime());
        $this->assertNull($template->getDurationMinutes());
        $this->assertSame(1, $template->getIsVisible());
        $this->assertNotNull($template->getCreatedAt());
        $this->assertNotNull($template->getUpdatedAt());
    }

    public function testCreateDefaultsToVisibleWhenFieldMissing(): void {
        $this->mapper->method('insert')->willReturnArgument(0);
        $payload = $this->validPayload();
        unset($payload['isVisible']);

        $this->assertSame(1, $this->service->create($payload, 'admin')->getIsVisible());
    }

    /**
     * @dataProvider invalidPayloads
     */
    public function testValidationRejects(array $payload, string $field): void {
        $this->mapper->expects($this->never())->method('insert');
        try {
            $this->service->create($this->validPayload($payload), 'admin');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->getErrors());
        }
    }

    public static function invalidPayloads(): array {
        return [
            'empty title' => [['title' => '   '], 'title'],
            'title too long' => [['title' => str_repeat('x', 201)], 'title'],
            'note too long' => [['note' => str_repeat('x', 501)], 'note'],
            'bad hour' => [['startTime' => '25:00'], 'startTime'],
            'bad minute' => [['startTime' => '08:61'], 'startTime'],
            'no colon' => [['startTime' => '0800'], 'startTime'],
            'duration zero' => [['durationMinutes' => 0], 'durationMinutes'],
            'duration above day' => [['durationMinutes' => 1441], 'durationMinutes'],
        ];
    }

    public function testUpdateWritesOldAndNewToAudit(): void {
        $existing = $this->makeTemplate(7, 'Alt');
        $this->mapper->method('find')->with(7)->willReturn($existing);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->auditLogService->expects($this->once())
            ->method('logUpdate')
            ->with(
                'admin',
                'duty_job_template',
                7,
                $this->callback(static fn (array $old): bool => $old['title'] === 'Alt'),
                $this->callback(static fn (array $new): bool => $new['title'] === 'Neu' && $new['isVisible'] === false)
            );

        $updated = $this->service->update(7, $this->validPayload(['title' => 'Neu', 'isVisible' => false]), 'admin');

        $this->assertSame('Neu', $updated->getTitle());
        $this->assertSame(0, $updated->getIsVisible());
    }

    public function testUpdateWithoutSortOrderKeepsExistingValue(): void {
        $existing = $this->makeTemplate(7, 'Alt');
        $existing->setSortOrder(5);
        $this->mapper->method('find')->with(7)->willReturn($existing);
        $this->mapper->method('update')->willReturnArgument(0);

        $payload = $this->validPayload(['title' => 'Neu']);
        unset($payload['sortOrder']);

        $updated = $this->service->update(7, $payload, 'admin');

        $this->assertSame(5, $updated->getSortOrder());
    }

    public function testUpdateWithSortOrderSetsNewValue(): void {
        $existing = $this->makeTemplate(7, 'Alt');
        $existing->setSortOrder(5);
        $this->mapper->method('find')->with(7)->willReturn($existing);
        $this->mapper->method('update')->willReturnArgument(0);

        $updated = $this->service->update(7, $this->validPayload(['title' => 'Neu', 'sortOrder' => 2]), 'admin');

        $this->assertSame(2, $updated->getSortOrder());
    }

    public function testUpdateUnknownIdThrowsNotFound(): void {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(NotFoundException::class);
        $this->service->update(99, $this->validPayload(), 'admin');
    }

    public function testDeleteAuditsBeforeRemoving(): void {
        $existing = $this->makeTemplate(5, 'Weg damit');
        $this->mapper->method('find')->with(5)->willReturn($existing);
        $this->auditLogService->expects($this->once())
            ->method('logDelete')
            ->with('admin', 'duty_job_template', 5, $this->anything());
        $this->mapper->expects($this->once())->method('delete')->with($existing);

        $this->service->delete(5, 'admin');
    }

    public function testDeleteUnknownIdThrowsNotFound(): void {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(NotFoundException::class);
        $this->service->delete(404, 'admin');
    }
}
