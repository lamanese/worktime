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
 * Aussendienst-Spesen und Extern-Kilometer:
 * - zw_projects.is_field_work: 1 = Aussendienst-Projekt, loest die Spesen-Pauschale
 *   (z.B. 14 EUR ab Tagesschwelle) aus.
 * - zw_projects.is_extern: 1 = externes Projekt, erlaubt die tageweise
 *   Kilometer-Erfassung (Verguetung pro km).
 * - zw_daily_km: ein Kilometerwert pro Mitarbeiter und Tag. Bewusst eine eigene
 *   Tabelle statt eines Feldes am Zeiteintrag, weil km auch an reinen
 *   Abwesenheitstagen (externer Abwesenheitstyp) ohne Zeiteintrag anfallen koennen.
 */
class Version000020Date20260709000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('zw_projects')) {
            $projects = $schema->getTable('zw_projects');
            if (!$projects->hasColumn('is_field_work')) {
                $projects->addColumn('is_field_work', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
            if (!$projects->hasColumn('is_extern')) {
                $projects->addColumn('is_extern', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        if (!$schema->hasTable('zw_daily_km')) {
            $table = $schema->createTable('zw_daily_km');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('employee_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('work_date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('kilometers', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['employee_id', 'work_date'], 'zw_dkm_emp_date_idx');
            $table->addIndex(['work_date'], 'zw_dkm_date_idx');
        }

        return $schema;
    }
}
