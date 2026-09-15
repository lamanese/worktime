<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
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
 * Dienstplan: Wochensperre («Schluessel», 0.19.0).
 * zw_duty_week_locks: eine Zeile pro gesperrter ISO-Woche (week_start = Montag,
 * eindeutig). Gesperrte Wochen lassen keine Aenderungen an Karten mehr zu.
 * Kein Backfill, kein Downgrade.
 */
class Version000028Date20260915000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('zw_duty_week_locks')) {
            $table = $schema->createTable('zw_duty_week_locks');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('week_start', Types::DATE, ['notnull' => true]);
            $table->addColumn('locked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('locked_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['week_start'], 'zw_dwl_week_uniq');
        }

        return $schema;
    }
}
