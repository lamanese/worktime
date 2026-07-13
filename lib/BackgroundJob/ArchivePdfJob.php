<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\BackgroundJob;

use DateTime;
use OCA\Zeitwerk\Db\ArchiveQueue;
use OCA\Zeitwerk\Db\ArchiveQueueMapper;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Notification\NotificationService;
use OCA\Zeitwerk\Service\ArchiveService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\EmployeeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that processes the PDF archive queue.
 * Runs every 5 minutes and archives approved monthly reports.
 */
class ArchivePdfJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private ArchiveQueueMapper $queueMapper,
        private CompanySettingsService $settingsService,
        private ArchiveService $archiveService,
        private EmployeeService $employeeService,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // Run every 5 minutes
        $this->setInterval(300);
    }

    protected function run($argument): void {
        $archiveUserId = $this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_USER);
        $archivePath = $this->settingsService->get(CompanySetting::KEY_PDF_ARCHIVE_PATH);

        if (empty($archiveUserId) || empty($archivePath)) {
            // Not configured, skip silently
            return;
        }

        // Get pending jobs (max 10 per run to avoid timeout)
        $pendingJobs = $this->queueMapper->findPending(10);

        foreach ($pendingJobs as $job) {
            $this->processJob($job, $archiveUserId);
        }

        // Clean up old completed jobs (older than 30 days)
        $this->queueMapper->deleteOldCompleted(30);
    }

    private function processJob(ArchiveQueue $job, string $archiveUserId): void {
        // Mark as processing
        $job->setStatus(ArchiveQueue::STATUS_PROCESSING);
        $this->queueMapper->update($job);

        try {
            $this->archiveService->archiveMonth(
                $job->getEmployeeId(),
                $job->getYear(),
                $job->getMonth(),
                $job->getApproverId(),
                $job->getApprovedAt(),
                $job->getSubmittedAt()
            );

            // Mark as completed
            $job->setStatus(ArchiveQueue::STATUS_COMPLETED);
            $job->setProcessedAt(new DateTime());
            $this->queueMapper->update($job);

            $this->logger->info(
                'PDF archived successfully for employee {employeeId}, {year}-{month}',
                [
                    'employeeId' => $job->getEmployeeId(),
                    'year' => $job->getYear(),
                    'month' => $job->getMonth(),
                ]
            );

        } catch (\Exception $e) {
            $job->setAttempts($job->getAttempts() + 1);
            $job->setLastError($e->getMessage());

            if ($job->getAttempts() >= ArchiveQueue::MAX_ATTEMPTS) {
                $job->setStatus(ArchiveQueue::STATUS_FAILED);
                $this->logger->error(
                    'PDF archive permanently failed for employee {employeeId}, {year}-{month}: {error}',
                    [
                        'employeeId' => $job->getEmployeeId(),
                        'year' => $job->getYear(),
                        'month' => $job->getMonth(),
                        'error' => $e->getMessage(),
                    ]
                );
                $this->notifyFailure($job, $archiveUserId, $e->getMessage());
            } else {
                // Reset to pending for retry
                $job->setStatus(ArchiveQueue::STATUS_PENDING);
                $this->logger->warning(
                    'PDF archive failed for employee {employeeId}, {year}-{month}, will retry: {error}',
                    [
                        'employeeId' => $job->getEmployeeId(),
                        'year' => $job->getYear(),
                        'month' => $job->getMonth(),
                        'error' => $e->getMessage(),
                        'attempts' => $job->getAttempts(),
                    ]
                );
            }

            $this->queueMapper->update($job);
        }
    }

    /**
     * Notify the archive admin about a permanently failed archive job (#323),
     * so it surfaces instead of failing silently in the background.
     */
    private function notifyFailure(ArchiveQueue $job, string $archiveUserId, string $error): void {
        $employeeName = '#' . $job->getEmployeeId();
        try {
            $employee = $this->employeeService->find($job->getEmployeeId());
            $employeeName = trim($employee->getFirstName() . ' ' . $employee->getLastName());
        } catch (\Throwable) {
            // fall back to the id-based label
        }

        $this->notificationService->notifyArchiveFailed(
            $archiveUserId,
            $job->getEmployeeId(),
            $employeeName,
            $job->getYear(),
            $job->getMonth(),
            $error
        );
    }

}
