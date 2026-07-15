<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCA\Zeitwerk\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'zeitwerk';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Load Composer autoloader for TCPDF (must be in register(), not global scope)
        include_once __DIR__ . '/../../vendor/autoload.php';

        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void {
        // Boot logic
    }
}
