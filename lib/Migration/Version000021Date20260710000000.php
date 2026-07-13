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
 * Persoenliche Standard-Vorgaben fuer neue Zeiteintraege (durch den Admin
 * freischaltbar, siehe CompanySetting::KEY_ALLOW_EMPLOYEE_DEFAULT_*):
 * - zw_employees.default_project_id: vorgewaehltes Projekt beim Anlegen
 * - zw_employees.default_description: vorausgefuellte Beschreibung
 */
class Version000021Date20260710000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('zw_employees')) {
            $employees = $schema->getTable('zw_employees');
            if (!$employees->hasColumn('default_project_id')) {
                $employees->addColumn('default_project_id', Types::INTEGER, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            if (!$employees->hasColumn('default_description')) {
                $employees->addColumn('default_description', Types::STRING, [
                    'notnull' => false,
                    'default' => null,
                    'length' => 500,
                ]);
            }
        }

        return $schema;
    }
}
