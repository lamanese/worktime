<?php

declare(strict_types=1);

namespace OCA\WorkTime\Notification;

use OCA\WorkTime\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'WorkTime';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case 'absence_submitted':
				$notification->setParsedSubject(
					$l->t(
						'%1$s hat eine Abwesenheit (%2$s, %3$s - %4$s) zur Genehmigung eingereicht',
						[
							$params['employeeName'],
							$params['typeName'],
							$params['startDate'],
							$params['endDate'],
						]
					)
				);
				break;

			case 'absence_approved':
				$notification->setParsedSubject(
					$l->t(
						'Deine Abwesenheit (%1$s, %2$s - %3$s) wurde genehmigt',
						[
							$params['typeName'],
							$params['startDate'],
							$params['endDate'],
						]
					)
				);
				break;

			case 'absence_rejected':
				$notification->setParsedSubject(
					$l->t(
						'Deine Abwesenheit (%1$s, %2$s - %3$s) wurde abgelehnt',
						[
							$params['typeName'],
							$params['startDate'],
							$params['endDate'],
						]
					)
				);
				break;

			case 'absence_informational':
				$notification->setParsedSubject(
					$l->t(
						'Information: %1$s ist abwesend (%2$s, %3$s - %4$s)',
						[
							$params['employeeName'],
							$params['typeName'],
							$params['startDate'],
							$params['endDate'],
						]
					)
				);
				break;

			case 'absence_cancelled':
				$notification->setParsedSubject(
					$l->t(
						'%1$s hat Abwesenheit (%2$s, %3$s - %4$s) storniert',
						[
							$params['employeeName'],
							$params['typeName'],
							$params['startDate'],
							$params['endDate'],
						]
					)
				);
				break;

			case 'time_entries_submitted':
				$monthYear = $this->formatMonthYear($languageCode, (int)$params['year'], (int)$params['month']);
				$notification->setParsedSubject(
					$l->t(
						'%1$s hat Zeiteinträge für %2$s zur Genehmigung eingereicht',
						[
							$params['employeeName'],
							$monthYear,
						]
					)
				);
				break;

			case 'time_entries_approved':
				$monthYear = $this->formatMonthYear($languageCode, (int)$params['year'], (int)$params['month']);
				$notification->setParsedSubject(
					$l->t(
						'Deine Zeiteinträge für %s wurden genehmigt',
						[$monthYear]
					)
				);
				break;

			case 'time_entries_rejected':
				$monthYear = $this->formatMonthYear($languageCode, (int)$params['year'], (int)$params['month']);
				$notification->setParsedSubject(
					$l->t(
						'Deine Zeiteinträge für %s wurden abgelehnt',
						[$monthYear]
					)
				);
				break;

			case 'time_entries_reopened':
				$monthYear = $this->formatMonthYear($languageCode, (int)$params['year'], (int)$params['month']);
				$notification->setParsedSubject(
					$l->t(
						'Die Genehmigung deiner Zeiteinträge für %s wurde zurückgenommen. Bitte erneut einreichen.',
						[$monthYear]
					)
				);
				break;

			default:
				throw new UnknownNotificationException();
		}

		$notification->setIcon(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
		);
		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute('worktime.page.index')
		);

		return $notification;
	}

	private function formatMonthYear(string $languageCode, int $year, int $month): string {
		try {
			$formatter = new \IntlDateFormatter(
				$languageCode,
				\IntlDateFormatter::NONE,
				\IntlDateFormatter::NONE,
				null,
				\IntlDateFormatter::GREGORIAN,
				'MMMM yyyy'
			);
			$date = (new \DateTime())->setDate($year, $month, 1);
			$result = $formatter->format($date);
			return $result !== false ? $result : sprintf('%d/%d', $month, $year);
		} catch (\Throwable $e) {
			return sprintf('%d/%d', $month, $year);
		}
	}
}
