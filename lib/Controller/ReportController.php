<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Controller;

use DateTime;
use OCA\Zeitwerk\Db\AbsenceMapper;
use OCA\Zeitwerk\Db\DailyKmMapper;
use OCA\Zeitwerk\Db\Employee;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\TimeEntryMapper;
use OCA\Zeitwerk\Service\AbsenceService;
use OCA\Zeitwerk\Service\AllowanceService;
use OCA\Zeitwerk\Service\EmployeeService;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\OvertimeCalculationService;
use OCA\Zeitwerk\Service\OvertimePayoutService;
use OCA\Zeitwerk\Service\PdfService;
use OCA\Zeitwerk\Service\PermissionService;
use OCA\Zeitwerk\Service\ProjectService;
use OCA\Zeitwerk\Service\TimeEntryService;
use OCA\Zeitwerk\Service\WorkScheduleService;
use OCA\Zeitwerk\Service\YearlyCarryoverService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

class ReportController extends BaseController {

    /** @var array<string, array> Holiday cache by "state-year-month" */
    private array $holidayCache = [];

    public function __construct(
        IRequest $request,
        ?string $userId,
        private TimeEntryService $timeEntryService,
        private TimeEntryMapper $timeEntryMapper,
        private AbsenceMapper $absenceMapper,
        private AbsenceService $absenceService,
        private EmployeeService $employeeService,
        private HolidayService $holidayService,
        private PermissionService $permissionService,
        private PdfService $pdfService,
        private WorkScheduleService $workScheduleService,
        private YearlyCarryoverService $carryoverService,
        private OvertimePayoutService $payoutService,
        private OvertimeCalculationService $overtimeCalc,
        private ProjectService $projectService,
        private AllowanceService $allowanceService,
        private DailyKmMapper $dailyKmMapper,
        private IL10N $l,
    ) {
        parent::__construct($request, $userId);
    }

    private function getHolidaysCached(int $year, int $month, string $federalState): array {
        $key = "$federalState-$year-$month";
        if (!isset($this->holidayCache[$key])) {
            $this->holidayCache[$key] = $this->holidayService->findByMonth($year, $month, $federalState);
        }
        return $this->holidayCache[$key];
    }

    #[NoAdminRequired]
    public function monthly(?int $employeeId = null, int $year = 0, int $month = 0): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if ($error = $this->requireEmployeeId($employeeId)) {
            return $error;
        }

