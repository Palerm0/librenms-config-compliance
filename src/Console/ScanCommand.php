<?php

/*
 * ScanCommand.php
 *
 * Het commando voor de dagelijkse scan via cron. Draai het met:
 *     /opt/librenms/lnms config-compliance:scan
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance\Console;

use Illuminate\Console\Command;
use Palerm0\LibrenmsConfigCompliance\ComplianceEngine;

class ScanCommand extends Command
{
    /** @var string */
    protected $signature = 'config-compliance:scan';

    /** @var string */
    protected $description = 'Runs the config compliance scan against all devices';

    public function handle(ComplianceEngine $engine): int
    {
        $this->info('Config compliance scan started...');

        $report = $engine->run();
        $summary = $report['summary'];

        $this->info(sprintf(
            'Done: %d devices | %d compliant | %d non-compliant | %d without config | %d rules.',
            $summary['devices'],
            $summary['compliant'],
            $summary['non_compliant'],
            $summary['no_config'],
            $summary['rules'],
        ));

        return self::SUCCESS;
    }
}
