<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Db\DutyJobTemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Auftragsvorlagen («Standard-Auftraege») fuer den Dienstplan. Gepflegt wird in
 * den Einstellungen (nur Admin), gezogen wird in der Wochenansicht (Planer).
 * Die Validierung ist bewusst dieselbe wie bei DutyJobService, abzueglich
 * Mitarbeiter und Datum — eine Vorlage kennt beides nicht.
 */
class DutyJobTemplateService {

    private const ENTITY_TYPE = 'duty_job_template';
    private const TITLE_MAX = 200;
    private const NOTE_MAX = 500;

    public function __construct(
        private DutyJobTemplateMapper $templateMapper,
        private AuditLogService $auditLogService,
        private IL10N $l,
    ) {
    }

    /**
     * @return DutyJobTemplate[]
     */
    public function findAll(): array {
        return $this->templateMapper->findAll();
    }

    /**
     * @return DutyJobTemplate[]
     */
    public function findVisible(): array {
        return $this->templateMapper->findVisible();
    }

    /**
     * @throws NotFoundException
     */
    public function find(int $id): DutyJobTemplate {
        try {
            return $this->templateMapper->find($id);
        } catch (DoesNotExistException) {
            throw new NotFoundException('Duty job template not found');
        }
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, string $userId): DutyJobTemplate {
        $clean = $this->validate($data);

        $template = new DutyJobTemplate();
        $this->apply($template, $clean);
        $template->setCreatedAt(new DateTime());
        $template->setUpdatedAt(new DateTime());
        $template = $this->templateMapper->insert($template);

        $this->auditLogService->logCreate($userId, self::ENTITY_TYPE, $template->getId(), $template->jsonSerialize());
        return $template;
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(int $id, array $data, string $userId): DutyJobTemplate {
        $template = $this->find($id);
        $old = $template->jsonSerialize();
        $data['sortOrder'] = $data['sortOrder'] ?? $template->getSortOrder();
        $clean = $this->validate($data);

        $this->apply($template, $clean);
        $template->setUpdatedAt(new DateTime());
        $template = $this->templateMapper->update($template);

        $this->auditLogService->logUpdate($userId, self::ENTITY_TYPE, $template->getId(), $old, $template->jsonSerialize());
        return $template;
    }

    /**
     * Vorlagen werden hart geloescht. Bereits erzeugte Karten bleiben
     * unberuehrt — sie sind eigenstaendige Datensaetze ohne Rueckbezug.
     *
     * @throws NotFoundException
     */
    public function delete(int $id, string $userId): void {
        $template = $this->find($id);
        $this->auditLogService->logDelete($userId, self::ENTITY_TYPE, $template->getId(), $template->jsonSerialize());
        $this->templateMapper->delete($template);
    }

    private function apply(DutyJobTemplate $template, array $clean): void {
        $template->setTitle($clean['title']);
        $template->setStartTime($clean['startTime']);
        $template->setDurationMinutes($clean['durationMinutes']);
        $template->setNote($clean['note']);
        $template->setOnCall($clean['onCall']);
        $template->setIsVisible($clean['isVisible']);
        $template->setSortOrder($clean['sortOrder']);
    }

    /**
     * Same rules as DutyJobService::validate() for the shared fields.
     *
     * @throws ValidationException
     */
    private function validate(array $data): array {
        $errors = [];

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = [$this->l->t('Titel ist erforderlich')];
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = [$this->l->t('Titel darf höchstens %d Zeichen haben', [self::TITLE_MAX])];
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

        $note = $data['note'] ?? null;
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            $errors['note'] = [$this->l->t('Notiz darf höchstens %d Zeichen haben', [self::NOTE_MAX])];
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'startTime' => $startTime,
            'durationMinutes' => $duration,
            'note' => $note,
            'onCall' => (bool)($data['onCall'] ?? false),
            'isVisible' => (bool)($data['isVisible'] ?? true),
            'sortOrder' => (int)($data['sortOrder'] ?? 0),
        ];
    }
}
