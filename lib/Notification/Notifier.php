<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Notification;

use OCA\Zeitwerk\AppInfo\Application;
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
		return 'Zeitwerk';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		try {
			return $this->prepareZeitwerkNotification($notification, $languageCode);
		} catch (UnknownNotificationException $e) {
			// Genuinely unknown subject — let NC handle it.
			throw $e;
		} catch (\InvalidArgumentException $e) {
			// Safety net (WorkTime #551): an NC INotification setter rejected a
			// value while building a known notification. NC 34+ deprecates letting
			// \InvalidArgumentException escape prepare() and logs it on every
			// cron run, so convert it and discard the undisplayable notification
			// cleanly.
			throw new UnknownNotificationException();
		}
	}

	/**
	 * Build a known Zeitwerk notification. Any \InvalidArgumentException raised
	 * by an NC setter while building it is caught and handled by prepare().
	 */
	private function prepareZeitwerkNotification(INotification $notification, string $languageCode): INotification {
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
				$notification->setParsedSubject(
					$l->t(
						'%1$s hat Zeiteinträge für %2$s zur Genehmigung eingereicht',
						[
							$params['employeeName'],
							$params['monthYear'],
						]
					)
				);
				break;

			case 'time_entries_approved':
				$notification->setParsedSubject(
					$l->t(
						'Deine Zeiteinträge für %s wurden genehmigt',
						[$params['monthYear']]
					)
				);
				break;

			case 'time_entries_rejected':
				$notification->setParsedSubject(
					$l->t(
						'Deine Zeiteinträge für %s wurden abgelehnt',
						[$params['monthYear']]
					)
				);
				break;

			case 'time_entries_reopened':
				if (!empty($params['reason'])) {
					$notification->setParsedSubject(
						$l->t(
							'Die Genehmigung deiner Zeiteinträge für %1$s wurde zurückgenommen (Grund: %2$s). Bitte erneut einreichen.',
							[$params['monthYear'], $params['reason']]
						)
					);
				} else {
					$notification->setParsedSubject(
						$l->t(
							'Die Genehmigung deiner Zeiteinträge für %s wurde zurückgenommen. Bitte erneut einreichen.',
							[$params['monthYear']]
						)
					);
				}
				break;

			case 'archive_failed':
				$notification->setParsedSubject(
					$l->t(
						'PDF-Archivierung für %1$s (%2$s) ist fehlgeschlagen',
						[$params['employeeName'], $params['monthYear']]
					)
				);
				if (!empty($params['error'])) {
					$notification->setParsedMessage(
						$l->t('Fehler: %s', [$params['error']])
					);
				}
				break;

			default:
				throw new UnknownNotificationException();
		}

		// WorkTime #551: NC 34's setIcon() rejects anything that is not an
		// absolute http(s) URL, and imagePath() returns a relative path. Passing
		// the raw imagePath() throws before setLink() runs — the notification
		// then loses both its icon and its link. getAbsoluteURL() fixes that.
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
			)
		);
		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute('zeitwerk.page.index')
		);

		return $notification;
	}
}
