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
 * Dienstplan: manuelle Reihenfolge der Zeilen (Mitarbeiter) im Wochenplan.
 * zw_employees.duty_roster_order: kleinere Zahlen stehen weiter oben,
 * gleiche Zahlen werden nach Nachname/Vorname sortiert. Kein Backfill.
 */
class Version000027Date20260914150000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('zw_employees')) {
            $employees = $schema->getTable('zw_employees');
            if (!$employees->hasColumn('duty_roster_order')) {
                $employees->addColumn('duty_roster_order', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        return $schema;
    }
}
