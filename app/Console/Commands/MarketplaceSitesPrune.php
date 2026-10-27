<?php

namespace App\Console\Commands;

use App\Marketplace\Store\ConnectedSites;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:marketplace-sites-prune')]
#[Description('The marketplace store only: end connected sites not seen for 60 days and requests not approved in 14 days, and add missing Cloudflare rules')]
class MarketplaceSitesPrune extends Command
{
    public function handle(ConnectedSites $sites): int
    {
        if (! config('nuvabill.marketplace.store')) {
            $this->components->error('This only runs on the marketplace store.');

            return self::FAILURE;
        }

        $ended = $sites->prune();
        $added = $sites->syncRules();
        $this->components->info("Connected sites: {$ended} ended, {$added} let through.");

        return self::SUCCESS;
    }
}
