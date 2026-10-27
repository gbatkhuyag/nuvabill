<?php

namespace App\Support;

use App\Models\Admin;
use Closure;
use Throwable;

/**
 * Items add-ons add to the staff dashboard's "Needs attention" list (and the phone app's Today
 * screen), for example the open hardware faults of Dedicated Server Power. Register in the
 * add-on's boot():
 *
 *     app(AttentionItems::class)->add(fn (Admin $admin): array => $admin->hasPermission('hardware.manage') ? [[
 *         'key' => 'my-addon.faults', 'tone' => 'crit', 'text' => __('...'), 'url' => route('...'), 'action' => __('Review'),
 *     ]] : []);
 *
 * The add-on checks the staff member's permissions itself. Items are checked: the key is made of
 * plain characters and starts with "ext.", the tone is warn or crit, the text is short plain text
 * (escaped when shown), and the link stays on this site. A resolver that fails is reported and
 * left out, so it never breaks the dashboard.
 */
final class AttentionItems
{
    /**
     * @var list<Closure(Admin): list<array<string, mixed>>>
     */
    private array $resolvers = [];

    /**
     * @param  Closure(Admin): list<array<string, mixed>>  $resolver
     */
    public function add(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * The checked items of every add-on for this staff member, at most 5 per add-on.
     *
     * @return list<array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    public function for(Admin $admin): array
    {
        $items = [];

        foreach ($this->resolvers as $resolver) {
            try {
                $added = $resolver($admin);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach (array_slice(is_array($added) ? array_values($added) : [], 0, 5) as $item) {
                $checked = is_array($item) ? self::check($item) : null;

                if ($checked !== null) {
                    $items[] = $checked;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $item
     * @return array{key: string, tone: string, text: string, url: string|null, action: string|null}|null
     */
    private static function check(array $item): ?array
    {
        $key = $item['key'] ?? null;
        $tone = $item['tone'] ?? null;
        $text = $item['text'] ?? null;
        $url = $item['url'] ?? null;
        $action = $item['action'] ?? null;

        if (! is_string($key) || preg_match('/^ext\.[a-z0-9][a-z0-9._-]{0,60}\z/', $key) !== 1 || ! in_array($tone, ['warn', 'crit'], true)) {
            return null;
        }

        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 300 || ($action !== null && (! is_string($action) || mb_strlen($action) > 60))) {
            return null;
        }

        if ($url !== null && (! is_string($url) || ! self::local($url))) {
            return null;
        }

        return ['key' => $key, 'tone' => $tone, 'text' => strip_tags($text), 'url' => $url, 'action' => $action !== null ? strip_tags($action) : null];
    }

    /**
     * Whether a link stays on this site: a path, or a full address on the site's own host.
     */
    private static function local(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($host) && in_array($scheme, ['http', 'https'], true) && strcasecmp($host, (string) parse_url(url('/'), PHP_URL_HOST)) === 0;
    }
}
