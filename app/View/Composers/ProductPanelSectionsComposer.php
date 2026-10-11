<?php

namespace App\View\Composers;

use App\Contracts\HasPanelSections;
use App\Extensions\ExtensionManager;
use App\Models\Product;
use App\Provisioning\PanelGate;
use Illuminate\View\View;
use Throwable;

/**
 * Admin → product form → the "Client panel" card: for every installed server module with panel
 * sections, its sections in display order with the switch value for this product. Modules list
 * their sections without calling a server, so the form never waits for one.
 *
 * Gives the view panelSectionModules: module slug => list of {key, label, icon, help, default,
 * enabled}. enabled is the value posted last (after a validation error), else the product's saved
 * switch for its own module, else the module's default.
 */
class ProductPanelSectionsComposer
{
    public function __construct(
        private ExtensionManager $extensions,
        private PanelGate $gate,
    ) {}

    public function compose(View $view): void
    {
        $product = $view->getData()['product'] ?? null;
        $product = $product instanceof Product ? $product : new Product;
        $old = old('panel_sections');
        $modules = [];

        foreach ($this->extensions->serverModuleNames()->keys() as $slug) {
            try {
                $module = $this->extensions->serverModule($slug);
            } catch (Throwable) {
                // A broken module shows on the extensions page; the form works without it.
                continue;
            }

            if (! $module instanceof HasPanelSections) {
                continue;
            }

            $own = $product->server_module === $slug;
            $sections = [];

            foreach ($this->gate->declaredSections($own ? $product : new Product(['server_module' => $slug]), $module) as $section) {
                $enabled = $own ? $product->panelSectionEnabled($section->key, $section->enabledByDefault) : $section->enabledByDefault;

                if (is_array($old) && array_key_exists($section->key, $old)) {
                    $enabled = filter_var($old[$section->key], FILTER_VALIDATE_BOOLEAN);
                }

                $sections[] = [
                    'key' => $section->key,
                    'label' => $section->label,
                    'icon' => $section->icon,
                    'help' => $section->staffHelp,
                    'default' => $section->enabledByDefault,
                    'enabled' => $enabled,
                ];
            }

            $modules[$slug] = $sections;
        }

        $view->with('panelSectionModules', $modules);
    }
}
