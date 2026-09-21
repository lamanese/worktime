<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\DutyJob;
use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Db\DutyJobTemplateMapper;
use OCA\Zeitwerk\Db\DutyTemplateWeekSkip;
use OCA\Zeitwerk\Db\DutyTemplateWeekSkipMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Vorlagen mit festen Wochentagen (spec §12): welche Soll-Tage einer Woche
 * sind schon verteilt, welche fehlen, welche Vorlage ist fuer die Woche
 * ignoriert. Ein Soll-Tag gilt als verteilt, sobald an dem Tag mindestens eine
 * Karte aus der Vorlage liegt — egal bei welchem Mitarbeiter.
 */
class DutyTemplateCoverageService {

    public function __construct(
        private DutyJobTemplateMapper $templateMapper,
        private DutyTemplateWeekSkipMapper $skipMapper,
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * Template a new card comes from. Unknown or missing ids answer null, so a
     * card never points to a template that did not exist when it was created.
     */
    public function resolveTemplate(mixed $templateId): ?DutyJobTemplate {
        $id = (int)($templateId ?? 0);
        if ($id <= 0) {
            return null;
        }
        try {
            return $this->templateMapper->find($id);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Fixed weekdays of every template that has some (hidden ones included: a
     * card keeps its origin when the template leaves the sidebar).
     *
     * @return array<int, int[]> template id => ascending ISO weekdays
     */
    public function fixedWeekdaysByTemplate(): array {
        $map = [];
        foreach ($this->templateMapper->findAll() as $template) {
            if ($template->getWeekdays() > 0) {
                $map[$template->getId()] = $template->getWeekdayList();
            }
        }
        return $map;
    }

    /**
     * One entry per visible template, ordered like the sidebar. Templates
     * without fixed weekdays have empty day lists; for them only `skipped`
     * («Erledigt» for this week) matters. A target day that is a holiday for
     * the whole roster is not required: it lands in `holidayDays` instead of
     * `openDays` unless a card already lies on it.
     *
     * @param DutyJob[] $jobs cards of that week that count (shown in the plan)
     * @param int[] $holidayWeekdays ISO weekdays that are a holiday for every roster employee
     * @return array<int, array{templateId:int,title:string,weekdays:int[],doneDays:int[],openDays:int[],holidayDays:int[],skipped:bool}>
     */
    public function coverage(DateTime $monday, array $jobs, array $holidayWeekdays = []): array {
        $templates = $this->templateMapper->findVisible();
        if ($templates === []) {
            return [];
        }

        $placed = [];
        foreach ($jobs as $job) {
            if ($job->getTemplateId() !== null) {
                $placed[$job->getTemplateId()][(int)$job->getJobDate()->format('N')] = true;
            }
        }
        $skipped = [];
        foreach ($this->skipMapper->findByWeekStart($monday) as $skip) {
            $skipped[$skip->getTemplateId()] = true;
        }

        $result = [];
        foreach ($templates as $template) {
            $weekdays = $template->getWeekdayList();
            $done = array_values(array_filter($weekdays, static fn (int $d) => isset($placed[$template->getId()][$d])));
            $result[] = [
                'templateId' => $template->getId(),
                'title' => $template->getTitle(),
                'weekdays' => $weekdays,
                'doneDays' => $done,
                'openDays' => array_values(array_diff($weekdays, $done, $holidayWeekdays)),
                'holidayDays' => array_values(array_diff(array_intersect($weekdays, $holidayWeekdays), $done)),
                'skipped' => isset($skipped[$template->getId()]),
            ];
        }
        return $result;
    }

    /**
     * Sum of open days over all templates that are not skipped.
     *
     * @param array<int, array{openDays:int[],skipped:bool}> $coverage
     */
    public static function countOpenDays(array $coverage): int {
        $open = 0;
        foreach ($coverage as $entry) {
            if (!$entry['skipped']) {
                $open += count($entry['openDays']);
            }
        }
        return $open;
    }

    /**
     * Idempotent. Permission is checked by the controller, the week lock by
     * DutyJobService::setTemplateSkipped() — do not call this from elsewhere.
     *
     * @throws NotFoundException when the template does not exist
     */
    public function setSkipped(int $templateId, DateTime $monday, bool $skipped, string $userId): void {
        try {
            $this->templateMapper->find($templateId);
        } catch (DoesNotExistException) {
            throw new NotFoundException('Duty job template not found');
        }

        $existing = null;
        foreach ($this->skipMapper->findByWeekStart($monday) as $skip) {
            if ($skip->getTemplateId() === $templateId) {
                $existing = $skip;
                break;
            }
        }
        $details = ['templateId' => $templateId, 'weekStart' => $monday->format('Y-m-d')];

        if ($skipped && $existing === null) {
            $skip = new DutyTemplateWeekSkip();
            $skip->setTemplateId($templateId);
            $skip->setWeekStart($monday);
            $skip->setSkippedBy($userId);
            $skip->setSkippedAt(new DateTime());
            $this->skipMapper->insert($skip);
            $this->auditLogService->log($userId, 'skip_template_week', DutyJobTemplateService::ENTITY_TYPE, $templateId, null, $details);
        } elseif (!$skipped && $existing !== null) {
            $this->skipMapper->delete($existing);
            $this->auditLogService->log($userId, 'unskip_template_week', DutyJobTemplateService::ENTITY_TYPE, $templateId, $details, null);
        }
    }
}
