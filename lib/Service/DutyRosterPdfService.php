<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySetting;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use TCPDF;

/**
 * Wochenplan als PDF (spec §11.3): A4 quer, Zeile pro Mitarbeiter, Spalte pro
 * Tag. Rendert den Wochenpayload von DutyJobService::getWeek fuer den
 * anfragenden Nutzer (gleiche Maskierung wie die Ansicht) und legt das PDF im
 * Archivordner des Archiv-Benutzers unter Dienstplan/KWnn-JJJJ.pdf ab.
 */
class DutyRosterPdfService {

    public const ARCHIVE_SAVED = 'saved';
    public const ARCHIVE_SKIPPED = 'skipped';
    public const ARCHIVE_FAILED = 'failed';

    private const FONT = 'helvetica';
    private const SUBFOLDER = 'Dienstplan';
    private const NAME_COL_MM = 45.0;
    private const LINE_MM = 4.2;
    private const CELL_PAD_MM = 1.5;

    public function __construct(
        private CompanySettingsService $settingsService,
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
        private IL10N $l,
    ) {
    }

    /**
     * Render, archive (best effort) and audit in one go.
     *
     * @param array $week payload of DutyJobService::getWeek
     * @return array{pdf:string,filename:string,archive:string,path:?string}
     */
    public function export(array $week, string $userId): array {
        $monday = new DateTime($week['weekStart']);
        $pdf = $this->render($week, $userId);
        $filename = self::filename($monday);

        $archive = self::ARCHIVE_SKIPPED;
        $path = null;
        try {
            $path = $this->archive($pdf, $monday);
            if ($path !== null) {
                $archive = self::ARCHIVE_SAVED;
            }
        } catch (\Exception $e) {
            $archive = self::ARCHIVE_FAILED;
            $this->logger->warning('Dienstplan-PDF konnte nicht abgelegt werden: {error}', ['error' => $e->getMessage()]);
        }

        $this->auditLogService->log($userId, 'export_pdf', 'duty_job', null, null, [
            'weekStart' => $monday->format('Y-m-d'),
            'archive' => $archive,
            'path' => $path,
        ]);

        return ['pdf' => $pdf, 'filename' => $filename, 'archive' => $archive, 'path' => $path];
    }

    /**
     * KW38-2026.pdf — ISO week and ISO year of the Monday.
     */
    public static function filename(DateTime $monday): string {
        return sprintf('KW%02d-%s.pdf', (int)$monday->format('W'), $monday->format('o'));
    }

