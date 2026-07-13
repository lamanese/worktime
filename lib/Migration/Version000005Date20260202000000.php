<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add submitted_at column to archive_queue table
 */
class Version000005Date20260202000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('zw_archive_queue')) {
            $table = $schema->getTable('zw_archive_queue');

            if (!$table->hasColumn('submitted_at')) {
                $table->addColumn('submitted_at', Types::DATETIME, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
