<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Store\ListingLimits;
use App\Marketplace\Store\SigningKey;
use App\Models\Client;
use App\Models\Developer;
use App\Models\MarketplaceItem;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Listing texts and prices are checked against the store's limits (the column sizes) before
 * anything is saved: by nuvabill:marketplace-publish, by the developer forms and by the staff form.
 * A text that is too long gets a clear message, never a database error.
 */
class ListingLimitsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nuvabill-listings-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/storage/app/private');
        $this->app->useStoragePath($this->dir.'/storage');

        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();
        app(SigningKey::class)->generate();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_publishing_skips_a_listing_with_a_long_summary_and_publishes_the_others(): void
    {
        $this->listing('too-long', ['summary' => str_repeat('a', 247)]);
        $this->listing('fine', ['summary' => str_repeat('b', ListingLimits::SUMMARY), 'name' => str_repeat('N', ListingLimits::NAME), 'category' => 'Server modules']);

        $this->artisan('nuvabill:marketplace-publish', ['listing' => [$this->dir.'/too-long.json', $this->dir.'/fine.json']])
            ->expectsOutputToContain('247 characters')
            ->assertFailed();

        // Nothing of the long listing was saved: no item, no package and no media.
        $this->assertFalse(MarketplaceItem::query()->where('slug', 'too-long')->exists());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/marketplace/packages/too-long'));

        $fine = MarketplaceItem::query()->where('slug', 'fine')->sole();
        $this->assertSame(MarketplaceItem::STATUS_LIVE, $fine->status);
        $this->assertSame(ListingLimits::SUMMARY, mb_strlen((string) $fine->summary));
    }

    public function test_every_listing_field_is_checked_against_its_limit(): void
    {
        File::put($this->dir.'/notes.txt', 'not an image');
        File::put($this->dir.'/fake.png', 'not an image either');
        File::ensureDirectoryExists($this->dir.'/inner');
        $this->png($this->dir.'/inner/shot.png');
        File::put(dirname($this->dir).'/outside-'.basename($this->dir).'.png', 'x');

        $problems = ListingLimits::problems([
            'name' => str_repeat('n', 121),
            'category' => str_repeat('c', 41),
            'summary' => str_repeat('s', 201),
            'description' => str_repeat('d', 5001),
            'changelog' => str_repeat('l', 2001),
            'price' => -1,
            'update_price' => 'free',
            'demo_url' => 'http://demo.example.test',
            'docs_url' => 'https://docs.example.test/'.str_repeat('p', 250),
            'icon' => ['glyph' => '<script>', 'bg' => 'red', 'shape' => 'round'],
            'screenshots' => ['inner/shot.png', 'notes.txt', 'fake.png', '../outside-'.basename($this->dir).'.png', 'missing.png'],
        ], ['slug' => str_repeat('s', 65), 'name' => 'Package', 'version' => '1.0.0-'.str_repeat('r', 40)], $this->dir);

        File::delete(dirname($this->dir).'/outside-'.basename($this->dir).'.png');

        $this->assertEqualsCanonicalizing([
            'slug (from the package) is 65 characters long; the limit is 64.',
            'version (from the package) is 46 characters long; the limit is 40.',
            'name is 121 characters long; the limit is 120.',
            'category is 41 characters long; the limit is 40.',
            'summary is 201 characters long; the limit is 200.',
            'description is 5001 characters long; the limit is 5000.',
            'changelog is 2001 characters long; the limit is 2000.',
            'demo_url must be a link that starts with https://.',
            'docs_url is 276 characters long; the limit is 255.',
            'price is -1; it must be from 0 to 10000.',
            'update_price must be a number.',
            'icon.shape is not an icon part (use glyph, bg and fg).',
            'icon.glyph must be an SVG path (letters, numbers, spaces, dots, commas and minus signs).',
            'icon.bg must be a colour such as #0F1B2D.',
            'screenshots[1] (notes.txt) must be a PNG, JPEG or WebP file.',
            'screenshots[2] (fake.png) is not an image.',
            'screenshots[3] (../outside-'.basename($this->dir).'.png) was not found next to the listing file.',
            'screenshots[4] (missing.png) was not found next to the listing file.',
        ], $problems);
    }

    public function test_a_listing_within_the_limits_has_no_problems(): void
    {
        $this->png($this->dir.'/shot.png');

        $this->assertSame([], ListingLimits::problems([
            'name' => 'Ünïcode name '.str_repeat('é', 100),
            'category' => 'Hosting',
            'summary' => str_repeat('ß', ListingLimits::SUMMARY),
            'price' => '4.99',
            'update_price' => 0,
            'demo_url' => 'https://demo.nuvabill.com',
            'icon' => ['glyph' => 'M12 3v8M6.3 7.3a8 8 0 1 0 11.4 0', 'bg' => '#ECFDF3', 'fg' => '#15803D'],
            'screenshots' => ['shot.png'],
        ], ['slug' => 'fine', 'name' => 'Fine', 'version' => '1.0.0'], $this->dir));

        // A higher update price than price, too many screenshots, or a missing summary are refused.
        $this->assertSame(['update_price (20) is more than price (10).'], ListingLimits::problems(['summary' => 'S', 'price' => 10, 'update_price' => 20], ['slug' => 'a', 'name' => 'A', 'version' => '1.0.0'], $this->dir));
        $this->assertSame(['screenshots has 7 files; the limit is 6.'], ListingLimits::problems(['summary' => 'S', 'screenshots' => array_fill(0, 7, 'shot.png')], ['slug' => 'a', 'name' => 'A', 'version' => '1.0.0'], $this->dir));
        $this->assertSame(['summary is empty.'], ListingLimits::problems([], ['slug' => 'a', 'name' => 'A', 'version' => '1.0.0'], $this->dir));
    }

    public function test_publishing_refuses_a_developer_name_that_does_not_fit(): void
    {
        $this->listing('fine', []);

        $this->artisan('nuvabill:marketplace-publish', ['listing' => [$this->dir.'/fine.json'], '--developer' => str_repeat('d', 121)])->assertFailed();
        $this->assertSame(0, Developer::query()->count());
        $this->assertSame(0, MarketplaceItem::query()->count());
    }

    public function test_the_developer_forms_refuse_long_texts_without_an_error_page(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]);

        // A long developer name still gets a slug that fits its column.
        $this->actingAs($client, 'web')->post(route('developer.join.store'), ['name' => str_repeat('Raz Studio ', 11), 'agree' => '1'])
            ->assertRedirect(route('developer.dashboard'));
        $this->assertLessThanOrEqual(ListingLimits::SLUG, strlen($client->fresh()->developer->slug));

        $this->post(route('developer.items.store'), [
            'name' => str_repeat('n', 121), 'slug' => 'calm', 'type' => 'theme', 'summary' => str_repeat('s', 201),
            'description' => str_repeat('d', 5001), 'changelog' => str_repeat('l', 2001), 'own_code' => '1',
            'package' => new UploadedFile($this->zip('calm'), 'calm.zip', 'application/zip', null, true),
        ])->assertSessionHasErrors(['name', 'summary', 'description', 'changelog']);
        $this->assertSame(0, MarketplaceItem::query()->count());

        $this->post(route('developer.items.store'), [
            'name' => 'Calm', 'slug' => 'calm', 'type' => 'theme', 'summary' => 'A calm theme.', 'own_code' => '1',
            'package' => new UploadedFile($this->zip('calm'), 'calm.zip', 'application/zip', null, true),
        ])->assertRedirect(route('developer.items.show', 'calm'));
        $item = MarketplaceItem::query()->where('slug', 'calm')->sole();

        $this->put(route('developer.items.update', $item), ['name' => 'Calm', 'summary' => str_repeat('s', 201), 'icon_bg' => 'red'])
            ->assertSessionHasErrors(['summary', 'icon_bg']);
        $this->post(route('developer.items.versions.store', $item), [
            'changelog' => str_repeat('l', 2001),
            'package' => new UploadedFile($this->zip('calm', '1.1.0'), 'calm.zip', 'application/zip', null, true),
        ])->assertSessionHasErrors('changelog');
    }

    public function test_the_staff_form_checks_the_limits_and_keeps_an_official_category(): void
    {
        $developer = Developer::query()->create(['name' => 'Nuvabill', 'slug' => 'nuvabill', 'is_official' => true, 'status' => 'active']);
        $item = $developer->items()->create(['slug' => 'power', 'type' => 'theme', 'name' => 'Power', 'summary' => 'Power.', 'category' => 'Hosting', 'price' => 2900, 'update_price' => 900, 'currency' => 'USD', 'status' => MarketplaceItem::STATUS_LIVE]);
        $this->signInAdmin();

        $this->get(route('admin.store.items.edit', $item))->assertOk()->assertSee('<option value="Hosting" selected', false);

        $this->put(route('admin.store.items.update', $item), ['status' => 'live', 'price' => '10001', 'update_price' => '5', 'demo_url' => 'https://demo.example.test/'.str_repeat('p', 250)])
            ->assertSessionHasErrors(['price', 'demo_url']);
        $this->put(route('admin.store.items.update', $item), ['status' => 'live', 'price' => '29', 'update_price' => '9', 'category' => 'Made up'])
            ->assertSessionHasErrors('category');

        $this->put(route('admin.store.items.update', $item), ['status' => 'live', 'price' => '29', 'update_price' => '9', 'category' => 'Hosting'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Hosting', $item->fresh()->category);
    }

    /**
     * A listing file named {slug}.json with its package {slug}.zip next to it.
     *
     * @param  array<string, mixed>  $fields
     */
    private function listing(string $slug, array $fields): void
    {
        $zip = $this->zip($slug);
        File::put($this->dir.'/'.$slug.'.json', (string) json_encode($fields + ['package' => basename($zip), 'summary' => 'A theme.']));
    }

    private function zip(string $slug, string $version = '1.0.0'): string
    {
        $path = $this->dir.'/'.$slug.'-'.$version.'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json', (string) json_encode(['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version, 'requires' => '>=0.3.0']));
        $zip->addFromString('views/layouts/app.blade.php', '<html></html>');
        $zip->close();

        return $path;
    }

    private function png(string $path): void
    {
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
    }
}
