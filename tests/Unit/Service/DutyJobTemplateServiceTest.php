<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Db\DutyJobTemplateMapper;
use OCA\Zeitwerk\Db\DutyTemplateWeekSkipMapper;
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

    private DutyTemplateWeekSkipMapper $skipMapper;

    protected function setUp(): void {
        $this->mapper = $this->createMock(DutyJobTemplateMapper::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        $this->skipMapper = $this->createMock(DutyTemplateWeekSkipMapper::class);
        $this->service = new DutyJobTemplateService($this->mapper, $this->skipMapper, $this->auditLogService, $l10n);
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
        $this->skipMapper->expects($this->once())->method('deleteByTemplate')->with(5);

        $this->service->delete(5, 'admin');
    }

    public function testDeleteUnknownIdThrowsNotFound(): void {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(NotFoundException::class);
        $this->service->delete(404, 'admin');
    }

    // ---- feste Wochentage (spec §12) ----

    public function testCreateStoresWeekdaysAsBitmaskAndSerializesSortedList(): void {
        $this->mapper->method('insert')->willReturnArgument(0);

        $template = $this->service->create(['title' => 'Reinigung', 'weekdays' => [5, 1, '3', 1]], 'admin');

        $this->assertSame(0b0010101, $template->getWeekdays());
        $this->assertSame([1, 3, 5], $template->jsonSerialize()['weekdays']);
    }

    public function testCreateWithoutWeekdaysMeansAnyDay(): void {
        $this->mapper->method('insert')->willReturnArgument(0);
        $template = $this->service->create(['title' => 'Frei'], 'admin');
        $this->assertSame(0, $template->getWeekdays());
        $this->assertSame([], $template->jsonSerialize()['weekdays']);
    }

    public function testCreateRejectsInvalidWeekdays(): void {
        foreach ([[0], [8], ['x'], ['3.9'], [2.5], [[1]], 'mo'] as $bad) {
            try {
                $this->service->create(['title' => 'X', 'weekdays' => $bad], 'admin');
                $this->fail('expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertTrue($e->hasError('weekdays'));
            }
        }
    }

    public function testUpdateWithoutWeekdaysKeepsThemAndEmptyListClearsThem(): void {
        $existing = $this->makeTemplate(5, 'Reinigung');
        $existing->setWeekdays(0b0000011);
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->method('update')->willReturnArgument(0);

        $kept = $this->service->update(5, ['title' => 'Reinigung', 'weekdays' => null], 'admin');
        $this->assertSame([1, 2], $kept->getWeekdayList());

        $cleared = $this->service->update(5, ['title' => 'Reinigung', 'weekdays' => []], 'admin');
        $this->assertSame([], $cleared->getWeekdayList());
    }

    public function testAllowOtherDaysNeedsFixedWeekdaysAndSurvivesPartialUpdate(): void {
        $this->mapper->method('insert')->willReturnArgument(0);
        $this->mapper->method('update')->willReturnArgument(0);

        $free = $this->service->create(['title' => 'Frei', 'allowOtherDays' => true], 'admin');
        $this->assertFalse($free->jsonSerialize()['allowOtherDays']); // meaningless without fixed days

        $fixed = $this->service->create(['title' => 'Fix', 'weekdays' => [1], 'allowOtherDays' => true], 'admin');
        $this->assertTrue($fixed->jsonSerialize()['allowOtherDays']);
        $this->assertTrue($fixed->allowsDay(2));

        // visibility toggle sends neither weekdays nor the flag
        $this->mapper->method('find')->willReturn($fixed);
        $kept = $this->service->update(1, ['title' => 'Fix', 'weekdays' => null, 'allowOtherDays' => null], 'admin');
        $this->assertTrue((bool)$kept->getAllowOtherDays());

        $strict = $this->service->update(1, ['title' => 'Fix', 'allowOtherDays' => false], 'admin');
        $this->assertFalse($strict->allowsDay(2));
        $this->assertTrue($strict->allowsDay(1));
    }
}
