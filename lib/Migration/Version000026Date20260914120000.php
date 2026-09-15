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
 * Auftragsvorlagen fuer den Dienstplan (0.19.0):
 * - zw_duty_job_templates: Standard-Auftraege, in den Einstellungen gepflegt,
 *   in der Wochenansicht per Drag and Drop in Zellen gezogen.
 * Kein Backfill, kein Downgrade.
 */
class Version000026Date20260914120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('zw_duty_job_templates')) {
            $table = $schema->createTable('zw_duty_job_templates');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 200]);
            $table->addColumn('start_time', Types::STRING, ['notnull' => false, 'length' => 5, 'default' => null]);
            $table->addColumn('duration_minutes', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 500, 'default' => null]);
            $table->addColumn('on_call', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('is_visible', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['is_visible', 'title'], 'zw_djt_vis_title_idx');
        }

        return $schema;
    }
}
