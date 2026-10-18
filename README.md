<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/rekhu-ai/assistant-laravel/main/art/banner-dark.svg">
    <img alt="Rekhu Assistant for Laravel" src="https://raw.githubusercontent.com/rekhu-ai/assistant-laravel/main/art/banner-light.svg" width="100%">
  </picture>
</p>

<p align="center">
  <a href="https://packagist.org/packages/rekhu/assistant-laravel"><img alt="Latest version" src="https://img.shields.io/packagist/v/rekhu/assistant-laravel?label=version&color=6A1FD0"></a>
  <a href="https://packagist.org/packages/rekhu/assistant-laravel"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/rekhu/assistant-laravel?color=6A1FD0"></a>
  <img alt="PHP 8.2 or newer" src="https://img.shields.io/badge/PHP-8.2%2B-777BB4">
  <img alt="Laravel 12 and 13" src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20">
</p>

<p align="center">
  <a href="#getting-started">Getting started</a> ·
  <a href="#choose-your-setup">Setups</a> ·
  <a href="#actions">Actions</a> ·
  <a href="#a-public-chat-for-visitors">Public chat</a> ·
  <a href="#security">Security</a> ·
  <a href="#configuration">Configuration</a>
</p>

---

**Rekhu Assistant** is a custom AI assistant you build into your own Laravel application, for your own customers. It answers questions from your help, sends people to the right page, and carries out tasks through the routes you mark, **as the signed-in user**, with your validation, policies and middleware. Every conversation is metered per customer by [Rekhu](https://rekhu.com), so you can offer the assistant to your customers and bill them for it.

| Feature | What it means |
|---|---|
| **Actions from your own routes** | Put `#[Tool]` on a controller method. The form request's rules become the input; your controller does the work. |
| **Safe by construction** | The assistant can only do what the user may do in your app. Destructive actions wait for a real click. |
| **Pages and help** | It sends users to the right page and answers from your own markdown help, in their language. |
| **Any front end** | Blade, Livewire, Inertia (Vue or React), or an API with Nuxt, Next or a mobile app. |
| **A public chat** | Optionally for visitors who are not signed in, with caps per visitor, per IP address and per day. |
| **One project per app** | Each app is a project in your Rekhu account, with keys per environment, one monthly limit, a pause, and usage per user or per tenant to bill from. |
| **No inbound connection** | Your app calls Rekhu; nothing calls into your app. It works the same on a laptop and in production. |

## Contents

1. [Requirements](#requirements)
2. [Getting started](#getting-started)
3. [Choose your setup](#choose-your-setup): [Blade](#blade) · [Livewire](#livewire) · [Inertia](#inertia-vue-or-react) · [API with Nuxt, Next or a mobile app](#an-api-with-nuxt-next-or-a-mobile-app)
4. [Actions](#actions)
5. [Pages](#pages)
6. [Help](#help)
7. [A public chat for visitors](#a-public-chat-for-visitors)
8. [Projects, usage and limits](#projects-usage-and-limits)
9. [How it works](#how-it-works)
10. [Security](#security)
11. [Configuration](#configuration)
12. [Commands](#commands)
13. [Troubleshooting](#troubleshooting)

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.2 or newer |
| Laravel | 12 or 13 |
| Cache | any store your app already uses; conversations are kept there |
| Rekhu | an account with an app key and credit; [sign up](https://rekhu.com/register), then top up from $10 under **Billing** |

## Getting started

### 1. Get an app key

[Create a Rekhu account](https://rekhu.com/register) and put credit on it under **Billing** (from $10). The assistant draws from that credit per conversation; without credit it cannot answer.

Then create a **project** under **Projects → New project**: choose **Laravel app**, monolith or multi-tenant, and name it (for example "Shop"). Turn on its assistant on the **Assistant** tab, and on the **Connection** tab add a key for your first environment (`local`). Add one per environment later (`staging`, `production`); they all count toward the same project. A key is shown once, together with the commands of the next step.

### 2. Install the package

```bash
composer require rekhu/assistant-laravel
php artisan rekhu:assistant-install --key=agk_…
```

The install command checks the key with Rekhu, writes `REKHU_ASSISTANT_KEY` to `.env`, publishes `config/rekhu-assistant.php` and creates `resources/rekhu/help`.

### 3. Add the widget

In your layout, before `</body>`:

```blade
<x-rekhu-assistant />
```

The chat button appears for signed-in users.

### 4. Mark a first action

```php
use Rekhu\Assistant\Attributes\Tool;

class OrderController extends Controller
{
    #[Tool('Reorder a past order: the same product and address as a new order; the quantity can be changed')]
    public function reorder(ReorderRequest $request, Order $order)
    {
        // your own code, as for the form on the order page
    }
}
```

### 5. Check what the assistant sees

```bash
php artisan rekhu:assistant --user=1
```

It shows your credit, lists the actions, pages and help topics that user can reach, and tests the connection to Rekhu. Then sign in, open the chat and ask *"order my last business cards again"*.

## Choose your setup

The package runs the chat on your server in every setup. What differs is how the widget is added and how the user is signed in.

| Your application | The widget | Sign-in |
|---|---|---|
| [Blade](#blade) | `<x-rekhu-assistant />` | the session |
| [Livewire](#livewire) | `<x-rekhu-assistant />` | the session |
| [Inertia, with Vue or React](#inertia-vue-or-react) | the Blade tag, or [`@rekhu/assistant-widget`](https://www.npmjs.com/package/@rekhu/assistant-widget) for router navigation | the session |
| [An API with Nuxt, Next or a mobile app](#an-api-with-nuxt-next-or-a-mobile-app) | [`@rekhu/assistant-widget`](https://www.npmjs.com/package/@rekhu/assistant-widget) | a token (Sanctum, Passport or your own) |
| A chat screen of your own | none; speak [protocol 1](PROTOCOL.md) | any of the above |

### Blade

Step 3 of [Getting started](#getting-started) is all there is. The tag uses the user's session and the page's CSRF token, and the package serves the widget's script itself: no npm and no build step.

```blade
{{-- resources/views/layouts/app.blade.php --}}
<body>
    {{ $slot }}

    <x-rekhu-assistant />
</body>
```

The tag accepts attributes that override the config for one layout:

```blade
<x-rekhu-assistant name="Shop assistant" greeting="Hi! How can I help with your order?" color="#0f766e" />
```

### Livewire

Use the same Blade tag in the layout. With `wire:navigate`, keep it on the page while users navigate, so an open conversation stays on screen:

```blade
@persist('rekhu-assistant')
    <x-rekhu-assistant />
@endpersist
```

To refresh a component after the assistant changed data, pass the widget's event on to Livewire:

```js
document.addEventListener('rekhu:action', () => Livewire.dispatch('rekhu-action'));
```

```php
use Livewire\Attributes\On;

#[On('rekhu-action')]
public function refreshAfterAssistant(): void
{
    // an empty listener re-renders the component
}
```

> [!NOTE]
> Actions are controller routes. Livewire component methods are not routes, so they cannot carry `#[Tool]` yet. Put the work in a route, or in a route that calls the same action class, and mark that route. Support for Livewire methods is planned.

### Inertia (Vue or React)

The Blade tag in `resources/views/app.blade.php` works unchanged, but its page buttons reload the whole page. To open pages with Inertia's router and reload the props after an action, mount the npm widget instead:

```bash
npm install @rekhu/assistant-widget
```

```blade
{{-- resources/views/app.blade.php, in <head> --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
@auth
    <meta name="rekhu-user" content="1">
@endauth
```

```js
// resources/js/app.js, after createInertiaApp(…)
import { router } from '@inertiajs/vue3'; // or '@inertiajs/react'
import { mount } from '@rekhu/assistant-widget';

if (document.querySelector('meta[name="rekhu-user"]')) {
    mount({
        endpoint: '/rekhu/assistant/chat',
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        navigate: (path) => router.visit(path),
        onAction: () => router.reload(),
    });
}
```

The middleware stays `web,auth`: the user's session signs them in.

### An API with Nuxt, Next or a mobile app

The front end runs on its own domain and signs in with a token.

**On the Laravel side**, put the chat behind your API guard in `.env`:

```dotenv
REKHU_ASSISTANT_MIDDLEWARE=api,auth:sanctum
```

and allow the front end's domain in `config/cors.php`:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie', 'rekhu/assistant/*'],
'allowed_origins' => ['https://app.example.com'],
```

Your actions can be API routes or web routes. They run as the token's user, and a controller that redirects with a flashed message still reports that message to the assistant.

**In the front end**, mount [`@rekhu/assistant-widget`](https://www.npmjs.com/package/@rekhu/assistant-widget) with the token. In Nuxt, as `plugins/rekhu-assistant.client.ts`:

```ts
import { mount } from '@rekhu/assistant-widget';

export default defineNuxtPlugin(() => {
    const token = useCookie('token'); // wherever your app keeps it
    const router = useRouter();
    if (!token.value) return;

    mount({
        endpoint: 'https://api.example.com/rekhu/assistant/chat',
        headers: () => ({ Authorization: `Bearer ${token.value}` }),
        credentials: 'omit',
        navigateOrigins: ['https://api.example.com'],
        navigate: (path, url) => router.push(url.pathname + url.search),
        onAction: () => refreshNuxtData(),
    });
});
```

**The chat's address.** When your front end's domain is one of Sanctum's stateful domains, Sanctum checks CSRF on every POST from it. If your app exempts only `api/*` from CSRF, put the chat under it:

```dotenv
REKHU_ASSISTANT_PATH=api/rekhu/assistant
```

The endpoint is then `https://api.example.com/api/rekhu/assistant/chat`, and the `api/*` rule in `config/cors.php` already covers it.

**Your front end's pages.** Laravel does not know the pages of a Nuxt or Next front end, so list them, and say where the front end lives:

```dotenv
REKHU_ASSISTANT_FRONTEND_URL=https://app.example.com
```

```php
// config/rekhu-assistant.php
'pages' => [
    'frontend' => [
        'url' => env('REKHU_ASSISTANT_FRONTEND_URL'),
        'list' => [
            ['path' => '/orders', 'label' => 'Orders', 'help' => 'All orders with their status'],
            ['path' => '/orders/{order}', 'label' => 'Order', 'help' => 'One order'],
            ['path' => '/finance', 'label' => 'Finance', 'can' => 'see-finance'],   // only for users who pass this gate
        ],
    ],
    // …
],
```

When your permissions are not gates, give the pages from code, as the user may open them. The user is `null` for a visitor of the public chat and for `php artisan rekhu:assistant` without `--user`:

```php
RekhuAssistant::pagesUsing(fn ($user) => ! $user ? [] : collect(config('my-app.pages'))
    ->filter(fn ($page) => $user->isAbleTo($page['permission']))
    ->all());
```

Laravel's own GET routes (an export download, a payment page) are offered as pages too. When all your users' pages live in the front end, turn that off:

```dotenv
REKHU_ASSISTANT_ROUTE_PAGES=false
```

The widget's `navigate` option opens them in your router (add the front end's origin to `navigateOrigins`).

The widget's README shows the same for Next.js and plain HTML. A mobile app, or a chat screen of your own, uses the same endpoint; [protocol 1](PROTOCOL.md) describes every request and event.

## Actions

Mark a controller method with `#[Tool]`. The assistant calls the route exactly as when the user submits the form: its middleware (`auth`, `can:` and your own), the form request's `authorize()` and rules, route model binding, policies and events all apply. When the app refuses, the assistant is told why and explains it to the user.

```php
use Rekhu\Assistant\Attributes\Tool;

#[Tool('List the user\'s orders with number, product, status and total', name: 'list_orders')]
public function index(Request $request) { … }        // GET: a read action; return JSON when $request->expectsJson()

#[Tool('Change the delivery address of an order that has not shipped yet', rules: ['address' => 'required|string|max:200'])]
public function address(Request $request, Order $order) { … }   // validates inline, so the rules are given here

#[Tool('Cancel an order that has not shipped yet')]
public function cancel(Order $order) { … }           // DELETE: the user confirms with a click first
```

| Argument | Required | Meaning |
|---|---|---|
| `description` | yes | What the action does, written for the assistant. Users see it on the confirm and result cards. |
| `name` | no | The tool's name. Defaults to the route name. |
| `confirm` | no | `true` asks the user to click **Confirm** first. Every `DELETE` route does this by default. |
| `rules` | no | The input, when the method validates inline instead of through a form request. |
| `guests` | no | `true` also offers the action in the [public chat](#a-public-chat-for-visitors). |

**Confirmations.** When an action needs confirming, nothing runs until the user clicks **Confirm** on the card. The action and its exact input are kept on the server; a typed "yes", or anything the model writes, cannot run it.

### Actions from the config

Routes whose controller you do not want to touch (a vendor package's, generated code) become actions from `config/rekhu-assistant.php`, by route name or `VERB uri`. And routes you never want offered are blocked there, whatever their attribute says:

```php
'actions' => [
    'include' => [
        'invoices.download' => 'Download an invoice as PDF',
        'POST api/orders/{order}/cancel' => ['description' => 'Cancel an order that has not shipped', 'confirm' => true],
    ],
    'exclude' => ['admin.*', '*.destroy', 'api/internal/*'],
],
```

An entry takes the same as `#[Tool]`: `description`, and optionally `name`, `confirm`, `rules`, `guests`. `php artisan rekhu:assistant` shows for each action whether it comes from an attribute or the config.

### Your own permission rule

The assistant offers a user only the actions and pages they may use. It reads `can:` middleware itself. When your app checks rights another way (Laratrust, Spatie permissions, a permission derived from the route name), tell it, in a service provider:

```php
use Rekhu\Assistant\RekhuAssistant;

RekhuAssistant::authorizeUsing(fn ($user, $route) => $user->isAbleTo($route->getName()));
```

This only decides what the assistant offers. Every call still runs through the route's own middleware, so a user can never do more through the chat than in the app.

## Pages

Every named GET route is a page the assistant can offer, except routes the user may not open (`can:`, `guest`) and names matching `pages.exclude` in the config. The user gets a button to open the page; the assistant never navigates on its own. A description helps it pick the right page:

```php
use Rekhu\Assistant\Attributes\Page;

#[Page('Invoices', help: 'All invoices of the account, with download and payment status')]
public function index() { … }

#[Page('Internal tools', hidden: true)]   // never offered
public function tools() { … }
```

## Quick questions

Questions a user can click in a fresh chat, written by you in `config/rekhu-assistant.php`:

```php
'quick' => [
    'Where is my last order?',
    ['text' => ['en' => 'Cancel an order', 'nl' => 'Annuleer een bestelling'], 'needs' => ['cancel_order']],
],
```

A text can be one per language. With `needs` (action names) a question is only offered to users who may use one of those actions. The package passes them on as you wrote them; how they look, and anything else your chat opens with, is up to your front end ([design your own chat](#design-your-own-chat)).

## Design your own chat

The package carries the conversation; how the chat looks is yours. The widget can be restyled completely from your own CSS (variables such as `--c`, `--rekhu-bg`, `--rekhu-width`, and parts such as `::part(panel)`), or replaced by a chat screen of your own on its headless client, which speaks to the same route with the same sign-in:

```js
import { createClient } from '@rekhu/assistant-widget';

const chat = createClient({ endpoint: 'https://api.example.com/api/rekhu/assistant/chat', headers: () => ({ Authorization: `Bearer ${token}` }), credentials: 'omit' });
const { greeting, quick } = await chat.history();
await chat.send('Where is my order?', (event) => { /* text, step, card, error, done */ });
```

The [widget's README](https://www.npmjs.com/package/@rekhu/assistant-widget) lists every variable, part and method.

How the assistant behaves, its tone and what it knows about your app and your users: write that in the **knowledge** of the project in your Rekhu account. It reaches the assistant on every call. `instructions` in the config is for rules that belong with the code.

## Help

Write help as markdown files in `resources/rekhu/help`, one topic per file. The first `# ` heading is the topic's title.

```
resources/rekhu/help/
├── shipping.md
└── returns.md
```

The assistant searches these topics for how-to questions and answers from them in the user's language. When the help does not cover a question, it says so instead of guessing.

## A public chat for visitors

The same assistant can serve people who are not signed in, for example for questions before a quote. It is off by default:

```dotenv
REKHU_ASSISTANT_GUESTS=true
```

The same `<x-rekhu-assistant />` tag then also shows for visitors, connected to a separate public endpoint. Mark what visitors may use:

```php
#[Tool('Calculate a price quote for a product and quantity', guests: true)]
public function quote(QuoteRequest $request) { … }   // the route must work without login
```

| Rule | Detail |
|---|---|
| What visitors reach | Only actions marked `guests: true`, pages that need no login (and the sign-in page), and the help. Keep internal information out of the help once the public chat is on. |
| Who a visitor is | An anonymous id kept in their browser, with a conversation of its own. A visitor is never a user, even when the browser happens to be signed in. |
| Caps | Per visitor per day (20 messages), per IP address per hour (60), for all visitors together per day (1000), plus shorter messages and fewer steps per answer. All set under `guests` in the config. |
| CSRF | Stays on, as for signed-in users. |
| Cost | All visitors together show as one tenant, **Visitors**, in the project's usage, and count toward its monthly limit. Set that limit before you turn the public chat on. |

## Projects, usage and limits

Your Rekhu account keeps everything per **project**: one project per app. Its **Connection** tab holds the keys; its **Assistant** tab the usage, the limit and the pause.

- **Keys per environment.** One secret key for local, staging and production; all of them count toward the project.
- **One monthly limit and a pause** for the project's assistant, with a message on your notification channels at 80% and 100%. At the limit, or paused, the assistant answers nobody; your other projects go on. Its users read that the assistant is not available right now, and your app's log says why.
- **Usage per user or per tenant**, tracked, not limited: per user for a monolith, per tenant (with its users) for a multi-tenant app, as you choose when you create the project. Conversations, messages and usage per month, and a CSV to bill from.

For a multi-tenant app, tell the package which tenant a user belongs to, in a service provider, so usage shows per tenant:

```php
use Rekhu\Assistant\RekhuAssistant;

public function boot(): void
{
    RekhuAssistant::tenantUsing(fn ($user) => $user->team);   // a model, or ['id' => …, 'name' => …]
}
```

Users are identified to Rekhu by their id only, never by name or e-mail.

## How it works

```
Browser (widget)  ──▶  your Laravel app (this package)  ──▶  Rekhu  ──▶  the model
                         │
                         └──▶ your routes, run as the user
```

1. The widget sends the user's message to the package's chat endpoint in your app, with the user's own session or token.
2. The package builds the assistant's view of your app for **this user**: the actions and pages they may use, and your help topics.
3. It calls Rekhu, which meters the call for the user's company and forwards it to the model.
4. When the model asks for an action, the package runs your route in the same request, as the user, and reports the result back.
5. The answer streams back to the widget as text, steps and cards (a page to open, an action to confirm, a result).

## Security

| Guarantee | How |
|---|---|
| **It acts as the user** | Every action runs as the signed-in user through your own middleware, policies and validation. The assistant cannot do anything the user could not do in your app. |
| **Clicks, not words** | Confirmations are held on the server. Only the user's click on the card runs the action, with exactly the input they saw. |
| **CSRF and guards** | The CSRF check stays on for cookie sign-in, the public chat included. API mode uses your API guard. |
| **Throttling** | The chat is rate limited; the public chat adds caps per visitor, per IP address and per day. |
| **No markup from the model** | The widget escapes everything the model writes and only links to `http(s)` addresses or your own paths. |
| **No inbound connection** | Your app calls Rekhu. There is no endpoint in your app for Rekhu to call. |
| **What leaves your app** | The conversation, and what your marked actions, pages and help return to the assistant, are sent to Rekhu and the model provider to produce the answer. Users are identified by their id only, never by name or e-mail. Conversations are stored in your app's cache, for 24 hours by default (`conversation_minutes`). |

## Configuration

`php artisan rekhu:assistant-install` publishes the config. To publish it again:

```bash
php artisan vendor:publish --tag=rekhu-assistant-config
```

| Environment variable | Default | Purpose |
|---|---|---|
| `REKHU_ASSISTANT_KEY` | | The app key. Written by `rekhu:assistant-install`. |
| `REKHU_ASSISTANT_MODEL` | `claude-sonnet-5` | The model that answers. |
| `REKHU_ASSISTANT_NAME` | `Assistant` | The assistant's name in the widget and in its answers. |
| `REKHU_ASSISTANT_MIDDLEWARE` | `web,auth` | Who may use the chat. `api,auth:sanctum` for an API. |
| `REKHU_ASSISTANT_GUESTS` | `false` | Turns on the public chat for visitors. |
| `REKHU_ASSISTANT_GUEST_MIDDLEWARE` | `web` | The middleware of the public chat. |
| `REKHU_PLATFORM` | `https://api.rekhu.ai` | Only for a test platform. |

`config/rekhu-assistant.php` also holds:

| Key | Purpose |
|---|---|
| `instructions` | Your own rules for the assistant, in plain words: tone, what it never does, when it hands over to a person. |
| `greeting`, `color` | The first message and the accent colour of the widget. |
| `pages.exclude`, `pages.limit` | Route names never offered as pages, and the most pages offered. |
| `help_path` | Another folder for the help topics. |
| `conversation_minutes` | How long a conversation is kept (1440). |
| `max_message` | The most characters in one message from a signed-in user (4000). |
| `max_tokens`, `max_steps` | How long one answer may get, and how many action rounds one turn may take. |
| `guests.*` | The public chat: its greeting, message length, steps and caps. |

### Translations

What your users read when something goes wrong (the assistant is unavailable, a message is empty, a visitor reached a cap) comes from the package's language file. Publish it to translate or reword it:

```bash
php artisan vendor:publish --tag=rekhu-assistant-lang
```

The file lands in `lang/vendor/rekhu-assistant/en/messages.php`; add a folder per language next to `en`. The assistant itself answers in the user's language.

### Errors on the chat routes

The chat routes always answer errors as JSON, also those of your own middleware: a sign-in that expired is a `401`, a CSRF token that expired a `419`, a cap a `429`, each with a `message`. The widget shows that message, and asks the user to reload on `401` and `419`.

## Commands

| Command | What it does |
|---|---|
| `php artisan rekhu:assistant-install --key=agk_…` | Checks the key with Rekhu, writes it to `.env`, publishes the config and creates the help folder. |
| `php artisan rekhu:assistant` | Shows your credit, lists the actions, pages and help topics, and tests the connection to Rekhu. |
| `php artisan rekhu:assistant --user=12` | The same, as one user: only what that user may use. |

## Troubleshooting

| What you see | What to do |
|---|---|
| No chat button | The tag shows for signed-in users (and for visitors with `REKHU_ASSISTANT_GUESTS=true`). Check that it is in the layout this page uses. |
| "Your session has ended" | The chat got a 401 or 419: the sign-in or the CSRF token expired. In API mode, check the token and `REKHU_ASSISTANT_MIDDLEWARE`. |
| An action is missing | Run `php artisan rekhu:assistant --user=<id>`. A `can:` middleware hides an action from users who may not use it. |
| "The assistant is not available right now" | The project's assistant is paused or at its monthly limit, your Rekhu credit is used up, the app could not reach Rekhu, or the key is wrong. The app's log says which; `php artisan rekhu:assistant` checks the key and the credit. |
| CORS errors in the browser | In API mode, add `rekhu/assistant/*` to `paths` and your front end's domain to `allowed_origins` in `config/cors.php`. |

## Testing

```bash
composer install
composer test
composer lint:check
```

The tests fake Rekhu, so they need no key and no network.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

## Licence

Licensed, not sold, to holders of a Rekhu account under the [Rekhu Terms of Service](https://rekhu.com/terms). See [LICENSE.txt](LICENSE.txt).
