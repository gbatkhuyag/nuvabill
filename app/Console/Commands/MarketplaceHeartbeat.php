<?php

namespace App\Console\Commands;

use App\Marketplace\MarketplaceClient;
use App\Marketplace\SiteConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:marketplace-heartbeat')]
#[Description('Tell the marketplace store this connected site is still here, renew its key when asked, and notice a new server address')]
class MarketplaceHeartbeat extends Command
{
    public function handle(SiteConnection $connection, MarketplaceClient $client): int
    {
        if (! $connection->isConnected()) {
            $this->components->info('This site is not connected to the marketplace.');

            return self::SUCCESS;
        }

        $status = $connection->heartbeat($client);
        $this->components->info('Marketplace connection: '.($status ?? 'unknown').'.');

        return self::SUCCESS;
    }
}
