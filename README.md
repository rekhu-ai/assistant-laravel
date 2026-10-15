# Rekhu Assistant for Laravel

Rekhu Assistant is a custom AI assistant you build into your own app, for your own customers. This package connects a
Laravel app to it; connectors for other stacks follow the same idea (actions, pages, help).

It answers from your help, opens the right page, and does
things for the user through the routes you mark, as that user, with your validation, policies and middleware. Every
conversation is metered per customer by [Rekhu](https://rekhu.com), so you can give it to your customers and bill them for it.

Laravel 11, 12 and 13 · PHP 8.2+ · no npm, no build step, no inbound connection.

## Install

```bash
composer require rekhu/assistant-laravel
php artisan rekhu:assistant-install     # asks for the app key from your Rekhu account
```

Put the widget in your layout, before `</body>`:

```blade
<x-rekhu-assistant />
```

It shows for signed-in users only.

### Livewire, Inertia, or an API with Nuxt or Next

The Blade tag is enough for Blade and Livewire apps. For Inertia, or a front end on its own domain that signs in with a
token, use the npm widget [`@rekhu/assistant-widget`](https://www.npmjs.com/package/@rekhu/assistant-widget); its README
has a section per setup. For an API, put the chat behind your API guard in `.env`:

```dotenv
REKHU_ASSISTANT_MIDDLEWARE=api,auth:sanctum
```

and add `rekhu/assistant/*` to the `paths` in `config/cors.php`. The widget and this package speak
[protocol 1](PROTOCOL.md), so you can also build a chat screen
of your own on the same endpoint.

## Let it act

Mark a controller method. The assistant reads the input from the form request's rules:

```php
use Rekhu\Assistant\Attributes\Tool;

#[Tool('Reorder a past order: the same product and address as a new order; the quantity can be changed')]
public function reorder(ReorderRequest $request, Order $order) { … }

#[Tool('Change the delivery address of an order that has not shipped yet', rules: ['address' => 'required|string|max:200'])]
public function address(Request $request, Order $order) { … }      // validates inline, so the rules are given here

#[Tool('Cancel an order that has not shipped yet')]
public function cancel(Order $order) { … }                          // DELETE: the user confirms with a click first
```

- The route runs in the user's own session: `auth`, `can:` and the app's own middleware, the form request's `authorize()`,
  route model binding and validation all apply. A refusal goes back to the assistant as a refusal.
- `confirm: true` asks the user to click Confirm first; every DELETE route does. Only the click runs it: a typed "yes"
  or anything the model says cannot.
- A GET route marked `#[Tool]` is a read action (for example "list my orders"); return JSON when `expectsJson()`.

## Let it open pages

Every named GET route is a page the assistant can send the user to, unless the user may not open it (`can:`, `guest`) or
it matches `pages.exclude` in the config. Describe a page to help the assistant pick it:

```php
use Rekhu\Assistant\Attributes\Page;

#[Page('Invoices', help: 'All invoices of the account, with download and payment status')]
public function index() { … }
```

## Help

Markdown files in `resources/rekhu/help`, one topic per file, the first `# ` line its title. The assistant searches them
for how-to questions and answers from them.

## A public chat for visitors

The same assistant for people who are not signed in, for example for a price quote. It is off until you turn it on:

```dotenv
REKHU_ASSISTANT_GUESTS=true
```

The same `<x-rekhu-assistant />` then shows for visitors too, and talks to a public endpoint with its own rules:

- Visitors only reach actions you mark for them, on routes that work without login. Everything else stays for signed-in users:

  ```php
  #[Tool('Calculate a price quote for a product and quantity', guests: true)]
  public function quote(QuoteRequest $request) { … }
  ```

- They can open pages that need no login (and the sign-in page), and read the help. Keep anything internal out of
  `resources/rekhu/help` once the public chat is on.
- Each visitor gets an anonymous id in their browser and their own conversation. They are never a user, even when the
  browser happens to be signed in.
- Caps in `config/rekhu-assistant.php` under `guests`: messages per visitor per day (20), per IP address per hour (60),
  and for all visitors together per day (1000), plus a shorter message and fewer steps per answer.
- The CSRF check stays on, as for signed-in users.
- All visitors together are one customer in your Rekhu account, **Visitors**, with its own monthly limit and pause under
  Your customers. Set that limit before you turn the public chat on.

## Your customers

When your app serves many companies, say which company a user belongs to, in a service provider:

```php
use Rekhu\Assistant\RekhuAssistant;

RekhuAssistant::tenantUsing(fn ($user) => $user->team);   // or ['id' => …, 'name' => …]
```

Your Rekhu account then shows usage per company. The user is sent as their id only, never a name or e-mail.

## See what it sees

```bash
php artisan rekhu:assistant              # actions, pages, help and the connection
php artisan rekhu:assistant --user=12    # as one user: only what they may use
```

## Configuration

`php artisan vendor:publish --tag=rekhu-assistant-config` publishes `config/rekhu-assistant.php`: the model, the name,
greeting and colour, your own instructions for the assistant, the middleware (also `REKHU_ASSISTANT_MIDDLEWARE`), and
the pages to leave out.

## Licence

Licensed, not sold, under the Rekhu Terms of Service. See LICENSE.txt.
