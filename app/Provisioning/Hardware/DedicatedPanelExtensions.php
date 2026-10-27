<?php

namespace App\Provisioning\Hardware;

use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Product;
use Throwable;

/**
 * The add-ons that add tabs to the client server panel of dedicated servers (see
 * DedicatedPanelExtension). Each tab belongs to the first add-on that declared its key; a key the
 * dedicated module uses itself is never given to an add-on.
 */
final class DedicatedPanelExtensions
{
    /**
     * The keys of the dedicated module's own tabs.
     *
     * @var list<string>
     */
    public const RESERVED = ['overview', 'network', 'reinstall', 'power'];

    /**
     * @var list<DedicatedPanelExtension>
     */
    private array $extensions = [];

    public function add(DedicatedPanelExtension $extension): void
    {
        $this->extensions[] = $extension;
    }

    /**
     * Every add-on tab of the product with the add-on it belongs to, keyed by tab key. An add-on
     * that fails is reported and left out.
     *
     * @return array<string, array{section: PanelSection, extension: DedicatedPanelExtension}>
     */
    public function sections(Product $product): array
    {
        $found = [];

        foreach ($this->extensions as $extension) {
            try {
                $sections = $extension->sections($product);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($sections as $section) {
                if ($section instanceof PanelSection && ! in_array($section->key, self::RESERVED, true) && ! isset($found[$section->key])) {
                    $found[$section->key] = ['section' => $section, 'extension' => $extension];
                }
            }
        }

        return $found;
    }

    /**
     * The add-on that owns a tab of the product, or null.
     */
    public function owner(Product $product, string $key): ?DedicatedPanelExtension
    {
        return $this->sections($product)[$key]['extension'] ?? null;
    }
}