    /**
     * Writes the PDF into <pdf_archive_path>/Dienstplan/ of the archive user.
     * Returns the path relative to the archive user's root, or null when no
     * archive user/path is configured. Storage errors propagate.
     *
     * @throws \Exception
     */
    public function archive(string $pdf, DateTime $monday): ?string {
        $archiveUserId = (string)($this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_USER) ?? '');
        $archivePath = (string)($this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_PATH) ?? '');
        if ($archiveUserId === '' || $archivePath === '') {
            return null;
        }

        $userFolder = $this->rootFolder->getUserFolder($archiveUserId);
        $folderPath = trim($archivePath, '/') . '/' . self::SUBFOLDER;
        $this->ensureFolder($userFolder, $folderPath);

        $relativePath = $folderPath . '/' . self::filename($monday);
        try {
            $userFolder->get($relativePath)->delete();
        } catch (FilesNotFoundException) {
            // fresh file
        }
        $userFolder->newFile($relativePath, $pdf);
        return '/' . $relativePath;
    }

    private function ensureFolder(Folder $userFolder, string $folderPath): void {
        $current = '';
        foreach (explode('/', $folderPath) as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . '/' . $part;
            try {
                $userFolder->get($current);
            } catch (FilesNotFoundException) {
                $userFolder->newFolder($current);
            }
        }
    }

    /**
     * @param array $week payload of DutyJobService::getWeek
     * @return string PDF bytes
     */
    public function render(array $week, string $userId): string {
        $monday = new DateTime($week['weekStart']);
        $sunday = new DateTime($week['weekEnd']);
        $companyName = $this->settingsService->getCompanyName();
        $title = $this->l->t('Dienstplan KW %s', [(int)$monday->format('W')]);
        $range = $monday->format('d.m.') . ' – ' . $sunday->format('d.m.Y');

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Zeitwerk Nextcloud App');
        $pdf->SetAuthor($companyName !== '' ? $companyName : 'Zeitwerk');
        $pdf->SetTitle($title . ' ' . $range);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont([self::FONT, '', 8]);
        $pdf->setFooterMargin(8);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(false, 12);
        $pdf->setCellPaddings(self::CELL_PAD_MM, 1, self::CELL_PAD_MM, 1);
        $pdf->AddPage();

        $this->addTitle($pdf, $companyName, $title, $range, $week, $userId);

        $days = $week['days'] ?? [];
        $rows = $week['rows'] ?? [];
        $contentWidth = $pdf->getPageWidth() - 20;
        $dayWidth = count($days) > 0 ? ($contentWidth - self::NAME_COL_MM) / count($days) : $contentWidth;

        $this->addHeaderRow($pdf, $days, $dayWidth);
        if (count($rows) === 0) {
            $pdf->SetFont(self::FONT, 'I', 9);
            $pdf->Cell($contentWidth, 8, $this->l->t('Keine Mitarbeitenden im Dienstplan'), 1, 1, 'C');
            return $pdf->Output('', 'S');
        }

        $bottom = $pdf->getPageHeight() - 12;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($days as $day) {
                $cells[] = $this->cellText($row, $day['date']);
            }
            $pdf->SetFont(self::FONT, '', 8);
            $lines = 1;
            foreach ($cells as $text) {
                $lines = max($lines, $pdf->getNumLines($text === '' ? ' ' : $text, $dayWidth));
            }
            $rowHeight = $lines * self::LINE_MM + 2;

            if ($pdf->GetY() + $rowHeight > $bottom) {
                $pdf->AddPage();
                $this->addHeaderRow($pdf, $days, $dayWidth);
            }

            $y = $pdf->GetY();
            $x = $pdf->GetX();
            $pdf->SetFont(self::FONT, 'B', 9);
            $pdf->MultiCell(self::NAME_COL_MM, $rowHeight, (string)$row['employee']['fullName'], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');
            $x += self::NAME_COL_MM;
            $pdf->SetFont(self::FONT, '', 8);
            foreach ($days as $i => $day) {
                $fill = !empty($day['isWeekend']);
                if ($fill) {
                    $pdf->SetFillColor(245, 245, 245);
                }
                $pdf->MultiCell($dayWidth, $rowHeight, $cells[$i], 1, 'L', $fill, 0, $x, $y, true, 0, false, true, $rowHeight, 'T');
                $x += $dayWidth;
            }
            $pdf->SetXY(10, $y + $rowHeight);
        }

        return $pdf->Output('', 'S');
    }

    private function addTitle(TCPDF $pdf, string $companyName, string $title, string $range, array $week, string $userId): void {
        if ($companyName !== '') {
            $pdf->SetFont(self::FONT, 'B', 13);
            $pdf->Cell(0, 7, $companyName, 0, 1, 'L');
        }
        $pdf->SetFont(self::FONT, 'B', 14);
        $pdf->Cell(0, 8, $title . ' · ' . $range, 0, 1, 'L');

        $creator = $this->userManager->get($userId)?->getDisplayName() ?? $userId;
        $meta = $this->l->t('Erstellt am %1$s von %2$s', [(new DateTime())->format('d.m.Y H:i'), $creator]);
        if (!empty($week['locked'])) {
            $lockedAt = !empty($week['lockedAt']) ? (new DateTime((string)$week['lockedAt']))->format('d.m.Y H:i') : '';
            $meta .= ' · ' . $this->l->t('Woche gesperrt von %1$s am %2$s', [(string)($week['lockedBy'] ?? '–'), $lockedAt]);
        }
        $pdf->SetFont(self::FONT, '', 8);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 5, $meta, 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }

    private function addHeaderRow(TCPDF $pdf, array $days, float $dayWidth): void {
        $pdf->SetFont(self::FONT, 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(self::NAME_COL_MM, 7, $this->l->t('Mitarbeiter'), 1, 0, 'L', true);
        foreach ($days as $day) {
            $date = new DateTime($day['date']);
            $label = $this->dayName((int)$date->format('N')) . ' ' . $date->format('d.m.');
            $pdf->Cell($dayWidth, 7, $label, 1, 0, 'C', true);
        }
        $pdf->Ln();
    }

    private function dayName(int $isoDay): string {
        return match ($isoDay) {
            1 => $this->l->t('Mo'),
            2 => $this->l->t('Di'),
            3 => $this->l->t('Mi'),
            4 => $this->l->t('Do'),
            5 => $this->l->t('Fr'),
            6 => $this->l->t('Sa'),
            default => $this->l->t('So'),
        };
    }

    /**
     * One line per job («08:30 Titel (1:30 h) · Abruf»), absence/holiday label
     * first. Same precedence as the view: holiday > approved full > half > pending.
     */
    private function cellText(array $row, string $date): string {
        $lines = [];
        $label = $this->absenceLabel($row, $date);
        if ($label !== null) {
            $lines[] = '[' . $label . ']';
        }
        $jobs = array_values(array_filter($row['jobs'] ?? [], static fn (array $j) => ($j['date'] ?? '') === $date));
        usort($jobs, static function (array $a, array $b): int {
            $ta = $a['startTime'] ?? null;
            $tb = $b['startTime'] ?? null;
            if ($ta && !$tb) {
                return -1;
            }
            if (!$ta && $tb) {
                return 1;
            }
            return strcmp((string)$ta, (string)$tb) ?: strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
        foreach ($jobs as $job) {
            $line = ($job['startTime'] ?? null) ? $job['startTime'] . ' ' : '';
            $line .= (string)($job['title'] ?? '');
            if (!empty($job['durationMinutes'])) {
                $line .= ' (' . $this->formatDuration((int)$job['durationMinutes']) . ')';
            }
            if (!empty($job['onCall'])) {
                $line .= ' · ' . $this->l->t('Abruf');
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private function absenceLabel(array $row, string $date): ?string {
        foreach ($row['holidays'] ?? [] as $holiday) {
            if (($holiday['date'] ?? '') === $date) {
                return $this->l->t('Feiertag: %s', [(string)$holiday['name']]);
            }
        }
        $absences = array_filter($row['absences'] ?? [], static fn (array $a) => ($a['date'] ?? '') === $date);
        $approved = array_filter($absences, static fn (array $a) => ($a['status'] ?? '') === 'approved');
        foreach ($approved as $a) {
            if ((float)($a['scope'] ?? 1) >= 1) {
                return (string)$a['typeName'];
            }
        }
        foreach ($approved as $a) {
            return $this->l->t('½ %s', [(string)$a['typeName']]);
        }
        foreach ($absences as $a) {
            if (($a['status'] ?? '') === 'pending') {
                return $this->l->t('beantragt: %s', [(string)$a['typeName']]);
            }
        }
        return null;
    }

    private function formatDuration(int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m . ' min';
        }
        return $m === 0 ? $h . ' h' : sprintf('%d:%02d h', $h, $m);
    }
}
