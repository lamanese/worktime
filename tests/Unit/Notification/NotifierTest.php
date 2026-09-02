<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Notification;

use OCA\Zeitwerk\AppInfo\Application;
use OCA\Zeitwerk\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Notifier behaviour on Nextcloud 34 (uebernommen aus WorkTime #551).
 */
class NotifierTest extends TestCase {

    private Notifier $notifier;

    protected function setUp(): void {
        $this->notifier = new Notifier(
            $this->createMock(IURLGenerator::class),
            $this->l10nFactory(),
        );
    }

    private function l10nFactory(): IFactory {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l10n);
        return $factory;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildNotification(string $subject, array $params, bool $subjectThrows = false): INotification {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn(Application::APP_ID);
        $notification->method('getSubject')->willReturn($subject);
        $notification->method('getSubjectParameters')->willReturn($params);
        if ($subjectThrows) {
            // Mirrors NC's INotification setters, which validate their input and
            // throw \InvalidArgumentException on an empty/oversized value.
            $notification->method('setParsedSubject')->willThrowException(new \InvalidArgumentException('invalid subject'));
        } else {
            $notification->method('setParsedSubject')->willReturnSelf();
        }
        $notification->method('setParsedMessage')->willReturnSelf();
        $notification->method('setIcon')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();
        return $notification;
    }

    public function testForeignAppNotificationIsUnknown(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('some_other_app');

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'de');
    }

    public function testUnknownSubjectIsUnknown(): void {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($this->buildNotification('subject_that_does_not_exist', []), 'de');
    }

    public function testKnownNotificationIsPrepared(): void {
        $notification = $this->buildNotification('time_entries_approved', ['monthYear' => 'März 2026']);
        $this->assertSame($notification, $this->notifier->prepare($notification, 'de'));
    }

    public function testSetterRejectionIsDiscardedAsUnknown(): void {
        // #551 safety net: if an NC INotification setter rejects a value while
        // building a known notification, the resulting \InvalidArgumentException
        // must not escape prepare() (deprecated on NC 34+). It is converted to
        // UnknownNotificationException so the undisplayable notification is
        // discarded cleanly instead of spamming the log every cron run.
        $notification = $this->buildNotification('time_entries_approved', ['monthYear' => 'März 2026'], subjectThrows: true);

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'de');
    }

    public function testIconIsSetAsAbsoluteUrl(): void {
        // #551 root cause: NC 34's setIcon() rejects a non-absolute URL, but
        // imagePath() returns a relative path. The notifier must wrap it in
        // getAbsoluteURL(); otherwise setIcon() throws and the notification
        // loses both its icon and its link on NC 34.
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')
            ->willReturn('/custom_apps/zeitwerk/img/app-dark.svg');
        $urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(static fn (string $path): string => 'http://localhost' . $path);
        $urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('http://localhost/apps/zeitwerk/');

        $notifier = new Notifier($urlGenerator, $this->l10nFactory());

        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn(Application::APP_ID);
        $notification->method('getSubject')->willReturn('time_entries_approved');
        $notification->method('getSubjectParameters')->willReturn(['monthYear' => 'März 2026']);
        $notification->method('setParsedSubject')->willReturnSelf();
        $notification->method('setParsedMessage')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();
        $notification->expects($this->once())
            ->method('setIcon')
            ->with($this->stringStartsWith('http'))
            ->willReturnSelf();

        $notifier->prepare($notification, 'de');
    }
}
