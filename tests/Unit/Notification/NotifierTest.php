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
use ReflectionMethod;

/**
 * Notifier behaviour on Nextcloud 34 (uebernommen aus WorkTime #551) und
 * Monatsnamen in Empfaengersprache (uebernommen aus WorkTime #537).
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

    // --- Month names in the recipient's language (WorkTime #537) -----------
    //
    // They used to be a hardcoded German array in NotificationService, so an
    // English or Czech recipient was told their time entries for "März 2026"
    // were approved. The name is now resolved per recipient in the Notifier.

    /**
     * @param array<string, mixed> $params
     */
    private function format(array $params, string $languageCode): string {
        $method = new ReflectionMethod(Notifier::class, 'formatMonthYear');
        $method->setAccessible(true);
        return $method->invoke($this->notifier, $params, $languageCode);
    }

    public function testKeepsPreRenderedMonthYearOfOlderNotifications(): void {
        // Notifications written before this change are still in the database and
        // carry a pre-rendered string. Dropping it would make prepare() fail and
        // hide the whole entry from the notification panel.
        $this->assertSame('Mai 2026', $this->format(['monthYear' => 'Mai 2026'], 'en'));
    }

    public function testFallsBackToYearOnUnusableMonth(): void {
        $this->assertSame('2026', $this->format(['month' => 0, 'year' => 2026], 'de'));
        $this->assertSame('2026', $this->format(['month' => 13, 'year' => 2026], 'de'));
    }

    public function testDoesNotFailOnMissingParameters(): void {
        $this->assertSame('0', $this->format([], 'de'));
    }

    /**
     * @dataProvider localisedMonths
     */
    public function testUsesRecipientLanguage(string $languageCode, string $expected): void {
        if (!class_exists(\IntlDateFormatter::class)) {
            $this->markTestSkipped('intl not available in this PHP build; the numeric fallback applies instead');
        }

        $this->assertSame($expected, $this->format(['month' => 3, 'year' => 2026], $languageCode));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function localisedMonths(): array {
        return [
            'german' => ['de', 'März 2026'],
            'english' => ['en', 'March 2026'],
            'czech' => ['cs', 'březen 2026'],
        ];
    }

    public function testNumericFallbackWithoutIntl(): void {
        if (class_exists(\IntlDateFormatter::class)) {
            $this->markTestSkipped('intl is available, so the localised path is used');
        }

        $this->assertSame('03/2026', $this->format(['month' => 3, 'year' => 2026], 'de'));
    }
}
