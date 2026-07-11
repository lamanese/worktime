<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Service;

use DateTime;
use OCA\WorkTime\Db\Absence;
use OCA\WorkTime\Db\CompanySetting;
use OCA\WorkTime\Db\Employee;
use OCA\WorkTime\Db\Holiday;
use OCA\WorkTime\Db\ProjectMapper;
use OCA\WorkTime\Db\TimeEntry;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use TCPDF;

class PdfService {

    private const FONT_FAMILY = 'helvetica';
    private const FONT_SIZE_NORMAL = 10;
    private const FONT_SIZE_SMALL = 8;
    private const FONT_SIZE_TITLE = 14;
    private const FONT_SIZE_HEADER = 12;

    public function __construct(
        private CompanySettingsService $settingsService,
        private IRootFolder $rootFolder,
        private ProjectMapper $projectMapper,
        private WorkScheduleService $workScheduleService,
    ) {
    }

    /**
     * Generate a monthly time report PDF
     *
     * @param Employee $employee
     * @param int $year
     * @param int $month
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @param Holiday[] $holidays
     * @param array $statistics
     * @param array|null $approvalInfo Optional approval info: ['approvedBy' => Employee, 'approvedAt' => DateTime]
     * @return string PDF content
     */
    public function generateMonthlyReport(
        Employee $employee,
        int $year,
        int $month,
        array $timeEntries,
        array $absences,
        array $holidays,
        array $statistics,
        ?array $approvalInfo = null,
        ?array $allowance = null
    ): string {
        $startDate = new DateTime("$year-$month-01");
        $endDate = (clone $startDate)->modify('last day of this month');
        $periodLabel = $this->getGermanMonthName($month) . ' ' . $year;

        return $this->renderReport($employee, $periodLabel, $startDate, $endDate, $timeEntries, $absences, $holidays, $statistics, $approvalInfo, 'Arbeitstage im Monat:', $allowance);
    }

    /**
     * Generate an Arbeitszeitnachweis PDF for an arbitrary inclusive [start, end]
     * range (#102 custom period, e.g. the 20th to the 20th).
     */
    public function generateRangeReport(
        Employee $employee,
        DateTime $startDate,
        DateTime $endDate,
        array $timeEntries,
        array $absences,
        array $holidays,
        array $statistics,
        ?array $approvalInfo = null,
        ?array $allowance = null
    ): string {
        $periodLabel = $startDate->format('d.m.Y') . ' – ' . $endDate->format('d.m.Y');
        return $this->renderReport($employee, $periodLabel, $startDate, $endDate, $timeEntries, $absences, $holidays, $statistics, $approvalInfo, 'Arbeitstage im Zeitraum:', $allowance);
    }

    /**
     * Shared rendering for both the monthly and the custom-period timesheet.
     */
    private function renderReport(
        Employee $employee,
        string $periodLabel,
        DateTime $startDate,
        DateTime $endDate,
        array $timeEntries,
        array $absences,
        array $holidays,
        array $statistics,
        ?array $approvalInfo = null,
        string $workingDaysLabel = 'Arbeitstage im Monat:',
        ?array $allowance = null
    ): string {
        $pdf = $this->createPdf();

        // Header
        $this->addHeader($pdf, $employee, $periodLabel, $startDate);

        // Time entries table (mit km-/Spesen-Spalten, wenn im Zeitraum vorhanden)
        $this->addTimeEntriesTable($pdf, $timeEntries, $absences, $holidays, $startDate, $endDate, $allowance);

        // Absences section
        if (!empty($absences)) {
            $this->addAbsencesSection($pdf, $absences);
        }

        // Summary — beginnt auf einer neuen Seite, damit der Schlussblock
        // (Zusammenfassung, Spesen & km, Unterschriften, Genehmigungsvermerk)
        // zusammenhängend auf einem eigenen Blatt steht.
        $pdf->AddPage();
        $this->addSummary($pdf, $employee, $statistics, $workingDaysLabel);

        // Spesen & Kilometer (nur wenn im Zeitraum etwas angefallen ist)
        if ($allowance !== null && (($allowance['allowanceDays'] ?? 0) > 0 || ($allowance['kilometers'] ?? 0) > 0)) {
            $this->addAllowanceSection($pdf, $allowance);
        }

        // Signature section
        $this->addSignatureSection($pdf);

        // Approval info section (if provided)
        if ($approvalInfo !== null) {
            $this->addApprovalInfoSection($pdf, $approvalInfo);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Generate a project evaluation PDF (#57): individual bookings over a period,
     * usable as a customer proof. Landscape for the wide table.
     *
     * @param string $label Period label (e.g. "06/2026", "Q2 2026", "2026")
     * @param array<array{date: string, projectName: ?string, customer: ?string, employeeName: ?string, minutes: int, description: ?string, isBillable: bool}> $entries
     * @param array{totalMinutes: int, billableMinutes: int} $totals
     * @return string PDF content
     */
    public function generateProjectEvaluation(string $label, array $entries, array $totals, array $filter = [], array $allowanceRows = []): string {
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $companyName = $this->settingsService->getCompanyName() ?: 'Projektauswertung';
        $pdf->SetCreator('WorkTime Nextcloud App');
        $pdf->SetAuthor($companyName);
        $pdf->SetTitle('Projektauswertung');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont([self::FONT_FAMILY, '', self::FONT_SIZE_SMALL]);
        $pdf->setFooterMargin(10);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->AddPage();

        if ($this->settingsService->getCompanyName()) {
            $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_TITLE);
            $pdf->Cell(0, 10, $companyName, 0, 1, 'C');
            $pdf->Ln(1);
        }
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADER);
        $pdf->Cell(0, 8, 'Projektauswertung', 0, 1, 'C');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 6, $label, 0, 1, 'C');
        $this->addFilterContext($pdf, $filter);
        $pdf->Ln(4);

        // Column widths (landscape A4 content width ~267mm). km/Spesen stehen
        // tagesbezogen auf der ersten Buchung des Tages (siehe ReportController).
        $cols = [
            ['Datum', 24, 'L'],
            ['Projekt', 55, 'L'],
            ['Mitarbeiter', 45, 'L'],
            ['Stunden', 20, 'R'],
            ['Kilometergeld', 26, 'R'],
            ['Spesen', 22, 'R'],
            ['Tätigkeit', 75, 'L'],
        ];

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($cols as $col) {
            $pdf->Cell($col[1], 7, $col[0], 1, 0, $col[2], true);
        }
        $pdf->Ln();

        $mileageTotal = 0.0;
        $allowanceTotal = 0.0;
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        foreach ($entries as $entry) {
            $date = (new DateTime($entry['date']))->format('d.m.Y');
            $mileageAmount = (float)($entry['mileageAmount'] ?? 0);
            $allowanceAmount = (float)($entry['allowanceAmount'] ?? 0);
            $mileageTotal += $mileageAmount;
            $allowanceTotal += $allowanceAmount;
            $row = [
                $date,
                $entry['projectName'] ?? 'Kein Projekt',
                $entry['employeeName'] ?? '',
                $this->minutesToHours($entry['minutes']),
                $mileageAmount > 0 ? $this->formatEuro($mileageAmount) : '',
                $allowanceAmount > 0 ? $this->formatEuro($allowanceAmount) : '',
                $entry['description'] ?? '',
            ];
            foreach ($cols as $i => $col) {
                $pdf->Cell($col[1], 6, $this->truncate($row[$i], $col[1]), 1, 0, $col[2]);
            }
            $pdf->Ln();
        }

        // Totals
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->Cell(124, 7, 'Gesamt', 1, 0, 'R');
        $pdf->Cell(20, 7, $this->minutesToHours($totals['totalMinutes']), 1, 0, 'R');
        $pdf->Cell(26, 7, $this->formatEuro($mileageTotal), 1, 0, 'R');
        $pdf->Cell(22, 7, $this->formatEuro($allowanceTotal), 1, 0, 'R');
        $pdf->Cell(75, 7, '', 1, 0, 'L');
        $pdf->Ln();

        $this->addAllowanceByEmployeeTable($pdf, $allowanceRows);

        return $pdf->Output('', 'S');
    }

