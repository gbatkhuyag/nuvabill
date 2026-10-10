<?php

namespace Tests\Feature\Panel;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Tests\Fixtures\PanelFake\PanelFakeModule;

/**
 * Shared setup of the client server panel tests: the panelfake module from the fixtures, the
 * client "Mer Las", and services on a panelfake product. The fake implements UsesMachineInventory,
 * so its services run without a server record.
 */
trait UsesPanelFake
{
    protected Client $client;

    protected function setUpPanelFake(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test', 'company_name' => null]);
    }

    /**
     * An active panelfake service of the client (or another client).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $product
     */
    protected function panelService(array $attributes = [], array $product = [], ?Client $client = null): Service
    {
        $product = Product::factory()->priced(1000)->create($product + ['server_module' => 'panelfake', 'uses_machines' => true, 'requires_domain' => false]);

        return Service::factory()->create($attributes + [
            'client_id' => ($client ?? $this->client)->id,
            'product_id' => $product->id,
            'server_id' => null,
            'domain' => 'vps.example.test',
            'status' => ServiceStatus::Active,
        ]);
    }

    protected function contextFor(Service $service, ?Client $client = null): ClientContext
    {
        return new ClientContext(($client ?? $this->client)->id, '203.0.113.9', true, route('client.services.show', $service), 'en');
    }
}
