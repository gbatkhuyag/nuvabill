<?php

namespace App\Provisioning\Hardware\Drivers;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds the ipmitool program on this server. It is looked for on the PATH and in the usual system
 * folders only; staff cannot set a path, so nobody can point Nuvabill at another program.
 */
class IpmiTool
{
    /**
     * @var list<string>
     */
    private const FIXED_PATHS = ['/usr/bin/ipmitool', '/usr/sbin/ipmitool', '/usr/local/bin/ipmitool'];

    private string|false|null $found = null;

    /**
     * The full path of ipmitool, or null when this server has none.
     */
    public function path(): ?string
    {
        $this->found ??= $this->find() ?? false;

        return $this->found === false ? null : $this->found;
    }

    /**
     * Whether PHP may start programs here (proc_open is not switched off).
     */
    public function canRun(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    private function find(): ?string
    {
        $candidates = [(new ExecutableFinder)->find('ipmitool'), ...self::FIXED_PATHS];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
