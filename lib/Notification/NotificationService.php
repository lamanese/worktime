<?php

declare(strict_types=1);

namespace OCA\WorkTime\Notification;

use OCA\WorkTime\AppInfo\Application;
use OCA\WorkTime\Db\Absence;
use OCA\WorkTime\Db\EmployeeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class NotificationService {

	public function __construct(
		private INotificationManager $notificationManager,
		private EmployeeMapper $employeeMapper,
		private LoggerInterface $logger,
	) {
	}

	public function notifyAbsenceSubmitted(Absence $absence): void {
		try {
			$employee = $this->employeeMapper->find($absence->getEmployeeId());
			$supervisorUserId = $this->getSupervisorUserId($employee->getSupervisorId());
			if ($supervisorUserId === null) {
				return;
			}

			$notification = $this->createNotification('absence_submitted', $supervisorUserId, [
				'employeeName' => $employee->getFullName(),
				'typeName' => $absence->getTypeName(),
				'startDate' => $absence->getStartDate()->format('d.m.'),
				'endDate' => $absence->getEndDate()->format('d.m.'),
			]);
			$notification->setObject('absence', (string)$absence->getId());

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send absence_submitted notification', [
				'exception' => $e,
				'absenceId' => $absence->getId(),
			]);
		}
	}

	public function notifyAbsenceApproved(Absence $absence): void {
		$this->sendAbsenceDecisionNotification($absence, 'absence_approved');
	}

	public function notifyAbsenceRejected(Absence $absence): void {
		$this->sendAbsenceDecisionNotification($absence, 'absence_rejected');
	}

	public function notifyAbsenceInformational(Absence $absence): void {
		$this->sendSupervisorAbsenceNotification($absence, 'absence_informational');
	}

	public function notifyAbsenceCancelled(Absence $absence): void {
		$this->sendSupervisorAbsenceNotification($absence, 'absence_cancelled');
	}

	public function notifyTimeEntriesSubmitted(int $employeeId, int $year, int $month): void {
		try {
			$employee = $this->employeeMapper->find($employeeId);
			$supervisorUserId = $this->getSupervisorUserId($employee->getSupervisorId());
			if ($supervisorUserId === null) {
				return;
			}

			$notification = $this->createNotification('time_entries_submitted', $supervisorUserId, [
				'employeeName' => $employee->getFullName(),
				'month' => $month,
				'year' => $year,
			]);
			$notification->setObject('time_entry', $employeeId . '-' . $year . '-' . $month);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send time_entries_submitted notification', [
				'exception' => $e,
				'employeeId' => $employeeId,
			]);
		}
	}

	public function notifyTimeEntriesApproved(int $employeeId, int $year, int $month): void {
		$this->sendTimeEntryDecisionNotification($employeeId, $year, $month, 'time_entries_approved');
	}

	public function notifyTimeEntriesRejected(int $employeeId, int $year, int $month): void {
		$this->sendTimeEntryDecisionNotification($employeeId, $year, $month, 'time_entries_rejected');
	}

	public function notifyTimeEntriesReopened(int $employeeId, int $year, int $month): void {
		$this->sendTimeEntryDecisionNotification($employeeId, $year, $month, 'time_entries_reopened');
	}

	private function sendSupervisorAbsenceNotification(Absence $absence, string $subject): void {
		try {
			$employee = $this->employeeMapper->find($absence->getEmployeeId());
			$supervisorUserId = $this->getSupervisorUserId($employee->getSupervisorId());
			if ($supervisorUserId === null) {
				return;
			}

			$notification = $this->createNotification($subject, $supervisorUserId, [
				'employeeName' => $employee->getFullName(),
				'typeName' => $absence->getTypeName(),
				'startDate' => $absence->getStartDate()->format('d.m.'),
				'endDate' => $absence->getEndDate()->format('d.m.'),
			]);
			$notification->setObject('absence', (string)$absence->getId());

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send ' . $subject . ' notification', [
				'exception' => $e,
				'absenceId' => $absence->getId(),
			]);
		}
	}

	private function sendAbsenceDecisionNotification(Absence $absence, string $subject): void {
		try {
			$employee = $this->employeeMapper->find($absence->getEmployeeId());

			$notification = $this->createNotification($subject, $employee->getUserId(), [
				'typeName' => $absence->getTypeName(),
				'startDate' => $absence->getStartDate()->format('d.m.'),
				'endDate' => $absence->getEndDate()->format('d.m.'),
			]);
			$notification->setObject('absence', (string)$absence->getId());

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send ' . $subject . ' notification', [
				'exception' => $e,
				'absenceId' => $absence->getId(),
			]);
		}
	}

	private function sendTimeEntryDecisionNotification(int $employeeId, int $year, int $month, string $subject): void {
		try {
			$employee = $this->employeeMapper->find($employeeId);

			$notification = $this->createNotification($subject, $employee->getUserId(), [
				'month' => $month,
				'year' => $year,
			]);
			$notification->setObject('time_entry', $employeeId . '-' . $year . '-' . $month);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send ' . $subject . ' notification', [
				'exception' => $e,
				'employeeId' => $employeeId,
			]);
		}
	}

	private function getSupervisorUserId(?int $supervisorId): ?string {
		if ($supervisorId === null) {
			return null;
		}

		try {
			$supervisor = $this->employeeMapper->find($supervisorId);
			return $supervisor->getUserId();
		} catch (DoesNotExistException) {
			$this->logger->warning('Supervisor not found', ['supervisorId' => $supervisorId]);
			return null;
		}
	}

	private function createNotification(string $subject, string $userId, array $parameters = []): \OCP\Notification\INotification {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID);
		$notification->setUser($userId);
		$notification->setDateTime(new \DateTime());
		$notification->setSubject($subject, $parameters);

		return $notification;
	}
}
