<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateInterval;
use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\DutyJob;
use OCA\Zeitwerk\Db\DutyJobMapper;
use OCA\Zeitwerk\Db\DutyWeekLock;
use OCA\Zeitwerk\Db\DutyWeekLockMapper;
use OCA\Zeitwerk\Db\Employee;
use OCA\Zeitwerk\Db\EmployeeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Dienstplan (Wochenplan): Auftragskarten pro Mitarbeiter und Tag. Fachlich
 * getrennt von der Zeiterfassung — es entstehen keine Zeiteintraege.
 */
class DutyJobService {

    public const ENTITY_TYPE = 'duty_job';
    private const TITLE_MAX = 200;
    private const NOTE_MAX = 500;
    private const SUGGEST_LIMIT = 10;

    public function __construct(
        private DutyJobMapper $jobMapper,
        private EmployeeMapper $employeeMapper,
        private AbsenceService $absenceService,
        private HolidayService $holidayService,
        private PermissionService $permissionService,
        private AuditLogService $auditLogService,
        private IL10N $l,
        private CompanySettingsService $settingsService,
        private DutyWeekLockMapper $lockMapper,
        private IUserManager $userManager,
    ) {
    }

    /**
     * Monday 00:00 of the ISO week containing $date (local time, no timezone shift).
     */
    public static function mondayOf(DateTime $date): DateTime {
        $monday = (clone $date)->setTime(0, 0, 0);
        $dow = (int)$monday->format('N'); // 1 = Monday
        if ($dow > 1) {
            $monday->sub(new DateInterval('P' . ($dow - 1) . 'D'));
        }
        return $monday;
    }