    /**
     * Generate an aggregated project evaluation PDF (#57): hours per employee
     * over a period, for the current selection. Portrait.
     *
     * @param array<array{name: string, minutes: int}> $rows
     * @return string PDF content
     */
    public function generateProjectAggregate(string $label, array $rows, int $totalMinutes, array $filter = [], array $allowanceRows = []): string {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $companyName = $this->settingsService->getCompanyName() ?: 'Projektauswertung';
        $pdf->SetCreator('WorkTime Nextcloud App');
        $pdf->SetAuthor($companyName);
        $pdf->SetTitle('Projektauswertung');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont([self::FONT_FAMILY, '', self::FONT_SIZE_SMALL]);
        $pdf->setFooterMargin(10);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->AddPage();

        if ($this->settingsService->getCompanyName()) {
            $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_TITLE);
            $pdf->Cell(0, 10, $companyName, 0, 1, 'C');
            $pdf->Ln(1);
        }
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADER);
        $pdf->Cell(0, 8, 'Projektauswertung', 0, 1, 'C');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 6, $label, 0, 1, 'C');
        $this->addFilterContext($pdf, $filter);
        $pdf->Ln(4);

        // Portrait A4 content width ~180mm: 110 + 35 + 35
        $total = max(1, $totalMinutes);
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(110, 7, 'Mitarbeiter', 1, 0, 'L', true);
        $pdf->Cell(35, 7, 'Stunden', 1, 0, 'R', true);
        $pdf->Cell(35, 7, 'Anteil', 1, 0, 'R', true);
        $pdf->Ln();

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        foreach ($rows as $row) {
            $pct = round($row['minutes'] / $total * 100);
            $pdf->Cell(110, 6, $this->truncate($row['name'], 110), 1, 0, 'L');
            $pdf->Cell(35, 6, $this->minutesToHours($row['minutes']), 1, 0, 'R');
            $pdf->Cell(35, 6, $pct . ' %', 1, 0, 'R');
            $pdf->Ln();
        }

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->Cell(110, 7, 'Gesamt', 1, 0, 'R');
        $pdf->Cell(35, 7, $this->minutesToHours($totalMinutes), 1, 0, 'R');
        $pdf->Cell(35, 7, '100 %', 1, 0, 'R');
        $pdf->Ln();

        $this->addAllowanceByEmployeeTable($pdf, $allowanceRows);

        return $pdf->Output('', 'S');
    }

    private function minutesToHours(int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    /**
     * Render the filter context (which projects/employees the report covers)
     * under the title, so an exported PDF is self-documenting.
     *
     * @param array{projects?: string, employees?: string} $filter
     */
    private function addFilterContext(TCPDF $pdf, array $filter): void {
        if (empty($filter)) {
            return;
        }
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 5, 'Projekte: ' . ($filter['projects'] ?? 'Alle'), 0, 1, 'C');
        $pdf->Cell(0, 5, 'Mitarbeitende: ' . ($filter['employees'] ?? 'Alle'), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function truncate(string $text, float $widthMm): string {
        // Rough character budget for the small font at the given column width.
        $max = (int)max(4, $widthMm / 1.7);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Text exakt auf eine Zellenbreite kürzen (mit der aktuell gesetzten
     * Schrift gemessen), damit TCPDF::Cell nicht in die Nachbarzelle läuft.
     */
    private function truncateToWidth(TCPDF $pdf, string $text, float $widthMm): string {
        $available = $widthMm - 1; // kleiner Puffer zum Zellenrand
        if ($pdf->GetStringWidth($text) <= $available) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text . '…') > $available) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    /**
     * Create and configure TCPDF instance
     */
    private function createPdf(): TCPDF {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $companyName = $this->settingsService->getCompanyName() ?: 'Arbeitszeitnachweis';
        $pdf->SetCreator('WorkTime Nextcloud App');
        $pdf->SetAuthor($companyName);
        $pdf->SetTitle('Arbeitszeitnachweis');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);

        // Set footer
        $pdf->setFooterFont([self::FONT_FAMILY, '', self::FONT_SIZE_SMALL]);
        $pdf->setFooterMargin(10);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 25);

        // Set default font
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);

        // Add page
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Add header with company name and employee info.
     *
     * Kompakter Kopf: Firma links, Titel mittig, Zeitraum rechts in EINER
     * Zeile — spart Höhe für die Tagesliste (mehrzeilige Bemerkungen).
     * Darunter Mitarbeiter, Personalnummer und Wochenstunden je auf einer
     * eigenen Zeile.
     */
    private function addHeader(TCPDF $pdf, Employee $employee, string $periodLabel, DateTime $periodStart): void {
        $companyName = $this->settingsService->getCompanyName();

        // Kopfzeile: Firma | Arbeitszeitnachweis | Zeitraum (je 1/3 der Breite)
        $margins = $pdf->getMargins();
        $colWidth = ($pdf->getPageWidth() - $margins['left'] - $margins['right']) / 3;

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADER);
        $pdf->Cell($colWidth, 8, $this->truncateToWidth($pdf, $companyName, $colWidth), 0, 0, 'L');
        $pdf->Cell($colWidth, 8, 'Arbeitszeitnachweis', 0, 0, 'C');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_HEADER);
        $pdf->Cell($colWidth, 8, $periodLabel, 0, 1, 'R');
        $pdf->Ln(3);

        // Mitarbeiter, Personalnummer und Wochenstunden je auf eigener Zeile.
        // TCPDF-Cell schneidet Überlänge nicht ab — die Werte werden auf die
        // verbleibende Zeilenbreite gekürzt (greift nur bei Extremlängen).
        $valueWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'] - 40;
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(40, 6, 'Mitarbeiter:', 0, 0);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 6, $this->truncateToWidth($pdf, $employee->getFullName(), $valueWidth), 0, 1);

        if ($employee->getPersonnelNumber()) {
            $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
            $pdf->Cell(40, 6, 'Personalnummer:', 0, 0);
            $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
            $pdf->Cell(0, 6, $this->truncateToWidth($pdf, $employee->getPersonnelNumber(), $valueWidth), 0, 1);
        }

        // Wochenstunden auf eigener Zeile
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(40, 6, 'Wochenstunden:', 0, 0);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        // Wochenstunden des im Berichtszeitraum gültigen Arbeitszeitprofils anzeigen,
        // nicht das aktuelle Mitarbeiter-Feld – sonst weicht der Kopf bei mehreren
        // Profilen von der zeitraumgültigen Soll-Berechnung ab (#356).
        $weeklyHours = $this->workScheduleService->getScheduleForDate($employee->getId(), $periodStart)->getWeeklyHours();
        $pdf->Cell(0, 6, number_format($weeklyHours, 1, ',', '.') . ' Std.', 0, 1);

        $pdf->Ln(4);
    }

    /**
     * Add time entries table. Wenn im Zeitraum Spesen-Tage oder Kilometer
     * angefallen sind, bekommt die Tagesliste zwei zusätzliche Spalten (km,
     * Spesen) — die Summen stehen weiterhin im Block "Spesen & Kilometer".
     */
    private function addTimeEntriesTable(TCPDF $pdf, array $timeEntries, array $absences, array $holidays, DateTime $startDate, DateTime $endDate, ?array $allowance = null): void {
        // Project id -> name lookup (including inactive projects for historical entries).
        $projectNames = [];
        foreach ($this->projectMapper->findAll() as $project) {
            $projectNames[$project->getId()] = $project->getName();
        }

        $kmByDate = $allowance['kilometersByDate'] ?? [];
        $spesenDates = array_fill_keys($allowance['allowanceDates'] ?? [], true);
        $spesenPerDay = (float)($allowance['allowancePerDay'] ?? 0);
        $withAllowance = !empty($kmByDate) || !empty($spesenDates);

        $rows = $this->buildDayRowsBetween($timeEntries, $absences, $holidays, $startDate, $endDate, $projectNames, $kmByDate, $spesenDates, $spesenPerDay);

        // Table header
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->SetFillColor(230, 230, 230);

        if ($withAllowance) {
            $pdf->Cell(20, 7, 'Datum', 1, 0, 'C', true);
            $pdf->Cell(10, 7, 'Tag', 1, 0, 'C', true);
            $pdf->Cell(15, 7, 'Beginn', 1, 0, 'C', true);
            $pdf->Cell(15, 7, 'Ende', 1, 0, 'C', true);
            $pdf->Cell(14, 7, 'Pause', 1, 0, 'C', true);
            $pdf->Cell(18, 7, 'Arbeitszeit', 1, 0, 'C', true);
            $pdf->Cell(22, 7, 'Projekt', 1, 0, 'C', true);
            $pdf->Cell(12, 7, 'km', 1, 0, 'C', true);
            $pdf->Cell(16, 7, 'Spesen', 1, 0, 'C', true);
            $pdf->Cell(0, 7, 'Bemerkung', 1, 1, 'C', true);
        } else {
            $pdf->Cell(22, 7, 'Datum', 1, 0, 'C', true);
            $pdf->Cell(12, 7, 'Tag', 1, 0, 'C', true);
            $pdf->Cell(17, 7, 'Beginn', 1, 0, 'C', true);
            $pdf->Cell(17, 7, 'Ende', 1, 0, 'C', true);
            $pdf->Cell(17, 7, 'Pause', 1, 0, 'C', true);
            $pdf->Cell(20, 7, 'Arbeitszeit', 1, 0, 'C', true);
            $pdf->Cell(28, 7, 'Projekt', 1, 0, 'C', true);
            $pdf->Cell(0, 7, 'Bemerkung', 1, 1, 'C', true);
        }

        // Table body
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        foreach ($rows as $row) {
            if ($row['fill']) {
                $pdf->SetFillColor(245, 245, 245);
            }
            $this->renderTimeEntryRow(
                $pdf, $row['date'], $row['day'], $row['start'], $row['end'],
                $row['break'], $row['work'], $row['project'], $row['note'], $row['fill'],
                $withAllowance ? ($row['km'] ?? '') : null,
                $withAllowance ? ($row['spesen'] ?? '') : null
            );
        }

        $pdf->Ln(5);
    }

    /**
     * Build the ordered list of day rows for the monthly overview (#318).
     *
     * Every calendar day of the month produces at least one row so the overview
     * is gap-free:
     *  - days with bookings → one row per booking (plus a marker row for a
     *    genuine half-day absence on the same day);
     *  - full-day absence days → the absence type;
     *  - holidays → "Feiertag: …";
     *  - regular workdays without a booking → blank time cells (a visible gap);
     *  - weekends without anything → dashes and no label.
     *
     * Pure (no PDF/DB side effects) so it can be unit-tested.
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @param Holiday[] $holidays
     * @param array<int, string> $projectNames id => name
     * @return list<array{date: string, day: string, start: string, end: string, break: string, work: string, project: string, note: string, fill: bool}>
     */
    private function buildDayRows(array $timeEntries, array $absences, array $holidays, int $year, int $month, array $projectNames, array $kmByDate = [], array $spesenDates = [], float $spesenPerDay = 0): array {
        $startDate = new DateTime("$year-$month-01");
        $endDate = (clone $startDate)->modify('last day of this month');
        return $this->buildDayRowsBetween($timeEntries, $absences, $holidays, $startDate, $endDate, $projectNames, $kmByDate, $spesenDates, $spesenPerDay);
    }

    /**
     * Same as buildDayRows() but for an arbitrary inclusive [start, end] range
     * (#102 custom-period timesheet). Pure (no PDF/DB side effects).
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @param Holiday[] $holidays
     * @param array<int, string> $projectNames id => name
     * @param array<string, int> $kmByDate Y-m-d => zählende Kilometer des Tages
     * @param array<string, bool> $spesenDates Y-m-d => true für Spesen-Tage
     * @return list<array{date: string, day: string, start: string, end: string, break: string, work: string, project: string, note: string, fill: bool, km: string, spesen: string}>
     */
    private function buildDayRowsBetween(array $timeEntries, array $absences, array $holidays, DateTime $startDate, DateTime $endDate, array $projectNames, array $kmByDate = [], array $spesenDates = [], float $spesenPerDay = 0): array {
        // Holiday lookup
        $holidayDates = [];
        foreach ($holidays as $holiday) {
            $holidayDates[$holiday->getDate()->format('Y-m-d')] = $holiday->getName();
        }

        // Absence lookup per calendar day: expand each absence's start–end range
        // to individual dates. Rejected/cancelled absences are ignored — they
        // did not actually happen.
        $absencesByDate = [];
        foreach ($absences as $absence) {
            if (in_array($absence->getStatus(), [Absence::STATUS_REJECTED, Absence::STATUS_CANCELLED], true)) {
                continue;
            }
            $cursor = clone $absence->getStartDate();
            $absenceEnd = $absence->getEndDate();
            while ($cursor <= $absenceEnd) {
                $absencesByDate[$cursor->format('Y-m-d')] = $absence;
                $cursor->modify('+1 day');
            }
        }

        // Entries by date
        $entriesByDate = [];
        foreach ($timeEntries as $entry) {
            $entriesByDate[$entry->getDate()->format('Y-m-d')][] = $entry;
        }

        $rows = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $dayOfWeek = (int)$current->format('N');
            $isWeekend = $dayOfWeek > 5;
            $isHoliday = isset($holidayDates[$dateStr]);
            $fill = $isWeekend || $isHoliday;

            $date = $current->format('d.m.Y');
            $day = $this->getGermanDayName($dayOfWeek);

            $absence = $absencesByDate[$dateStr] ?? null;
            $absenceLabel = $absence !== null
                ? $absence->getTypeName() . ($absence->isHalfDay() ? ' (halber Tag)' : '')
                : '';

            // km/Spesen des Tages: wie das Datum nur auf der ersten Zeile des
            // Tages ausgeben (danach geleert), damit nichts doppelt erscheint.
            $km = isset($kmByDate[$dateStr]) ? (string)$kmByDate[$dateStr] : '';
            $spesen = isset($spesenDates[$dateStr]) ? $this->formatEuro($spesenPerDay) : '';

            if (isset($entriesByDate[$dateStr])) {
                foreach ($entriesByDate[$dateStr] as $entry) {
                    $rows[] = [
                        'date' => $date,
                        'day' => $day,
                        'start' => $entry->getStartTime()->format('H:i'),
                        'end' => $entry->getEndTime()->format('H:i'),
                        'break' => $this->formatMinutes($entry->getBreakMinutes()),
                        'work' => $this->formatMinutes($entry->getWorkMinutes()),
                        'project' => $entry->getProjectId() !== null ? ($projectNames[$entry->getProjectId()] ?? '') : '',
                        'note' => $entry->getDescription() ?? '',
                        'fill' => $fill,
                        'km' => $km,
                        'spesen' => $spesen,
                    ];
                    $date = ''; // Clear for subsequent entries on the same day
                    $day = '';
                    $km = '';
                    $spesen = '';
                }
                // Only a genuine half-day absence is shown alongside a booking
                // (morning work + afternoon off). A full-day absence never
                // coexists with a booking in valid data, so we don't add a
                // marker row there to avoid a confusing "worked + absent" day.
                if ($absence !== null && $absence->isHalfDay()) {
                    $rows[] = $this->markerRow($date, $day, $absenceLabel, $fill);
                }
            } elseif ($absence !== null) {
                // Absence day (#318): show the type directly in the day row.
                // km/Spesen sind hier möglich (externer Abwesenheitstyp).
                $rows[] = $this->markerRow($date, $day, $absenceLabel, $fill, $km, $spesen);
            } elseif ($isHoliday) {
                $rows[] = $this->markerRow($date, $day, 'Feiertag: ' . $holidayDates[$dateStr], $fill, $km, $spesen);
            } elseif (!$isWeekend) {
                // Regular workday without entry: blank cells signal a gap
                // (a missing booking that may need attention).
                $rows[] = [
                    'date' => $date, 'day' => $day, 'start' => '', 'end' => '',
                    'break' => '', 'work' => '', 'project' => '', 'note' => '', 'fill' => $fill,
                    'km' => $km, 'spesen' => $spesen,
                ];
            } else {
                // Weekend without entry/absence: show the day so the overview is
                // gap-free (#318), with dashes and no label — the "Sa"/"So" day
                // and grey shading already mark it as a non-working day.
                $rows[] = $this->markerRow($date, $day, '', $fill, $km, $spesen);
            }

            $current->modify('+1 day');
        }

        return $rows;
    }

    /**
     * A non-working day row: dashes in the time columns plus an optional note.
     *
     * @return array{date: string, day: string, start: string, end: string, break: string, work: string, project: string, note: string, fill: bool, km: string, spesen: string}
     */
    private function markerRow(string $date, string $day, string $note, bool $fill, string $km = '', string $spesen = ''): array {
        return [
            'date' => $date, 'day' => $day, 'start' => '-', 'end' => '-',
            'break' => '-', 'work' => '-', 'project' => '', 'note' => $note, 'fill' => $fill,
            'km' => $km, 'spesen' => $spesen,
        ];
    }

    /**
     * Move to the next page if the given block height would not fit in the
     * remaining space on the current page. Keeps a section (heading + content)
     * from being orphaned across a page break (#318).
     */
    private function ensureSpace(TCPDF $pdf, float $neededMm): void {
        if ($pdf->GetY() + $neededMm > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
            $pdf->AddPage();
        }
    }

    /**
     * Add absences section
     */
    private function addAbsencesSection(TCPDF $pdf, array $absences): void {
        // Keep heading + table header + at least the first rows together.
        $this->ensureSpace($pdf, 14 + min(count($absences), 3) * 6 + 5);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 8, 'Abwesenheiten', 0, 1);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(30, 6, 'Zeitraum', 1, 0, 'C', true);
        $pdf->Cell(20, 6, 'Tage', 1, 0, 'C', true);
        $pdf->Cell(30, 6, 'Art', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Status', 1, 0, 'C', true);
        $pdf->Cell(0, 6, 'Bemerkung', 1, 1, 'C', true);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);

        foreach ($absences as $absence) {
            $period = $absence->getStartDate()->format('d.m.') . ' - ' . $absence->getEndDate()->format('d.m.');
            $days = number_format((float)$absence->getDays(), 1, ',', '.');

            $statusTranslation = [
                Absence::STATUS_PENDING => 'Ausstehend',
                Absence::STATUS_APPROVED => 'Genehmigt',
                Absence::STATUS_REJECTED => 'Abgelehnt',
                Absence::STATUS_CANCELLED => 'Storniert',
            ];
            $status = $statusTranslation[$absence->getStatus()] ?? $absence->getStatus();

            $this->renderAbsenceRow($pdf, $period, $days, $absence->getTypeName(), $status, $absence->getNote() ?? '');
        }

        $pdf->Ln(5);
    }

    /**
     * Width of the note column = page width minus margins and the sum of the fixed columns.
     */
    private function getNoteCellWidth(TCPDF $pdf, float $fixedColumnsWidth): float {
        $margins = $pdf->getMargins();
        return $pdf->getPageWidth() - $margins['left'] - $margins['right'] - $fixedColumnsWidth;
    }

    /**
     * Calculate the row height needed so a wrapping note fits, with $minHeight as the floor.
     */
    private function calculateRowHeight(TCPDF $pdf, string $note, float $noteWidth, float $minHeight = 6.0): float {
        if ($note === '') {
            return $minHeight;
        }
        return max($minHeight, $pdf->getStringHeight($noteWidth, $note));
    }

    /**
     * Render one row of the time entries table; the note column wraps and dictates row height.
     * $km/$spesen null = Tabelle ohne die Zusatzspalten (kompaktes Standard-Layout).
     */
    private function renderTimeEntryRow(
        TCPDF $pdf,
        string $date,
        string $day,
        string $start,
        string $end,
        string $break,
        string $work,
        string $project,
        string $note,
        bool $fill,
        ?string $km = null,
        ?string $spesen = null
    ): void {
        $withAllowance = $km !== null;
        $noteWidth = $this->getNoteCellWidth($pdf, $withAllowance ? 142.0 : 133.0);
        $rowHeight = $this->calculateRowHeight($pdf, $note, $noteWidth);

        if ($withAllowance) {
            $pdf->Cell(20, $rowHeight, $date, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(10, $rowHeight, $day, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(15, $rowHeight, $start, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(15, $rowHeight, $end, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(14, $rowHeight, $break, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(18, $rowHeight, $work, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(22, $rowHeight, $this->truncate($project, 22), 1, 0, 'L', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(12, $rowHeight, $km, 1, 0, 'R', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(16, $rowHeight, $spesen ?? '', 1, 0, 'R', $fill, '', 0, false, 'T', 'M');
        } else {
            $pdf->Cell(22, $rowHeight, $date, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(12, $rowHeight, $day, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(17, $rowHeight, $start, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(17, $rowHeight, $end, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(17, $rowHeight, $break, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(20, $rowHeight, $work, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
            $pdf->Cell(28, $rowHeight, $this->truncate($project, 28), 1, 0, 'L', $fill, '', 0, false, 'T', 'M');
        }
        $pdf->MultiCell($noteWidth, $rowHeight, $note, 1, 'L', $fill, 1, '', '', true, 0, false, true, 0, 'M');
    }

    /**
     * Render one row of the absences table; the note column wraps and dictates row height.
     */
    private function renderAbsenceRow(
        TCPDF $pdf,
        string $period,
        string $days,
        string $type,
        string $status,
        string $note,
        bool $fill = false
    ): void {
        $noteWidth = $this->getNoteCellWidth($pdf, 105.0);
        $rowHeight = $this->calculateRowHeight($pdf, $note, $noteWidth);

        $pdf->Cell(30, $rowHeight, $period, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
        $pdf->Cell(20, $rowHeight, $days, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
        $pdf->Cell(30, $rowHeight, $type, 1, 0, 'L', $fill, '', 0, false, 'T', 'M');
        $pdf->Cell(25, $rowHeight, $status, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
        $pdf->MultiCell($noteWidth, $rowHeight, $note, 1, 'L', $fill, 1, '', '', true, 0, false, true, 0, 'M');
    }

    /**
     * Add summary section
     */
    private function addSummary(TCPDF $pdf, Employee $employee, array $statistics, string $workingDaysLabel = 'Arbeitstage im Monat:'): void {
        // Keep the whole summary block on one page.
        $this->ensureSpace($pdf, 48);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 8, 'Zusammenfassung', 0, 1);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);

        // Left column
        $pdf->Cell(50, 6, $workingDaysLabel, 0, 0);
        $pdf->Cell(30, 6, $statistics['workingDays'] . ' Tage', 0, 0);
        $pdf->Cell(50, 6, 'Feiertage:', 0, 0);
        $pdf->Cell(0, 6, $statistics['holidayCount'] . ' Tage', 0, 1);

        $pdf->Cell(50, 6, 'Abwesenheitstage:', 0, 0);
        $pdf->Cell(30, 6, number_format($statistics['absenceDays'], 1, ',', '.') . ' Tage', 0, 0);
        $pdf->Cell(50, 6, 'Einträge:', 0, 0);
        $pdf->Cell(0, 6, $statistics['entryCount'], 0, 1);

        $pdf->Ln(3);

        // Time calculations
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(50, 6, 'Soll-Arbeitszeit:', 0, 0);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->Cell(30, 6, $this->formatMinutes($statistics['adjustedTargetMinutes']), 0, 0);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(50, 6, 'Ist-Arbeitszeit:', 0, 0);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 6, $this->formatMinutes($statistics['actualMinutes']), 0, 1);

        // Overtime
        $pdf->Ln(2);
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADER);
        $overtimeMinutes = $statistics['overtimeMinutes'];
        $overtimeLabel = $overtimeMinutes >= 0 ? 'Überstunden:' : 'Minusstunden:';
        $overtimeFormatted = $this->formatMinutes(abs($overtimeMinutes));

        if ($overtimeMinutes < 0) {
            $overtimeFormatted = '-' . $overtimeFormatted;
        }

        $pdf->Cell(50, 8, $overtimeLabel, 0, 0);
        $pdf->Cell(0, 8, $overtimeFormatted, 0, 1);

        // Defensive hint: a zero-hour work schedule makes every working-day and
        // absence-day count collapse to 0. Surface the likely cause instead of
        // silently showing "0,0 Tage" (which looks like a data error to the user).
        if ((float)$statistics['workingDays'] === 0.0) {
            $pdf->Ln(2);
            $pdf->SetTextColor(180, 0, 0);
            $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
            $pdf->MultiCell(0, 5, 'Hinweis: Für diesen Monat sind 0 Arbeitstage hinterlegt. '
                . 'Vermutlich fehlt ein gültiges Arbeitszeitprofil mit Wochenstunden größer als 0. '
                . 'Bitte das Arbeitszeitprofil des Mitarbeiters prüfen.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);
        }

        $pdf->Ln(5);
    }

    /**
     * Spesen- und Kilometer-Block im Arbeitszeitnachweis. Wird nur gerendert,
     * wenn im Zeitraum Spesen-Tage oder Kilometer angefallen sind.
     *
     * @param array{allowanceDays: int, allowancePerDay: float, allowanceAmount: float, kilometers: int, mileageRate: float, mileageAmount: float, total: float} $allowance
     */
    private function addAllowanceSection(TCPDF $pdf, array $allowance): void {
        // Keep the whole block on one page.
        $this->ensureSpace($pdf, 32);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 8, 'Spesen & Kilometer', 0, 1);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);

        $pdf->Cell(50, 6, 'Spesen (Aussendienst):', 0, 0);
        $pdf->Cell(0, 6, sprintf(
            '%d Tage × %s = %s',
            $allowance['allowanceDays'],
            $this->formatEuro($allowance['allowancePerDay']),
            $this->formatEuro($allowance['allowanceAmount'])
        ), 0, 1);

        $pdf->Cell(50, 6, 'Kilometer (extern):', 0, 0);
        $pdf->Cell(0, 6, sprintf(
            '%d km × %s = %s',
            $allowance['kilometers'],
            $this->formatEuro($allowance['mileageRate']),
            $this->formatEuro($allowance['mileageAmount'])
        ), 0, 1);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(50, 6, 'Summe:', 0, 0);
        $pdf->Cell(0, 6, $this->formatEuro($allowance['total']), 0, 1);
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_NORMAL);

        $pdf->Ln(5);
    }

    /**
     * Tabelle "Spesen & Kilometer je Mitarbeiter" für die Projekt-Exporte.
     * Die Werte gelten je Mitarbeiter für den gesamten Zeitraum und sind
     * unabhängig von der Projektauswahl (Spesen/km sind tages-, nicht
     * projektgebunden) — der Hinweis wird mitgedruckt.
     *
     * @param array<array{name: string, allowanceDays: int, allowanceAmount: float, kilometers: int, mileageAmount: float, total: float}> $rows
     */
    private function addAllowanceByEmployeeTable(TCPDF $pdf, array $rows): void {
        if (empty($rows)) {
            return;
        }

        $this->ensureSpace($pdf, 24 + min(count($rows), 3) * 6);

        $pdf->Ln(6);
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 8, 'Spesen & Kilometer je Mitarbeiter', 0, 1, 'L');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 5, 'Je Mitarbeiter für den gesamten Zeitraum, unabhängig von der Projektauswahl.', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(60, 7, 'Mitarbeiter', 1, 0, 'L', true);
        $pdf->Cell(22, 7, 'Spesen-Tage', 1, 0, 'R', true);
        $pdf->Cell(24, 7, 'Spesen', 1, 0, 'R', true);
        $pdf->Cell(22, 7, 'Kilometer', 1, 0, 'R', true);
        $pdf->Cell(26, 7, 'Kilometergeld', 1, 0, 'R', true);
        $pdf->Cell(26, 7, 'Summe', 1, 0, 'R', true);
        $pdf->Ln();

        $totals = ['allowanceDays' => 0, 'allowanceAmount' => 0.0, 'kilometers' => 0, 'mileageAmount' => 0.0, 'total' => 0.0];
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        foreach ($rows as $row) {
            $pdf->Cell(60, 6, $this->truncate($row['name'], 60), 1, 0, 'L');
            $pdf->Cell(22, 6, (string)$row['allowanceDays'], 1, 0, 'R');
            $pdf->Cell(24, 6, $this->formatEuro($row['allowanceAmount']), 1, 0, 'R');
            $pdf->Cell(22, 6, (string)$row['kilometers'], 1, 0, 'R');
            $pdf->Cell(26, 6, $this->formatEuro($row['mileageAmount']), 1, 0, 'R');
            $pdf->Cell(26, 6, $this->formatEuro($row['total']), 1, 0, 'R');
            $pdf->Ln();

            $totals['allowanceDays'] += $row['allowanceDays'];
            $totals['allowanceAmount'] += $row['allowanceAmount'];
            $totals['kilometers'] += $row['kilometers'];
            $totals['mileageAmount'] += $row['mileageAmount'];
            $totals['total'] += $row['total'];
        }

        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SMALL);
        $pdf->Cell(60, 7, 'Gesamt', 1, 0, 'R');
        $pdf->Cell(22, 7, (string)$totals['allowanceDays'], 1, 0, 'R');
        $pdf->Cell(24, 7, $this->formatEuro($totals['allowanceAmount']), 1, 0, 'R');
        $pdf->Cell(22, 7, (string)$totals['kilometers'], 1, 0, 'R');
        $pdf->Cell(26, 7, $this->formatEuro($totals['mileageAmount']), 1, 0, 'R');
        $pdf->Cell(26, 7, $this->formatEuro($totals['total']), 1, 0, 'R');
        $pdf->Ln();
    }

    private function formatEuro(float $amount): string {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    /**
     * Add signature section
     */
    private function addSignatureSection(TCPDF $pdf): void {
        // Keep the confirmation text + signature lines together on one page.
        $this->ensureSpace($pdf, 50);

        $pdf->Ln(10);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        $pdf->Cell(0, 5, 'Ich bestätige die Richtigkeit der oben aufgeführten Arbeitszeiten.', 0, 1);

        $pdf->Ln(10);

        $lineWidth = 70;
        $gap = 40;

        // "Ort, Datum" labels above signature lines
        $pdf->Cell($lineWidth, 5, 'Ort, Datum', 0, 0, 'L');
        $pdf->Cell($gap, 5, '', 0, 0);
        $pdf->Cell($lineWidth, 5, 'Ort, Datum', 0, 1, 'L');

        $pdf->Ln(10);

        // Signature lines
        $pdf->Cell($lineWidth, 6, '', 'B', 0);
        $pdf->Cell($gap, 6, '', 0, 0);
        $pdf->Cell($lineWidth, 6, '', 'B', 1);

        // Labels below signature lines
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        $pdf->Cell($lineWidth, 5, 'Mitarbeiter', 0, 0, 'C');
        $pdf->Cell($gap, 5, '', 0, 0);
        $pdf->Cell($lineWidth, 5, 'Vorgesetzter', 0, 1, 'C');
    }

    /**
     * Format minutes as hours:minutes string
     */
    private function formatMinutes(int $minutes): string {
        $hours = intdiv(abs($minutes), 60);
        $mins = abs($minutes) % 60;
        return sprintf('%d:%02d Std.', $hours, $mins);
    }

    /**
     * Get German month name
     */
    private function getGermanMonthName(int $month): string {
        $months = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];
        return $months[$month] ?? '';
    }

    /**
     * Get German day name abbreviation
     */
    private function getGermanDayName(int $dayOfWeek): string {
        $days = [
            1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do',
            5 => 'Fr', 6 => 'Sa', 7 => 'So',
        ];
        return $days[$dayOfWeek] ?? '';
    }

    /**
     * Add approval info section to PDF (two columns: submitted / approved)
     */
    private function addApprovalInfoSection(TCPDF $pdf, array $approvalInfo): void {
        // Keep the approval info block together on one page.
        $this->ensureSpace($pdf, 45);

        $pdf->Ln(10);

        // Extract data
        $submittedBy = $approvalInfo['submittedBy'] ?? null;
        $submittedAt = $approvalInfo['submittedAt'] ?? null;
        $approvedBy = $approvalInfo['approvedBy'] ?? null;
        $approvedAt = $approvalInfo['approvedAt'] ?? null;

        $submitterName = $submittedBy instanceof Employee ? $submittedBy->getFullName() : 'Unbekannt';
        $submissionDate = $submittedAt instanceof DateTime ? $submittedAt->format('d.m.Y H:i') : 'Unbekannt';
        $approverName = $approvedBy instanceof Employee ? $approvedBy->getFullName() : 'Unbekannt';
        $approvalDate = $approvedAt instanceof DateTime ? $approvedAt->format('d.m.Y H:i') : 'Unbekannt';

        // Header
        $pdf->SetFillColor(240, 248, 255);
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_NORMAL);
        $pdf->Cell(0, 8, 'Genehmigungsvermerk', 1, 1, 'L', true);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);

        $colWidth = 90;

        // Row 1: Von labels and values
        $pdf->Cell(30, 6, 'Eingereicht von:', 1, 0, 'L');
        $pdf->Cell($colWidth - 30, 6, $submitterName, 1, 0, 'L');
        $pdf->Cell(30, 6, 'Genehmigt von:', 1, 0, 'L');
        $pdf->Cell(0, 6, $approverName, 1, 1, 'L');

        // Row 2: Am labels and values
        $pdf->Cell(30, 6, 'Eingereicht am:', 1, 0, 'L');
        $pdf->Cell($colWidth - 30, 6, $submissionDate . ' Uhr', 1, 0, 'L');
        $pdf->Cell(30, 6, 'Genehmigt am:', 1, 0, 'L');
        $pdf->Cell(0, 6, $approvalDate . ' Uhr', 1, 1, 'L');
    }

    /**
     * Archive monthly report PDF to Nextcloud folder
     *
     * @param string $adminUserId User ID with write access (usually admin or HR)
     * @param Employee $employee The employee whose report is archived
     * @param int $year
     * @param int $month
     * @param string $pdfContent PDF content to save
     * @return string Path where the file was saved
     * @throws \Exception If archive folder cannot be created or file cannot be written
     */
    public function archiveMonthlyReport(
        string $adminUserId,
        Employee $employee,
        int $year,
        int $month,
        string $pdfContent
    ): string {
        $archivePath = $this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_PATH);

        // Build folder path: {archivePath}/{Jahr}/{Nachname_Vorname}/
        $folderPath = sprintf(
            '%s/%d/%s_%s',
            trim($archivePath, '/'),
            $year,
            $employee->getLastName(),
            $employee->getFirstName()
        );

        // Build filename: Arbeitszeitnachweis_{YYYY-MM}.pdf
        $filename = sprintf('Arbeitszeitnachweis_%d-%02d.pdf', $year, $month);

        try {
            $userFolder = $this->rootFolder->getUserFolder($adminUserId);

            // Create folder structure if it doesn't exist
            $currentPath = '';
            foreach (explode('/', $folderPath) as $folder) {
                if (empty($folder)) {
                    continue;
                }
                $currentPath .= '/' . $folder;
                $relativePath = ltrim($currentPath, '/');

                try {
                    $userFolder->get($relativePath);
                } catch (FilesNotFoundException) {
                    $userFolder->newFolder($relativePath);
                }
            }

            $fullPath = $folderPath . '/' . $filename;
            $relativePath = ltrim($fullPath, '/');

            // Check if file already exists and delete it
            try {
                $existingFile = $userFolder->get($relativePath);
                $existingFile->delete();
            } catch (FilesNotFoundException) {
                // File doesn't exist, that's fine
            }

            // Write the file
            $userFolder->newFile($relativePath, $pdfContent);

            return $fullPath;
        } catch (\Exception $e) {
            throw new \Exception('Could not archive PDF: ' . $e->getMessage());
        }
    }

    /**
     * Remove an archived monthly report. Called when a month is un-approved (reopened),
     * so the archive never holds a PDF for a month that is no longer approved (#323).
     * No-op if archiving is not configured or the file does not exist.
     */
    public function deleteArchivedReport(
        string $adminUserId,
        Employee $employee,
        int $year,
        int $month
    ): bool {
        $archivePath = $this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_PATH);
        if (empty($archivePath)) {
            return false;
        }

        $folderPath = sprintf(
            '%s/%d/%s_%s',
            trim($archivePath, '/'),
            $year,
            $employee->getLastName(),
            $employee->getFirstName()
        );
        $filename = sprintf('Arbeitszeitnachweis_%d-%02d.pdf', $year, $month);
        $relativePath = ltrim($folderPath . '/' . $filename, '/');

        try {
            $userFolder = $this->rootFolder->getUserFolder($adminUserId);
            $file = $userFolder->get($relativePath);
            $file->delete();
            return true;
        } catch (FilesNotFoundException) {
            // Already gone — nothing to delete.
            return false;
        }
        // Other errors (permissions, storage) propagate so the calling
        // controller can log them instead of failing silently.
    }

    /**
     * Whether an archived report already exists for this month (#323),
     * so callers can distinguish a fresh archive from a replacement.
     */
    public function archivedReportExists(
        string $adminUserId,
        Employee $employee,
        int $year,
        int $month
    ): bool {
        $archivePath = $this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_PATH);
        if (empty($archivePath)) {
            return false;
        }

        $folderPath = sprintf(
            '%s/%d/%s_%s',
            trim($archivePath, '/'),
            $year,
            $employee->getLastName(),
            $employee->getFirstName()
        );
        $filename = sprintf('Arbeitszeitnachweis_%d-%02d.pdf', $year, $month);
        $relativePath = ltrim($folderPath . '/' . $filename, '/');

        try {
            $this->rootFolder->getUserFolder($adminUserId)->get($relativePath);
            return true;
        } catch (FilesNotFoundException) {
            return false;
        } catch (\Exception) {
            return false;
        }
    }
}
