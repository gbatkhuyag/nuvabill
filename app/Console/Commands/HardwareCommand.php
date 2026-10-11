<?php

namespace App\Console\Commands;

use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\PowerControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('nuvabill:hardware')]
#[Description('Free machines whose dedicated server ended, and switch off suspended or released ones')]
class HardwareCommand extends Command
{
    /**
     * Machines whose power is checked in one run, and the seconds after which no new one starts.
     */
    public const POWER_LIMIT = 20;

    public const POWER_BUDGET_SECONDS = 120;

    /**
     * A BMC that fails never stops the run: each machine is tried again next time.
     */
    public function handle(MachineInventory $inventory, PowerControl $power): int
    {
        try {
            $fixed = $inventory->sweep();
        } catch (Throwable $exception) {
            report(new RuntimeException('The machine sweep failed: '.$exception::class));
            $fixed = 0;
        }

        $checked = $power->enforce(self::POWER_LIMIT, self::POWER_BUDGET_SECONDS);

        $this->components->info("Machines fixed: {$fixed}, power checks: {$checked}");

        return self::SUCCESS;
    }
}
