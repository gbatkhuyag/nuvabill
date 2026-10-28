# Contributing to Nuvabill

Thank you for helping. Bug reports, translations, documentation fixes and pull requests are all welcome.

**New here?** Start with an issue labelled [good first issue](https://github.com/meroxis/nuvabill/labels/good%20first%20issue).
These are small, checked tasks with the files, what done looks like, and how to test. Say in the issue that you are
working on it, so two people do not do the same task.

Found a security problem? Please do **not** open an issue; follow the [security policy](SECURITY.md) instead.

## Reporting a bug

Open an [issue](https://github.com/meroxis/nuvabill/issues) and include:

- your Nuvabill version (shown on the **Updates** page), PHP version and database (MySQL, MariaDB or SQLite)
- what you did, what you expected, and what happened instead
- any error from `storage/logs/laravel.log` (remove passwords, keys and client details first)

Feature ideas are welcome too. Check the [roadmap](ROADMAP.md) first; it may already be planned.

## Setting up a development copy

```
git clone https://github.com/meroxis/nuvabill.git
cd nuvabill
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Set `NUVABILL_INSTALLED=true` and `APP_ENV=local` in `.env`, then load the demo data and start the server:

```
php artisan migrate:fresh --seed
php artisan serve
```

Demo sign-ins: staff `admin@nuvabill.test`, client `client@nuvabill.test`, both with password `nuvabill-demo`.

## Making a change

1. Fork the repository and create a branch from `main`.
2. Follow the code around your change: the same naming, structure and style. The project rules are in `AGENTS.md`.
3. Add or update tests for what you changed, and run them: `php artisan test`.
4. Format the code: `vendor/bin/pint`.
5. Open a pull request that says what changed and why. Keep it to one topic.

A few rules that matter a lot in billing software:

- Store money as whole numbers in the smallest unit (cents) with a currency code; never use floats.
- Record every payment through `App\Billing\PaymentRecorder`.
- Database changes must work on MySQL, MariaDB and SQLite, and must be safe to run during an automatic update.
- Admin routes that change settings, sign-ins or call another server must be added to `Demo::LOCKED_ROUTES`.
- Write texts for people in plain, short English; many users read English as a second language.

## Translations

Nuvabill is in 12 languages: English, Arabic, Kurdish (Sorani), Chinese (Simplified), Spanish, French, Portuguese
(Brazil), Russian, German, Turkish, Thai and Mongolian. The list is in `app/Support/Locales.php`.

Where the texts are:

- `lang/<language>.json`: almost every text, with the English text as the key.
- `lang/<language>/*.php`: Laravel's own messages (form errors, password reset, page links).
- `themes/<theme>/lang/<language>.json` and `orderforms/<form>/lang/<language>.json`: words a theme or order form
  brings itself.
- `database/seeders/email-templates/<language>.php`: the email templates.

To improve a translation, change the value and open a pull request. Please:

- keep placeholders such as `:count` and `:name`, and HTML tags such as `<strong>`, exactly as they are;
- keep the same number of plural forms, or use one form without `|` if your language has no plurals;
- never add a script, a link or Blade code to a text.

A new language needs more than one file (emails, PDFs, right-to-left pages, every add-on), so open an issue first and
we will plan it together.

## Extensions and themes

Gateways, server modules, registrars, add-ons and themes can be written without changing Nuvabill itself. Start with
the [developer guide](docs/developers/README.md): it has a step-by-step first add-on, every hook and contract, and how
to sell on the marketplace, where you keep 83% of each sale by default.

## License of your contribution

Nuvabill is licensed under the AGPL-3.0 with an attribution term (see `NOTICE`). By sending a pull request you agree
that your contribution may be distributed by RapidNet Ltd under the AGPL-3.0 license and under the Nuvabill commercial
White-label License.
