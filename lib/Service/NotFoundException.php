<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Service;

use Exception;

class NotFoundException extends Exception {

    public function __construct(string $message = 'Entity not found') {
        parent::__construct($message);
    }
}