    /**
     * Everything the week view needs in one call (spec §3).
     */
    public function getWeek(DateTime $monday, string $userId): array {
        $monday = self::mondayOf($monday);
        $sunday = (clone $monday)->add(new DateInterval('P6D'));
        $canManage = $this->permissionService->canManageDutyRoster($userId);
        $canUnlock = $this->permissionService->canUnlockDutyWeek($userId);
        $isPrivileged = $this->permissionService->isAdmin($userId) || $this->permissionService->isHrManager($userId);
        $viewer = $this->permissionService->getEmployeeForUser($userId);
        $viewerId = $viewer?->getId();
        $subtreeIds = $viewerId !== null
            ? array_map(static fn (Employee $e) => $e->getId(), $this->permissionService->getSubordinateEmployees($viewerId))
            : [];
        $today = (new DateTime())->format('Y-m-d');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = (clone $monday)->add(new DateInterval('P' . $i . 'D'));
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'isWeekend' => (int)$d->format('N') >= 6,
                'isToday' => $d->format('Y-m-d') === $today,
            ];
        }

        $employees = $this->employeeMapper->findAllActiveInDutyRoster();
        $rosterIds = array_map(static fn (Employee $e) => $e->getId(), $employees);

        $jobsByEmployee = [];
        foreach ($this->jobMapper->findByDateRange($monday, $sunday) as $job) {
            if (!in_array($job->getEmployeeId(), $rosterIds, true)) {
                continue; // employee left the roster: card stays in DB, not shown
            }
            $jobsByEmployee[$job->getEmployeeId()][] = $job->jsonSerialize();
        }

        $rows = [];
        foreach ($employees as $employee) {
            $visible = $this->absenceService->isEmployeeVisibleInOverview($employee, $isPrivileged, $viewerId, $subtreeIds);
            $unmasked = $isPrivileged
                || in_array($employee->getId(), $subtreeIds, true)
                || $employee->getId() === $viewerId;

            $jobs = $jobsByEmployee[$employee->getId()] ?? [];
            if (!$canManage) {
                $jobs = array_map(
                    static fn (array $job) => array_diff_key($job, ['createdBy' => null, 'createdAt' => null, 'updatedAt' => null]),
                    $jobs
                );
            }

            $rows[] = [
                'employee' => [
                    'id' => $employee->getId(),
                    'userId' => $employee->getUserId(),
                    'fullName' => $employee->getFullName(),
                ],
                'jobs' => $jobs,
                'absences' => $visible ? $this->absenceDays($employee, $monday, $sunday, $unmasked) : [],
                'holidays' => $this->holidayDays($employee, $monday, $sunday),
            ];
        }

        return array_merge([
            'weekStart' => $monday->format('Y-m-d'),
            'weekEnd' => $sunday->format('Y-m-d'),
            'canManage' => $canManage,
            'canUnlock' => $canUnlock,
            'showTemplates' => $canManage && $this->settingsService->isDutyRosterTemplatesSidebarEnabled(),
            'days' => $days,
            'rows' => $rows,
        ], $this->lockInfo($monday));
    }

    // ---- Wochensperre («Schluessel», spec §11.2) ----

    private function findLock(DateTime $monday): ?DutyWeekLock {
        try {
            return $this->lockMapper->findByWeekStart(self::mondayOf($monday));
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function isWeekLocked(DateTime $day): bool {
        return $this->findLock($day) !== null;
    }

    /**
     * @return array{locked:bool,lockedBy:?string,lockedAt:?string}
     */
    public function lockInfo(DateTime $day): array {
        $lock = $this->findLock($day);
        if ($lock === null) {
            return ['locked' => false, 'lockedBy' => null, 'lockedAt' => null];
        }
        $user = $this->userManager->get($lock->getLockedBy());
        return [
            'locked' => true,
            'lockedBy' => $user?->getDisplayName() ?? $lock->getLockedBy(),
            'lockedAt' => $lock->getLockedAt()->format('c'),
        ];
    }

    /**
     * Idempotent: an already locked week keeps its original lock (no audit noise).
     *
     * @return array{locked:bool,lockedBy:?string,lockedAt:?string}
     */
    public function lockWeek(DateTime $day, string $userId): array {
        $monday = self::mondayOf($day);
        if ($this->findLock($monday) === null) {
            $lock = new DutyWeekLock();
            $lock->setWeekStart($monday);
            $lock->setLockedBy($userId);
            $lock->setLockedAt(new DateTime());
            $this->lockMapper->insert($lock);
            $this->auditLogService->log($userId, 'lock_week', self::ENTITY_TYPE, null, null, [
                'weekStart' => $monday->format('Y-m-d'),
            ]);
        }
        return $this->lockInfo($monday);
    }

    /**
     * Idempotent: unlocking an open week is a no-op. Permission (Admin/HR only)
     * is checked by the controller.
     *
     * @return array{locked:bool,lockedBy:?string,lockedAt:?string}
     */
    public function unlockWeek(DateTime $day, string $userId): array {
        $monday = self::mondayOf($day);
        $lock = $this->findLock($monday);
        if ($lock !== null) {
            $this->lockMapper->delete($lock);
            $this->auditLogService->log($userId, 'unlock_week', self::ENTITY_TYPE, null, [
                'weekStart' => $monday->format('Y-m-d'),
                'lockedBy' => $lock->getLockedBy(),
            ], null);
        }
        return $this->lockInfo($monday);
    }

    /**
     * Every write path calls this for each week it touches (also via the API).
     *
     * @throws ForbiddenException
     */
    private function assertWeekOpen(DateTime $day): void {
        if ($this->isWeekLocked($day)) {
            throw new ForbiddenException($this->l->t('Woche ist gesperrt'));
        }
    }

    /**
     * Absences resolved to single days inside the week. Same rule as
     * AbsenceService::getAbsenceOverview: viewers who see the employee unmasked
     * (Admin/HR, own subtree, own row) get approved AND pending with the real
     * type; everyone else only approved, masked as 'absent' — unless the
     * employee's absenceDetail is 'detailed', then the real type is shown but
     * still no pending requests.
     *
     * @return array<int, array{date:string,type:string,typeName:string,status:string,scope:float}>
     */
    private function absenceDays(Employee $employee, DateTime $monday, DateTime $sunday, bool $unmasked): array {
        $result = [];
        foreach ($this->absenceService->findByEmployeeAndDateRange($employee->getId(), $monday, $sunday) as $absence) {
            $status = $absence->getStatus();
            if ($status === Absence::STATUS_APPROVED) {
                // ok
            } elseif ($status === Absence::STATUS_PENDING && $unmasked) {
                // ok, planners see requests
            } else {
                continue;
            }
            $showType = $unmasked || $employee->getAbsenceDetail() === 'detailed';
            $type = $showType ? (string)$absence->getType() : 'absent';
            $typeName = $showType ? $absence->getTypeName() : $this->l->t('Abwesend');

            $cursor = $absence->getStartDate() > $monday ? $absence->getStartDate() : $monday;
            $end = $absence->getEndDate() < $sunday ? $absence->getEndDate() : $sunday;
            for ($d = clone $cursor; $d <= $end; $d->add(new DateInterval('P1D'))) {
                $result[] = [
                    'date' => $d->format('Y-m-d'),
                    'type' => $type,
                    'typeName' => $typeName,
                    'status' => $status,
                    'scope' => $absence->getScopeValue(),
                ];
            }
        }
        usort($result, static fn (array $a, array $b) => strcmp($a['date'], $b['date']));
        return $result;
    }

    /**
     * @return array<int, array{date:string,name:string}>
     */
    private function holidayDays(Employee $employee, DateTime $monday, DateTime $sunday): array {
        $result = [];
        foreach ($this->holidayService->findHolidaysInRange($monday, $sunday, $employee->getFederalState()) as $holiday) {
            $result[] = ['date' => $holiday->getDate()->format('Y-m-d'), 'name' => $holiday->getName()];
        }
        return $result;
    }

    /**
     * @throws NotFoundException
     */
    public function find(int $id): DutyJob {
        try {
            return $this->jobMapper->find($id);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Duty job not found');
        }
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException when the week is locked
     */
    public function create(array $data, string $userId): DutyJob {
        $clean = $this->validate($data);
        $this->assertWeekOpen(new DateTime($clean['date']));

        $job = new DutyJob();
        $this->apply($job, $clean);
        $job->setCreatedBy($userId);
        $job->setCreatedAt(new DateTime());
        $job->setUpdatedAt(new DateTime());
        $job = $this->jobMapper->insert($job);

        $this->auditLogService->logCreate($userId, self::ENTITY_TYPE, $job->getId(), $job->jsonSerialize());
        return $job;
    }

    /**
     * Full update; may change employee and date (the form always saves through here).
     *
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ForbiddenException when the old or the new week is locked
     */
    public function update(int $id, array $data, string $userId): DutyJob {
        $job = $this->find($id);
        $old = $job->jsonSerialize();
        $clean = $this->validate($data);
        $this->assertWeekOpen($job->getJobDate());
        $this->assertWeekOpen(new DateTime($clean['date']));

        $this->apply($job, $clean);
        $job->setUpdatedAt(new DateTime());
        $job = $this->jobMapper->update($job);

        $this->auditLogService->logUpdate($userId, self::ENTITY_TYPE, $job->getId(), $old, $job->jsonSerialize());
        return $job;
    }

    /**
     * Drag-and-drop shortcut: only employee and date change; same checks as update.
     *
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ForbiddenException when the old or the new week is locked
     */
    public function move(int $id, int $employeeId, string $date, string $userId): DutyJob {
        $job = $this->find($id);
        $old = $job->jsonSerialize();
        $data = array_merge($old, ['employeeId' => $employeeId, 'date' => $date]);
        $clean = $this->validate($data);

        if ($clean['employeeId'] === $job->getEmployeeId() && $clean['date'] === $old['date']) {
            return $job; // same cell: nothing to do, no audit noise
        }
        $this->assertWeekOpen($job->getJobDate());
        $this->assertWeekOpen(new DateTime($clean['date']));

        $job->setEmployeeId($clean['employeeId']);
        $job->setJobDate(new DateTime($clean['date']));
        $job->setUpdatedAt(new DateTime());
        $job = $this->jobMapper->update($job);

        $this->auditLogService->logUpdate($userId, self::ENTITY_TYPE, $job->getId(), $old, $job->jsonSerialize());
        return $job;
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException when the week is locked
     */
    public function delete(int $id, string $userId): void {
        $job = $this->find($id);
        $this->assertWeekOpen($job->getJobDate());
        $this->auditLogService->logDelete($userId, self::ENTITY_TYPE, $job->getId(), $job->jsonSerialize());
        $this->jobMapper->delete($job);
    }

    /**
     * Copy every card of the source week into the target week (same weekday).
     * Duplicate = same employee, date, start time and title already in target.
     * Only employees currently in the roster are copied. The source week may be
     * locked; the target week must be open.
     *
     * @return int number of cards created
     * @throws ForbiddenException when the target week is locked
     */
    public function copyWeek(DateTime $fromMonday, DateTime $toMonday, string $userId): int {
        $fromMonday = self::mondayOf($fromMonday);
        $toMonday = self::mondayOf($toMonday);
        $offsetDays = (int)$fromMonday->diff($toMonday)->format('%r%a');
        if ($offsetDays === 0) {
            return 0;
        }
        $this->assertWeekOpen($toMonday);
        $rosterIds = array_map(static fn (Employee $e) => $e->getId(), $this->employeeMapper->findAllActiveInDutyRoster());

        $existing = [];
        foreach ($this->jobMapper->findByDateRange($toMonday, (clone $toMonday)->add(new DateInterval('P6D'))) as $job) {
            $existing[$this->dedupeKey($job->getEmployeeId(), $job->getJobDate()->format('Y-m-d'), $job->getStartTime(), $job->getTitle())] = true;
        }

        $now = new DateTime();
        $created = 0;
        foreach ($this->jobMapper->findByDateRange($fromMonday, (clone $fromMonday)->add(new DateInterval('P6D'))) as $source) {
            if (!in_array($source->getEmployeeId(), $rosterIds, true)) {
                continue;
            }
            $target = (clone $source->getJobDate());
            $offsetDays > 0
                ? $target->add(new DateInterval('P' . $offsetDays . 'D'))
                : $target->sub(new DateInterval('P' . abs($offsetDays) . 'D'));
            $targetDate = $target->format('Y-m-d');
            $key = $this->dedupeKey($source->getEmployeeId(), $targetDate, $source->getStartTime(), $source->getTitle());
            if (isset($existing[$key])) {
                continue;
            }
            $copy = new DutyJob();
            $copy->setEmployeeId($source->getEmployeeId());
            $copy->setJobDate(new DateTime($targetDate));
            $copy->setStartTime($source->getStartTime());
            $copy->setDurationMinutes($source->getDurationMinutes());
            $copy->setTitle($source->getTitle());
            $copy->setNote($source->getNote());
            $copy->setOnCall($source->getOnCall());
            $copy->setCreatedBy($userId);
            $copy->setCreatedAt($now);
            $copy->setUpdatedAt($now);
            $this->jobMapper->insert($copy);
            $existing[$key] = true;
            $created++;
        }

        $this->auditLogService->log($userId, 'copy_week', self::ENTITY_TYPE, null, null, [
            'from' => $fromMonday->format('Y-m-d'),
            'to' => $toMonday->format('Y-m-d'),
            'created' => $created,
        ]);
        return $created;
    }

    /**
     * @return string[]
     */
    public function suggestTitles(string $prefix): array {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }
        return $this->jobMapper->findDistinctTitles($prefix, self::SUGGEST_LIMIT);
    }

    private function dedupeKey(int $employeeId, string $date, ?string $time, string $title): string {
        return $employeeId . '|' . $date . '|' . ($time ?? '') . '|' . mb_strtolower(trim($title));
    }

    private function apply(DutyJob $job, array $clean): void {
        $job->setEmployeeId($clean['employeeId']);
        $job->setJobDate(new DateTime($clean['date']));
        $job->setStartTime($clean['startTime']);
        $job->setDurationMinutes($clean['durationMinutes']);
        $job->setTitle($clean['title']);
        $job->setNote($clean['note']);
        $job->setOnCall($clean['onCall']);
    }

    /**
     * Validates and normalizes the payload. Returns the clean array or throws.
     *
     * @throws ValidationException
     */
    private function validate(array $data): array {
        $errors = [];

        $employeeId = (int)($data['employeeId'] ?? 0);
        $employee = null;
        if ($employeeId <= 0) {
            $errors['employeeId'] = [$this->l->t('Mitarbeiter ist erforderlich')];
        } else {
            try {
                $employee = $this->employeeMapper->find($employeeId);
            } catch (DoesNotExistException) {
                $employee = null;
            }
            if ($employee === null || !$employee->getIsActive() || !$employee->getInDutyRoster()) {
                $errors['employeeId'] = [$this->l->t('Mitarbeiter ist nicht im Dienstplan')];
            }
        }

        $date = (string)($data['date'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $errors['date'] = [$this->l->t('Ungültiges Datum')];
        }

        $startTime = $data['startTime'] ?? null;
        $startTime = is_string($startTime) && trim($startTime) !== '' ? trim($startTime) : null;
        if ($startTime !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            $errors['startTime'] = [$this->l->t('Ungültige Uhrzeit (HH:MM)')];
        }

        $duration = $data['durationMinutes'] ?? null;
        $duration = ($duration === null || $duration === '') ? null : (int)$duration;
        if ($duration !== null && ($duration < 1 || $duration > 1440)) {
            $errors['durationMinutes'] = [$this->l->t('Dauer muss zwischen 1 und 1440 Minuten liegen')];
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = [$this->l->t('Titel ist erforderlich')];
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = [$this->l->t('Titel darf höchstens %d Zeichen haben', [self::TITLE_MAX])];
        }

        $note = $data['note'] ?? null;
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            $errors['note'] = [$this->l->t('Notiz darf höchstens %d Zeichen haben', [self::NOTE_MAX])];
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return [
            'employeeId' => $employeeId,
            'date' => $date,
            'startTime' => $startTime,
            'durationMinutes' => $duration,
            'title' => $title,
            'note' => $note,
            'onCall' => (bool)($data['onCall'] ?? false),
        ];
    }
}