        if (!$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        try {
            $employee = $this->employeeService->find($employeeId);
            $timeEntries = $this->timeEntryService->findByEmployeeAndMonth($employeeId, $year, $month);
            $absences = $this->absenceService->findByEmployeeAndMonth($employeeId, $year, $month);
            $holidays = $this->holidayService->findByMonth($year, $month, $employee->getFederalState());

            // Calculate statistics
            $stats = $this->overtimeCalc->getMonthlyStats($employee, $year, $month, $timeEntries, $absences, $holidays);

            // Per-day labor-law warnings (#338): minimum break (§4 ArbZG) and max
            // daily hours, evaluated across all entries of each day.
            $dayWarnings = $this->buildDayWarnings($timeEntries, $absences);

            $allowance = $this->allowanceService->getMonthlySummary($employeeId, $year, $month, $timeEntries, $absences);

            return $this->successResponse([
                'employee' => $employee,
                'year' => $year,
                'month' => $month,
                'timeEntries' => $timeEntries,
                'absences' => $absences,
                'holidays' => $holidays,
                'statistics' => $stats,
                'dayWarnings' => $dayWarnings,
                'allowance' => $allowance,
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Group the month's time entries by date and evaluate the day-level
     * labor-law warnings (#338). Only days that actually have a warning are
     * included, keyed by their 'Y-m-d' date.
     *
     * @param \OCA\Zeitwerk\Db\TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @return array<string, string[]>
     */
    private function buildDayWarnings(array $timeEntries, array $absences = []): array {
        $entriesByDate = [];
        foreach ($timeEntries as $entry) {
            $date = $entry->getDate();
            if (!$date) {
                continue;
            }
            $entriesByDate[$date->format('Y-m-d')][] = $entry;
        }

        $dayWarnings = [];
        foreach ($entriesByDate as $date => $entries) {
            $warnings = $this->timeEntryService->dayWarnings($entries);

            // #360: a half-day absence may coexist with time entries (it is not
            // hard-blocked like a full-day absence), but the combination is worth
            // flagging. Surfaced symmetrically here regardless of which side was
            // created first.
            $halfDayAbsence = $this->halfDayAbsenceOnDate($absences, $date);
            if ($halfDayAbsence !== null) {
                $warnings[] = $this->l->t(
                    'Halbtägige Abwesenheit (%s) und Zeiteintrag am selben Tag.',
                    [$halfDayAbsence->getTypeName()]
                );
            }

            if (!empty($warnings)) {
                $dayWarnings[$date] = $warnings;
            }
        }

        return $dayWarnings;
    }

    /**
     * #360: find a non-cancelled half-day absence that covers the given date, if
     * any. Used to flag days that have both a half-day absence and time entries.
     *
     * @param Absence[] $absences
     */
    private function halfDayAbsenceOnDate(array $absences, string $date): ?Absence {
        $dateObj = new DateTime($date);
        foreach ($absences as $absence) {
            if ($absence->getStatus() === Absence::STATUS_CANCELLED) {
                continue;
            }
            if (!$absence->isHalfDay()) {
                continue;
            }
            if ($absence->getStartDate() <= $dateObj && $absence->getEndDate() >= $dateObj) {
                return $absence;
            }
        }

        return null;
    }

    /**
     * Project evaluation (#57): work minutes grouped by project and employee
     * over a month/quarter/year, for Admin/HR.
     */
    #[NoAdminRequired]
    public function projects(int $year = 0, int $month = 0, string $period = 'month', bool $billableOnly = false): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }

        try {
            [$start, $end, $label] = $this->resolvePeriod($year, $month, $period);

            $aggregates = $this->timeEntryMapper->sumWorkMinutesGroupedByProjectAndEmployee($start, $end);

            // Lookup maps for project metadata and employee names.
            $projects = [];
            foreach ($this->projectService->findAll() as $p) {
                $projects[$p->getId()] = $p;
            }
            $employees = [];
            foreach ($this->employeeService->findAll() as $e) {
                $employees[$e->getId()] = $e;
            }

            $rows = [];
            $totalMinutes = 0;
            $billableMinutes = 0;
            $projectIds = [];
            $employeeIds = [];

            foreach ($aggregates as $agg) {
                $projectId = $agg['projectId'];
                $project = $projects[$projectId] ?? null;
                $isBillable = $project !== null && (bool)$project->getIsBillable();

                if ($billableOnly && !$isBillable) {
                    continue;
                }

                $employee = $employees[$agg['employeeId']] ?? null;
                $minutes = $agg['minutes'];

                $rows[] = [
                    'projectId' => $projectId,
                    'projectName' => $project?->getName(),
                    'projectCode' => $project?->getCode(),
                    'customer' => $project?->getCustomer(),
                    'color' => $project?->getColor(),
                    'isBillable' => $isBillable,
                    'employeeId' => $agg['employeeId'],
                    'employeeName' => $employee?->getFullName(),
                    'minutes' => $minutes,
                ];

                $totalMinutes += $minutes;
                if ($isBillable) {
                    $billableMinutes += $minutes;
                }
                if ($projectId > 0) {
                    $projectIds[$projectId] = true;
                }
                $employeeIds[$agg['employeeId']] = true;
            }

            $allowanceByEmployee = $this->collectAllowanceByEmployee($start, $end, $employees);

            return $this->successResponse([
                'period' => [
                    'year' => $year,
                    'month' => $month,
                    'type' => $period,
                    'label' => $label,
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ],
                'totals' => [
                    'totalMinutes' => $totalMinutes,
                    'billableMinutes' => $billableMinutes,
                    'projectCount' => count($projectIds),
                    'employeeCount' => count($employeeIds),
                ],
                'rows' => $rows,
                'allowanceByEmployee' => array_values($allowanceByEmployee),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Individual bookings for the period (#57 detail / customer proof).
     */
    #[NoAdminRequired]
    public function projectEntries(int $year = 0, int $month = 0, string $period = 'month', bool $billableOnly = false): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            [, , $label, $entries, $totals] = $this->collectProjectEntries($year, $month, $period, $billableOnly);
            return $this->successResponse([
                'period' => ['label' => $label],
                'totals' => $totals,
                'entries' => $entries,
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function projectsCsv(int $year = 0, int $month = 0, string $period = 'month', bool $billableOnly = false, string $projectIds = '', string $employeeIds = '', string $mode = 'detail'): DataDownloadResponse|JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            $eIds = $this->parseIds($employeeIds);
            [, , $label, $entries, $totals, , , $allowanceByEmployee] = $this->collectProjectEntries($year, $month, $period, $billableOnly, $this->parseIds($projectIds), $eIds);

            if ($mode === 'agg') {
                $total = max(1, $totals['totalMinutes']);
                $lines = [implode(';', array_map([$this, 'csvCell'], ['Mitarbeiter', 'Stunden', 'Anteil']))];
                foreach ($this->aggregateByEmployee($entries) as $row) {
                    $lines[] = implode(';', array_map([$this, 'csvCell'], [
                        $row['name'],
                        $this->minutesToDecimal($row['minutes']),
                        round($row['minutes'] / $total * 100) . ' %',
                    ]));
                }
            } else {
                $headers = ['Datum', 'Projekt', 'Projektcode', 'Mitarbeiter', 'Stunden', 'Kilometergeld (EUR)', 'Spesen (EUR)', 'Tätigkeit'];
                $lines = [implode(';', array_map([$this, 'csvCell'], $headers))];
                foreach ($entries as $entry) {
                    $lines[] = implode(';', array_map([$this, 'csvCell'], [
                        (new DateTime($entry['date']))->format('d.m.Y'),
                        $entry['projectName'] ?? 'Kein Projekt',
                        $entry['projectCode'] ?? '',
                        $entry['employeeName'] ?? '',
                        $this->minutesToDecimal($entry['minutes']),
                        $entry['mileageAmount'] > 0 ? number_format($entry['mileageAmount'], 2, ',', '') : '',
                        $entry['allowanceAmount'] > 0 ? number_format($entry['allowanceAmount'], 2, ',', '') : '',
                        $entry['description'] ?? '',
                    ]));
                }
            }

            // Spesen & Kilometer je Mitarbeiter als eigener Block (tages-, nicht
            // projektgebunden — gilt für den gesamten Zeitraum).
            $allowanceRows = $this->allowanceExportRows($allowanceByEmployee, $eIds);
            if (!empty($allowanceRows)) {
                $lines[] = '';
                $lines[] = $this->csvCell('Spesen & Kilometer je Mitarbeiter (gesamter Zeitraum, unabhängig von der Projektauswahl)');
                $lines[] = implode(';', array_map([$this, 'csvCell'], ['Mitarbeiter', 'Spesen-Tage', 'Spesen (EUR)', 'Kilometer', 'Kilometergeld (EUR)', 'Summe (EUR)']));
                $totalRow = ['allowanceDays' => 0, 'allowanceAmount' => 0.0, 'kilometers' => 0, 'mileageAmount' => 0.0, 'total' => 0.0];
                foreach ($allowanceRows as $row) {
                    $lines[] = implode(';', array_map([$this, 'csvCell'], [
                        $row['name'],
                        (string)$row['allowanceDays'],
                        number_format($row['allowanceAmount'], 2, ',', ''),
                        (string)$row['kilometers'],
                        number_format($row['mileageAmount'], 2, ',', ''),
                        number_format($row['total'], 2, ',', ''),
                    ]));
                    foreach ($totalRow as $key => $value) {
                        $totalRow[$key] += $row[$key];
                    }
                }
                $lines[] = implode(';', array_map([$this, 'csvCell'], [
                    'Gesamt',
                    (string)$totalRow['allowanceDays'],
                    number_format($totalRow['allowanceAmount'], 2, ',', ''),
                    (string)$totalRow['kilometers'],
                    number_format($totalRow['mileageAmount'], 2, ',', ''),
                    number_format($totalRow['total'], 2, ',', ''),
                ]));
            }

            // UTF-8 BOM so Excel detects the encoding.
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";

            return new DataDownloadResponse($csv, $this->exportFilename($label) . '.csv', 'text/csv; charset=UTF-8');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function projectsPdf(int $year = 0, int $month = 0, string $period = 'month', bool $billableOnly = false, string $projectIds = '', string $employeeIds = '', string $mode = 'detail'): DataDownloadResponse|JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            $pIds = $this->parseIds($projectIds);
            $eIds = $this->parseIds($employeeIds);
            [, , $label, $entries, $totals, $projects, $employees, $allowanceByEmployee] = $this->collectProjectEntries($year, $month, $period, $billableOnly, $pIds, $eIds);
            $filter = $this->selectionLabels($pIds, $eIds, $projects, $employees);
            $allowanceRows = $this->allowanceExportRows($allowanceByEmployee, $eIds);
            $pdf = $mode === 'agg'
                ? $this->pdfService->generateProjectAggregate($label, $this->aggregateByEmployee($entries), $totals['totalMinutes'], $filter, $allowanceRows)
                : $this->pdfService->generateProjectEvaluation($label, $entries, $totals, $filter, $allowanceRows);
            return new DataDownloadResponse($pdf, $this->exportFilename($label) . '.pdf', 'application/pdf');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Collect individual bookings for the period, enriched with project and
     * employee metadata. Shared by the JSON, CSV and PDF endpoints.
     *
     * Kilometergeld und Spesen sind tages-, nicht buchungsgebunden: sie werden
     * an der ERSTEN (nach Filterung enthaltenen) Buchung des Tages eines
     * Mitarbeiters ausgewiesen, damit Spaltensummen nichts doppelt zählen.
     * km/Spesen an Tagen ganz ohne Buchung (z.B. externer Abwesenheitstag)
     * erscheinen nur im Block «Spesen & Kilometer je Mitarbeiter».
     *
     * @param int[] $projectIds optional filter (empty = all)
     * @param int[] $employeeIds optional filter (empty = all)
     * @return array{0: DateTime, 1: DateTime, 2: string, 3: array, 4: array{totalMinutes: int, billableMinutes: int}, 5: array<int, \OCA\Zeitwerk\Db\Project>, 6: array<int, \OCA\Zeitwerk\Db\Employee>, 7: array<int, array>}
     */
    private function collectProjectEntries(int $year, int $month, string $period, bool $billableOnly, array $projectIds = [], array $employeeIds = []): array {
        [$start, $end, $label] = $this->resolvePeriod($year, $month, $period);

        $projectFilter = array_flip($projectIds);
        $employeeFilter = array_flip($employeeIds);

        $projects = [];
        foreach ($this->projectService->findAll() as $p) {
            $projects[$p->getId()] = $p;
        }
        $employees = [];
        foreach ($this->employeeService->findAll() as $e) {
            $employees[$e->getId()] = $e;
        }

        $allowanceByEmployee = $this->collectAllowanceByEmployee($start, $end, $employees);

        $entries = [];
        $totalMinutes = 0;
        $billableMinutes = 0;
        $seenEmployeeDays = [];
        foreach ($this->timeEntryMapper->findByDateRange($start, $end) as $te) {
            $projectId = (int)($te->getProjectId() ?? 0);
            $project = $projects[$projectId] ?? null;
            $isBillable = $project !== null && (bool)$project->getIsBillable();
            if ($billableOnly && !$isBillable) {
                continue;
            }
            if (!empty($projectFilter) && !isset($projectFilter[$projectId])) {
                continue;
            }
            if (!empty($employeeFilter) && !isset($employeeFilter[$te->getEmployeeId()])) {
                continue;
            }
            $employee = $employees[$te->getEmployeeId()] ?? null;
            $minutes = (int)$te->getWorkMinutes();
            $dateStr = $te->getDate()->format('Y-m-d');

            // km/Spesen des Tages nur auf der ersten enthaltenen Buchung.
            $mileageAmount = 0.0;
            $allowanceAmount = 0.0;
            $dayKey = $te->getEmployeeId() . '|' . $dateStr;
            if (!isset($seenEmployeeDays[$dayKey])) {
                $seenEmployeeDays[$dayKey] = true;
                $summary = $allowanceByEmployee[$te->getEmployeeId()] ?? null;
                if ($summary !== null) {
                    $dayKm = $summary['kilometersByDate'][$dateStr] ?? 0;
                    $mileageAmount = round($dayKm * (float)($summary['mileageRate'] ?? 0), 2);
                    if (in_array($dateStr, $summary['allowanceDates'] ?? [], true)) {
                        $allowanceAmount = (float)($summary['allowancePerDay'] ?? 0);
                    }
                }
            }

            $entries[] = [
                'id' => $te->getId(),
                'date' => $dateStr,
                'projectId' => $projectId,
                'projectName' => $project?->getName(),
                'projectCode' => $project?->getCode(),
                'customer' => $project?->getCustomer(),
                'color' => $project?->getColor(),
                'isBillable' => $isBillable,
                'employeeId' => $te->getEmployeeId(),
                'employeeName' => $employee?->getFullName(),
                'minutes' => $minutes,
                'description' => $te->getDescription(),
                'mileageAmount' => $mileageAmount,
                'allowanceAmount' => $allowanceAmount,
            ];
            $totalMinutes += $minutes;
            if ($isBillable) {
                $billableMinutes += $minutes;
            }
        }

        return [$start, $end, $label, $entries, ['totalMinutes' => $totalMinutes, 'billableMinutes' => $billableMinutes], $projects, $employees, $allowanceByEmployee];
    }

    /**
     * Spesen/Kilometer je Mitarbeiter über den Zeitraum (projektunabhängig,
     * da Spesen/km tages- und nicht projektgebunden sind). Drei Batch-Queries
     * (Zeiteinträge, Abwesenheiten, km) statt N pro Mitarbeiter; die reine
     * Berechnung übernimmt AllowanceService::calculate(). Nur Mitarbeiter mit
     * Spesen-Tagen oder Kilometern werden zurückgegeben.
     *
     * @param array<int, Employee> $employees id-keyed map (für die Namen)
     * @return array<int, array> employeeId => allowance summary + employeeId/employeeName
     */
    private function collectAllowanceByEmployee(DateTime $start, DateTime $end, array $employees): array {
        $entriesByEmployee = [];
        foreach ($this->timeEntryMapper->findByDateRange($start, $end) as $entry) {
            $entriesByEmployee[$entry->getEmployeeId()][] = $entry;
        }

        $absencesByEmployee = [];
        foreach ($this->absenceMapper->findByDateRange($start, $end) as $absence) {
            $absencesByEmployee[$absence->getEmployeeId()][] = $absence;
        }

        $kmByEmployee = [];
        foreach ($this->dailyKmMapper->findByDateRange($start, $end) as $record) {
            $kmByEmployee[$record->getEmployeeId()][] = $record;
        }

        $employeeIds = array_unique(array_merge(
            array_keys($entriesByEmployee),
            array_keys($absencesByEmployee),
            array_keys($kmByEmployee)
        ));

        $result = [];
        foreach ($employeeIds as $employeeId) {
            $summary = $this->allowanceService->calculate(
                $entriesByEmployee[$employeeId] ?? [],
                $absencesByEmployee[$employeeId] ?? [],
                $kmByEmployee[$employeeId] ?? [],
                $start,
                $end
            );
            if (($summary['allowanceDays'] ?? 0) <= 0 && ($summary['kilometers'] ?? 0) <= 0) {
                continue;
            }
            $result[$employeeId] = array_merge($summary, [
                'employeeId' => $employeeId,
                'employeeName' => isset($employees[$employeeId]) ? $employees[$employeeId]->getFullName() : null,
            ]);
        }

        return $result;
    }

    /**
     * Allowance-Zeilen für die Exporte (CSV/PDF), optional auf die gewählten
     * Mitarbeiter eingeschränkt und nach Name sortiert.
     *
     * @param array<int, array> $allowanceByEmployee
     * @param int[] $employeeIds Filter (leer = alle)
     * @return array<array{name: string, allowanceDays: int, allowanceAmount: float, kilometers: int, mileageAmount: float, total: float}>
     */
    private function allowanceExportRows(array $allowanceByEmployee, array $employeeIds): array {
        $filter = array_flip($employeeIds);
        $rows = [];
        foreach ($allowanceByEmployee as $employeeId => $summary) {
            if (!empty($filter) && !isset($filter[$employeeId])) {
                continue;
            }
            $rows[] = [
                'name' => $summary['employeeName'] ?? 'Unbekannt',
                'allowanceDays' => $summary['allowanceDays'],
                'allowanceAmount' => $summary['allowanceAmount'],
                'kilometers' => $summary['kilometers'],
                'mileageAmount' => $summary['mileageAmount'],
                'total' => $summary['total'],
            ];
        }
        usort($rows, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $rows;
    }

    /**
     * Parse a comma-separated list of positive integer IDs from a query param.
     *
     * @return int[]
     */
    private function parseIds(string $raw): array {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id) => $id > 0));
    }

    /**
     * Aggregate enriched entries to hours per employee, sorted by hours desc.
     *
     * @param array $entries
     * @return array<array{name: string, minutes: int}>
     */
    private function aggregateByEmployee(array $entries): array {
        $byEmp = [];
        foreach ($entries as $e) {
            $id = $e['employeeId'];
            if (!isset($byEmp[$id])) {
                $byEmp[$id] = ['name' => $e['employeeName'] ?? 'Unbekannt', 'minutes' => 0];
            }
            $byEmp[$id]['minutes'] += $e['minutes'];
        }
        $rows = array_values($byEmp);
        usort($rows, static fn ($a, $b) => $b['minutes'] - $a['minutes']);
        return $rows;
    }

    /**
     * Human-readable labels for the current selection, for the export header.
     * Empty id list => "Alle". The project/employee maps are reused from
     * collectProjectEntries() to avoid a second findAll() round-trip (#311).
     *
     * @param int[] $projectIds
     * @param int[] $employeeIds
     * @param array<int, \OCA\Zeitwerk\Db\Project> $projects id-keyed map
     * @param array<int, \OCA\Zeitwerk\Db\Employee> $employees id-keyed map
     * @return array{projects: string, employees: string}
     */
    private function selectionLabels(array $projectIds, array $employeeIds, array $projects, array $employees): array {
        $projectsLabel = 'Alle';
        if (!empty($projectIds)) {
            $names = [];
            foreach ($projectIds as $id) {
                if (isset($projects[$id])) {
                    $names[] = $projects[$id]->getName();
                }
            }
            $projectsLabel = implode(', ', $names);
        }

        $employeesLabel = 'Alle';
        if (!empty($employeeIds)) {
            $names = [];
            foreach ($employeeIds as $id) {
                if (isset($employees[$id])) {
                    $names[] = $employees[$id]->getFullName();
                }
            }
            $employeesLabel = implode(', ', $names);
        }

        return ['projects' => $projectsLabel, 'employees' => $employeesLabel];
    }

    private function csvCell(string $value): string {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function minutesToDecimal(int $minutes): string {
        return number_format($minutes / 60, 2, ',', '');
    }

    private function exportFilename(string $label): string {
        return 'Projektauswertung_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $label);
    }

    /**
     * Resolve the date range and a human label for a period selection.
     *
     * @return array{0: DateTime, 1: DateTime, 2: string}
     */
    private function resolvePeriod(int $year, int $month, string $period): array {
        if ($period === 'year') {
            $start = new DateTime("$year-01-01");
            $end = new DateTime("$year-12-31");
            return [$start, $end, (string)$year];
        }

        if ($period === 'quarter') {
            $quarter = intdiv(max(1, min(12, $month)) - 1, 3); // 0..3
            $startMonth = $quarter * 3 + 1;
            $start = new DateTime(sprintf('%d-%02d-01', $year, $startMonth));
            $end = (clone $start)->modify('+2 months')->modify('last day of this month');
            return [$start, $end, sprintf('Q%d %d', $quarter + 1, $year)];
        }

        // default: month
        $m = max(1, min(12, $month));
        $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $start = new DateTime(sprintf('%d-%02d-01', $year, $m));
        $end = (clone $start)->modify('last day of this month');
        return [$start, $end, $months[$m] . ' ' . $year];
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function pdf(?int $employeeId = null, int $year = 0, int $month = 0): DataDownloadResponse|JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if ($error = $this->requireEmployeeId($employeeId)) {
            return $error;
        }

        if (!$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        try {
            $employee = $this->employeeService->find($employeeId);
            $timeEntries = $this->timeEntryService->findByEmployeeAndMonth($employeeId, $year, $month);
            $absences = $this->absenceService->findByEmployeeAndMonth($employeeId, $year, $month);
            $holidays = $this->holidayService->findByMonth($year, $month, $employee->getFederalState());

            $stats = $this->overtimeCalc->getMonthlyStats($employee, $year, $month, $timeEntries, $absences, $holidays);
            $allowance = $this->allowanceService->getMonthlySummary($employeeId, $year, $month, $timeEntries, $absences);

            $pdfContent = $this->pdfService->generateMonthlyReport(
                $employee,
                $year,
                $month,
                $timeEntries,
                $absences,
                $holidays,
                $stats,
                null,
                $allowance
            );

            $filename = sprintf(
                'Arbeitszeitnachweis_%s_%s_%d-%02d.pdf',
                $employee->getLastName(),
                $employee->getFirstName(),
                $year,
                $month
            );

            return new DataDownloadResponse($pdfContent, $filename, 'application/pdf');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Arbeitszeitnachweis-PDF over a custom inclusive [startDate, endDate] range
     * (#102), e.g. the 20th of one month to the 20th of the next.
     */
    #[NoAdminRequired]
    public function pdfRange(?int $employeeId = null, string $startDate = '', string $endDate = ''): DataDownloadResponse|JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if ($error = $this->requireEmployeeId($employeeId)) {
            return $error;
        }

        if (!$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        if ($startDate === '' || $endDate === '') {
            return $this->successResponse(['error' => 'Start- und Enddatum sind erforderlich'], 400);
        }

        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            // Tolerate a reversed range by normalising the order.
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $employee = $this->employeeService->find($employeeId);
            $timeEntries = $this->timeEntryService->findByEmployeeAndDateRange($employeeId, $start, $end);
            $absences = $this->absenceService->findByEmployeeAndDateRange($employeeId, $start, $end);
            $holidays = $this->holidayService->findHolidaysInRange($start, $end, $employee->getFederalState());

            $stats = $this->overtimeCalc->getRangeStats($employee, $start, $end, $timeEntries, $absences, $holidays);
            $allowance = $this->allowanceService->getRangeSummary($employeeId, $start, $end, $timeEntries, $absences);

            $pdfContent = $this->pdfService->generateRangeReport(
                $employee,
                $start,
                $end,
                $timeEntries,
                $absences,
                $holidays,
                $stats,
                null,
                $allowance
            );

            $filename = sprintf(
                'Arbeitszeitnachweis_%s_%s_%s_bis_%s.pdf',
                $employee->getLastName(),
                $employee->getFirstName(),
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            );

            return new DataDownloadResponse($pdfContent, $filename, 'application/pdf');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function team(int $year, int $month): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        $teamMembers = $this->permissionService->getTeamMembers($this->userId);

        if (empty($teamMembers)) {
            return $this->successResponse([]);
        }

        // Batch-load all data in 3 queries instead of 4×N
        $employeeIds = array_map(fn(Employee $e) => $e->getId(), $teamMembers);
        $allTimeEntries = $this->timeEntryMapper->findByEmployeeIdsAndMonth($employeeIds, $year, $month);
        $allAbsences = $this->absenceMapper->findByEmployeeIdsAndMonth($employeeIds, $year, $month);
        $allStatusSummaries = $this->timeEntryMapper->getMonthlyStatusSummaryBatch($employeeIds, $year, $month);

        $report = [];

        foreach ($teamMembers as $employee) {
            $empId = $employee->getId();
            $timeEntries = $allTimeEntries[$empId] ?? [];
            $absences = $allAbsences[$empId] ?? [];
            $holidays = $this->getHolidaysCached($year, $month, $employee->getFederalState());

            $stats = $this->overtimeCalc->getMonthlyStats($employee, $year, $month, $timeEntries, $absences, $holidays);
            $statusSummary = $allStatusSummaries[$empId] ?? ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];

            $report[] = [
                'employee' => $employee,
                'statistics' => $stats,
                'allowance' => $this->allowanceService->getMonthlySummary($empId, $year, $month, $timeEntries, $absences),
                'monthStatus' => [
                    'draft' => $statusSummary['draft'],
                    'submitted' => $statusSummary['submitted'],
                    'approved' => $statusSummary['approved'],
                    'rejected' => $statusSummary['rejected'],
                    'canApprove' => $statusSummary['submitted'] > 0,
                ],
            ];
        }

        return $this->successResponse($report);
    }

    #[NoAdminRequired]
    public function teamYear(int $year): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        // Sicht (#347): rekursiv ueber den ganzen Unterbaum. Genehmigen bleibt
        // direkt (siehe Genehmigungen/pendingMonths).
        $teamMembers = $this->permissionService->getVisibleTeamMembers($this->userId);

        if (empty($teamMembers)) {
            return $this->successResponse([]);
        }

        // Batch-load all year data: 2 queries for all employees
        $employeeIds = array_map(fn(Employee $e) => $e->getId(), $teamMembers);
        $allTimeEntries = $this->timeEntryMapper->findByEmployeeIdsAndYear($employeeIds, $year);
        $allAbsences = $this->absenceMapper->findByEmployeeIdsAndYear($employeeIds, $year);

        // km-Datensätze des Jahres für die Spesen-/Kilometer-Spalte (eine Query)
        $yearStart = new DateTime("$year-01-01");
        $yearEnd = new DateTime("$year-12-31");
        $kmByEmployee = [];
        foreach ($this->dailyKmMapper->findByDateRange($yearStart, $yearEnd) as $record) {
            $kmByEmployee[$record->getEmployeeId()][] = $record;
        }

        // Batch-load status summaries for all months (12 queries total, not 12×N)
        $allStatusByMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $allStatusByMonth[$m] = $this->timeEntryMapper->getMonthlyStatusSummaryBatch($employeeIds, $year, $m);
        }

        $report = [];

        foreach ($teamMembers as $employee) {
            $empId = $employee->getId();
            $empTimeEntries = $allTimeEntries[$empId] ?? [];
            $empAbsences = $allAbsences[$empId] ?? [];

            // Split time entries by month in-memory
            $entriesByMonth = [];
            foreach ($empTimeEntries as $entry) {
                $entryMonth = (int)$entry->getDate()->format('n');
                $entriesByMonth[$entryMonth][] = $entry;
            }

            // Split absences by month in-memory (an absence can span multiple months)
            $absencesByMonth = [];
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = new DateTime("$year-$month-01");
                $monthEnd = (clone $monthStart)->modify('last day of this month');
                $absencesByMonth[$month] = [];
                foreach ($empAbsences as $absence) {
                    if ($absence->getStartDate() <= $monthEnd && $absence->getEndDate() >= $monthStart) {
                        $absencesByMonth[$month][] = $absence;
                    }
                }
            }

            $months = [];
            $totalOvertimeMinutes = 0;

            for ($month = 1; $month <= 12; $month++) {
                $startDate = new DateTime("$year-$month-01");

                // Future months: only load vacation, skip time entries
                if ($startDate > new DateTime()) {
                    $futureAbsences = $absencesByMonth[$month];
                    $futureVacationDays = 0;
                    foreach ($futureAbsences as $absence) {
                        if ($absence->countsAsVacation() && $absence->getStatus() === Absence::STATUS_APPROVED) {
                            $futureVacationDays += $this->countWorkingDaysInMonth($absence, $year, $month);
                        }
                    }
                    $months[] = [
                        'month' => $month,
                        'overtimeMinutes' => null,
                        'vacationDays' => $futureVacationDays > 0 ? $futureVacationDays : null,
                        'status' => null,
                        'canApprove' => false,
                    ];
                    continue;
                }

                $timeEntries = $entriesByMonth[$month] ?? [];
                $absences = $absencesByMonth[$month];
                $holidays = $this->getHolidaysCached($year, $month, $employee->getFederalState());

                $stats = $this->overtimeCalc->getMonthlyStats($employee, $year, $month, $timeEntries, $absences, $holidays);
                $statusSummary = $allStatusByMonth[$month][$empId] ?? ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];

                // Determine dominant status
                $status = 'draft';
                if ($statusSummary['approved'] > 0 && $statusSummary['submitted'] === 0 && $statusSummary['draft'] === 0 && $statusSummary['rejected'] === 0) {
                    $status = 'approved';
                } elseif ($statusSummary['submitted'] > 0) {
                    $status = 'submitted';
                } elseif ($statusSummary['rejected'] > 0) {
                    $status = 'rejected';
                }

                // Count vacation days in this month
                $vacationDays = 0;
                foreach ($absences as $absence) {
                    if ($absence->countsAsVacation() && $absence->getStatus() === Absence::STATUS_APPROVED) {
                        $vacationDays += $this->countWorkingDaysInMonth($absence, $year, $month);
                    }
                }

                $months[] = [
                    'month' => $month,
                    'overtimeMinutes' => $stats['overtimeMinutes'],
                    'vacationDays' => $vacationDays,
                    'status' => $status,
                    'canApprove' => $statusSummary['submitted'] > 0,
                ];

                $totalOvertimeMinutes += $stats['overtimeMinutes'];
            }

            // Vacation stats - use schedule-aware vacation days + carryover
            $vacationDaysForYear = $this->workScheduleService->getVacationDaysForYear($empId, $year);
            $vacationCarryover = $this->carryoverService->getVacationCarryoverDays($empId, $year);
            $vacationStats = $this->absenceService->getVacationStats(
                $empId,
                $year,
                $vacationDaysForYear + (int)round($vacationCarryover)
            );
            $vacationStats['carryover'] = $vacationCarryover;

            // Overtime carryover
            $overtimeCarryover = $this->carryoverService->getOvertimeCarryoverMinutes($empId, $year);

            // Overtime paid out in money reduces the balance (#401)
            $paidOutMinutes = $this->payoutService->getPaidOutMinutes($empId, $year);

            $report[] = [
                'employee' => [
                    'id' => $empId,
                    'userId' => $employee->getUserId(),
                    'fullName' => $employee->getFullName(),
                    'weeklyHours' => $employee->getWeeklyHours(),
                ],
                'vacationStats' => $vacationStats,
                'months' => $months,
                'allowance' => $this->allowanceService->calculate($empTimeEntries, $empAbsences, $kmByEmployee[$empId] ?? [], $yearStart, $yearEnd),
                'carryoverMinutes' => $overtimeCarryover,
                'paidOutMinutes' => $paidOutMinutes,
                'totalOvertimeMinutes' => $totalOvertimeMinutes + $overtimeCarryover - $paidOutMinutes,
            ];
        }

        return $this->successResponse($report);
    }

    #[NoAdminRequired]
    public function overtime(?int $employeeId = null, int $year = 0): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if ($error = $this->requireEmployeeId($employeeId)) {
            return $error;
        }

        if (!$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        try {
            $employee = $this->employeeService->find($employeeId);
            $monthlyData = [];
            $totalOvertime = 0;

            for ($month = 1; $month <= 12; $month++) {
                $startDate = new DateTime("$year-$month-01");

                // Skip future months
                if ($startDate > new DateTime()) {
                    break;
                }

                $timeEntries = $this->timeEntryService->findByEmployeeAndMonth($employeeId, $year, $month);
                $absences = $this->absenceService->findByEmployeeAndMonth($employeeId, $year, $month);
                $holidays = $this->holidayService->findByMonth($year, $month, $employee->getFederalState());

                $stats = $this->overtimeCalc->getMonthlyStats($employee, $year, $month, $timeEntries, $absences, $holidays);

                $monthlyData[] = [
                    'month' => $month,
                    'targetMinutes' => $stats['targetMinutes'],
                    'actualMinutes' => $stats['actualMinutes'],
                    'overtimeMinutes' => $stats['overtimeMinutes'],
                ];

                $totalOvertime += $stats['overtimeMinutes'];
            }

            $carryoverMinutes = $this->carryoverService->getOvertimeCarryoverMinutes($employeeId, $year);

            // Overtime paid out in money reduces the balance (#401)
            $paidOutMinutes = $this->payoutService->getPaidOutMinutes($employeeId, $year);
            $netOvertime = $totalOvertime + $carryoverMinutes - $paidOutMinutes;

            return $this->successResponse([
                'employee' => $employee,
                'year' => $year,
                'monthly' => $monthlyData,
                'carryoverMinutes' => $carryoverMinutes,
                'paidOutMinutes' => $paidOutMinutes,
                'totalOvertimeMinutes' => $netOvertime,
                'totalOvertimeHours' => round($netOvertime / 60, 2),
                // Average daily target (weekly hours / 5), used for the Freizeitausgleich ≈ hours hint.
                'dailyMinutes' => (int)round($employee->getWeeklyHours() / 5 * 60),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function allEmployeesStatus(int $year, int $month): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        // Only Admin and HR Manager can see all employees
        if (!$this->permissionService->isAdmin($this->userId) && !$this->permissionService->isHrManager($this->userId)) {
            return $this->forbiddenResponse();
        }

        $allEmployees = $this->employeeService->findAllActive();

        $report = [];

        foreach ($allEmployees as $employee) {
            $statusSummary = $this->timeEntryMapper->getMonthlyStatusSummary($employee->getId(), $year, $month);
            $totalEntries = $statusSummary['draft'] + $statusSummary['submitted'] + $statusSummary['approved'] + $statusSummary['rejected'];

            $report[] = [
                'employee' => $employee,
                'monthStatus' => [
                    'draft' => $statusSummary['draft'],
                    'submitted' => $statusSummary['submitted'],
                    'approved' => $statusSummary['approved'],
                    'rejected' => $statusSummary['rejected'],
                    'total' => $totalEntries,
                    'canApprove' => $statusSummary['submitted'] > 0,
                    'isFullyApproved' => $totalEntries > 0 && $statusSummary['approved'] === $totalEntries,
                ],
            ];
        }

        return $this->successResponse($report);
    }

    /**
     * Calculate monthly statistics
     *
     * Logic:
     * - Display values (workingDays, absenceDays, targetMinutes) = full month
     * - Actual (Ist) = worked hours + credited absence hours up to today
     * - Overtime = Actual - proportional target (based on working days up to today)
     *
     * Absence types:
     * - Paid (vacation, sick, child_sick, special, training):
     *   Credited as work time (added to Ist)
     * - Unpaid: Reduces target (Soll), not credited as work time
     * - Compensatory (Freizeitausgleich): keeps the target and is NOT credited to Ist,
     *   so the overtime balance decreases by one daily target per FZA day
     */

    /**
     * Count working days of an absence that fall within a specific month.
     * Uses schedule-aware logic (only counts days where the employee has >0 hours).
     */
    private function countWorkingDaysInMonth(Absence $absence, int $year, int $month): float {
        $monthStart = new DateTime("$year-$month-01");
        $monthEnd = (clone $monthStart)->modify('last day of this month');

        $absStart = $absence->getStartDate();
        $absEnd = $absence->getEndDate();

        // Clamp to month boundaries
        $start = $absStart > $monthStart ? $absStart : $monthStart;
        $end = $absEnd < $monthEnd ? $absEnd : $monthEnd;

        if ($start > $end) {
            return 0;
        }

        return $this->workScheduleService->countWorkingDays($absence->getEmployeeId(), $start, $end, []);
    }

}
