# Changelog

All notable changes to `rekhu/assistant-laravel`. Versions follow [semantic versioning](https://semver.org).

## 0.8.0 · 25 October 2026

- **Tool classes.** An action written for the assistant only, without a route or a controller: a summary, a short list, a figure across tables (who has not paid but still comes, today's attendance of one class). `php artisan make:assistant-tool UnpaidAttending` makes one in `app/Assistant/Tools`, where every class is offered by itself to the users its `authorize()` allows. `handle()` gets the validated input as the request and returns an array, a model or collection, a JSON resource or a download. `RekhuAssistant::tools([...])` adds classes kept elsewhere; `tools.path` and `tools.namespace` move the folder.
- **Files.** An action that returns a download (`download()`, `streamDownload()`, Laravel Excel, a PDF) gives the user a Download button in the chat, with no signed route of your own. The file is kept in the cache, which all servers share, for `files.minutes` (15) and at most `files.max_mb` (20 MB); the link is signed and expires, and works from a front end that signs in with a token. The stream gets a `file` card. Widget 0.4.0 shows it.
- **`inputs` and `fields`.** `#[Tool(inputs: ['status' => 'paid, pending or failed'])]` says what an input means, so the description can stay short. `fields: ['id', 'name', 'address.city']` keeps only those fields of every record the action returns, so a resource made for a page needs no second, shorter route. Both work in `actions.include` and on Tool classes.
- **Shared rules.** `rules` takes a class too: a form request, or any class with `rules()`, so a list and its export share one set of filters. An input validated with `Rule::enum()` is offered with its values and what they mean (`308 paid`), from each case's `label()` or its name.
- **Results that fit.** A long result is no longer cut off in the middle of its JSON, which could lose its paging and make the assistant believe it had every row. A paginator's links are left out, and a list too long to read loses its last rows, with a note on how many it got.
- **`RekhuAssistant::accessUsing()`.** One rule for who may use the chat, beside its middleware; refused users get a 403 with a message in their language.
- **Answers.** The assistant reads lists to the end before it counts or sums, shows statuses in words, writes links as short Markdown labels, does not talk about the user's permissions, offers a file for long lists and draws charts (a ```` ```chart ```` block that widget 0.4.0 draws and `renderMessage()` renders for a chat of your own). Rules like these no longer need to be in `instructions`.
- An action's request gets the chat's user even when the app's auth guard has none yet (a console command, a test).
- Widget 0.4.0: the file card, charts, and `renderMessage()` and `renderChart()` for a chat of your own.

## 0.7.1 · 25 October 2026

- An action whose controller reads the request in its constructor (directly, or through a service it injects, such as a date filter) now gets the input of its own call. Laravel keeps a route's controller once it is built, and listing the actions builds it from the chat request, so such an action answered with its defaults every time: a revenue question for this month got the year, a list asked for a larger page got the default one. Each call now builds the controller afresh, and the app's next request does not reuse it either.

## 0.7.0 · 22 October 2026

- **Report a problem.** Under each answer the user can report what went wrong (the answer is wrong, it did not understand, the information is wrong, something else, or reasons of your own) and say what they expected. The report goes to the Reports section of the project's Assistant tab in the Rekhu account, linked to the conversation; no tables, routes or controllers of your own. It is built on the server from the conversation the package kept: the question, the answer, the conversation up to it and the actions the answer used with their input and outcome. What the actions returned is left out unless `reports.include_results` is on. Configure it under `reports` (reasons, comment length, an hourly limit per user, visitors). `Rekhu\Assistant\Events\AnswerReported` is dispatched for every report, for a copy of your own. When Rekhu cannot be reached the user is still thanked and the report is tried once more, on the queue when the app has one. `php artisan rekhu:assistant` says whether reports are on and whether Rekhu takes them.
- **Message ids.** Every message in the history has an `id` (`"m_7"`), the same for the life of the conversation, also after the oldest turns are dropped; an answer in several parts around actions is one message. The stream's `done` event names the answer just given. Conversations stored by an earlier version get their ids when they are opened. `POST <endpoint>/report` and the history's `report` are in PROTOCOL.md.
- **Laravel 10 and later.** The package now supports Laravel 10.48 and later: 10, 11, 12 and 13. It requires `guzzlehttp/guzzle`, which Laravel 10 does not install by itself. Laravel 10 and 11 are past their security support, so Composer flags them; upgrading the app is the real fix.
- Conversations are kept in the cache as JSON. With a cache store that unserializes no classes (`cache.serializable_classes => false`, the default in new Laravel apps) an action without input came back as an unreadable object and was sent to the model that way; it is now always `{}`. Conversations kept by an earlier version are read as before.
- Tested on PHP 8.5.
- Widget 0.3.0: the report link, form and thank-you (parts `report`, `report-link`, `report-form`, `report-reason`, `report-comment`, `report-send`, `report-cancel`, `report-thanks`; option `report: false`), `client.report()`, and the answer's id on `done`.

## 0.6.1 · 21 October 2026

- The chat shows tables: an answer that lists records in a Markdown table now reads as a table, not as lines of `|` characters. Widget 0.2.1.

## 0.6.0 · 19 October 2026

- **Your own routes.** `REKHU_ASSISTANT_ROUTES=false` (or `RekhuAssistant::ignoreRoutes()`) stops the package from registering its routes, and `RekhuAssistant::routes([...])` registers them in your own route file, inside your own group: your domain, prefix, middleware and name prefix, with `path`, `middleware`, `guests`, `guest_middleware` and `widget` as options. The assistant's own routes are never offered as pages, whatever their name prefix.
- The README's table for `#[Tool]` says what users read: `label`, never the description.
- **Examples and a guide.** `examples/` (on GitHub, not in what Composer installs) is a small shop wired up file by file: one action of each kind, a form request, the service provider, a config, your own routes, help topics, a Blade layout and a Nuxt chat on `createClient()`. The README's new section "Writing good actions" explains descriptions, labels, names, confirmation, and where help, knowledge and instructions go.

## 0.5.2 · 19 October 2026

- **What the user reads while an action runs.** `label` on `#[Tool]` and in `actions.include` (a text or one per language) is shown on the step line and on the action's cards. Without one the user reads a neutral "Working on it…" in their language; the description, which is written for the assistant, is no longer shown. "Looking in the help…" comes in the user's language too.

## 0.5.1 · 19 October 2026

- A key of another Rekhu product (Rekhu Builder's `laravel-dev` key, for one) is refused by `rekhu:assistant-install` and named by `rekhu:assistant`, with where to make an assistant key instead. Before, it was taken without a word, and the assistant's usage, limit and project knowledge did not apply.

## 0.5.0 · 18 October 2026

- **Actions from the config**: `actions.include` turns routes into actions by route name or `VERB uri`, without touching their controller (a vendor package's routes, generated code); `actions.exclude` blocks routes by name or uri pattern, whatever their attribute says. `rekhu:assistant` shows where each action comes from.
- **Quick questions**: `quick` in the config lists your own questions for a fresh chat, a text or one per language. A question with `needs` (action names) is only offered to users who may use one of those actions. The history answer carries them; your front end decides how they look.
- **Design your own chat**: the widget is 0.2.0, which can be styled completely (CSS variables and parts) or replaced by your own chat screen on its headless client.
- **Your project's knowledge** in the Rekhu account now reaches the assistant on every call: how it behaves and what it knows is kept there, next to `instructions` in the config.

## 0.4.1 · 18 October 2026

- `REKHU_ASSISTANT_ROUTE_PAGES=false` (`pages.routes`): an API with a front end of its own offers only that front end's pages, not Laravel's own GET routes such as export downloads or payment pages.
- The README says that `pagesUsing()` is also called without a user: for a visitor of the public chat and for `rekhu:assistant` without `--user`.
- **Arabic and Dutch.** What users read from the package (not available, limits, an empty message) comes in Arabic and Dutch as well as English, by the app's locale.
- **The greeting for a front end of its own.** The chat's history answer carries the greeting in the user's language, so a Nuxt, Next or mobile front end shows it without setting it again. `greeting` in the config can be one text per language: `['en' => 'Hi!', 'ar' => 'مرحباً!']`.
- The widget is 0.1.3: it follows a right-to-left page (it sits on the left and reads from right to left), its own words come in English, Arabic and Dutch (`texts` overrides them), and it shows the server's message when a request fails.

## 0.4.0 · 18 October 2026

- **Your own permission rule.** `RekhuAssistant::authorizeUsing(fn ($user, $route) => …)` tells the assistant which actions and pages a user may use, for apps that check rights their own way (Laratrust, Spatie, a permission per route name) instead of `can:` middleware. Each call is still checked by the route's own middleware.
- **Pages of a front end of its own.** A Nuxt, Next or mobile front end lists its pages under `pages.frontend.list` in the config, or from code with `RekhuAssistant::pagesUsing(fn ($user) => […])`, as the user may open them. They open at `pages.frontend.url` (`REKHU_ASSISTANT_FRONTEND_URL`), with `{placeholders}` filled in.
- **The chat's address from `.env`** (`REKHU_ASSISTANT_PATH`). An API whose CSRF exemption covers only `api/*` puts the chat under it, for example `api/rekhu/assistant`: Sanctum checks CSRF for requests from its stateful domains.

## 0.3.2 · 15 October 2026

- The install command and the config point to where keys are made now: the Connection tab of the app's project in the Rekhu account.

## 0.3.1 · 15 October 2026

- **Laravel 12 and 13.** Laravel 11 is no longer supported: its security support ended, and every Laravel 11 release now has open security advisories, so Composer refuses to install it. Apps on Laravel 11 keep 0.2.0 until they upgrade.
- CI on the current GitHub Actions runners.

## 0.3.0 · 15 October 2026

- **Errors as JSON on the chat routes**, also those of the app's own middleware. A sign-in that expired now answers `401` instead of redirecting to the login page (which failed with a `500` in apps without a `login` route); an expired CSRF token answers a JSON `419`. The widget can show both.
- **Translatable messages.** What users read on an error comes from `lang/vendor/rekhu-assistant`; publish it with `--tag=rekhu-assistant-lang`.
- **`max_message`** sets the longest message from a signed-in user (4000 characters).
- The chat endpoints are rebuilt as a small controller with middleware for the protocol, the visitor and the visitor caps, and a form request for the message. Routes, route names and protocol 1 are unchanged.
- The history leaves out what the app reports for the user (a confirmed action and its result) by how it is stored, no longer by its wording.
- The log points to the project's **Assistant** tab when the assistant is paused or at its limit.
- Tests (PHPUnit with Testbench, Rekhu faked), Pint, and CI on PHP 8.2 to 8.4 with Laravel 11 to 13.
- Requires `laravel/framework` instead of separate Illuminate components.

## 0.2.0 · 15 October 2026

- **Projects.** Usage and limits now belong to the app's project in the Rekhu account: keys per environment (local, staging, production), one monthly limit and a pause for the project's assistant, and alerts at 80% and 100%. One project at its limit no longer stops the developer's other projects.
- **Usage is tracked inside each project:** per user for a monolith, per tenant for a multi-tenant app, chosen when the project is created. It is no longer limited per tenant, and the same tenant name in two projects is no longer counted as one.
- Users of an app whose assistant is paused or at its limit read that the assistant is not available right now; the app's log says why.
- Removed the `paused_message` setting: there is no pause per customer any more.

## 0.1.2 · 15 October 2026

- When the Rekhu account's credit is used up, users now read that the assistant is not available, and the app's log says to top up. Before, they were told their account was paused.
- `rekhu:assistant-install` and `rekhu:assistant` show the account's credit, and warn when it is empty.
- The documentation says that the account needs credit, in the requirements, in step 1 and in troubleshooting.

## 0.1.1 · 15 October 2026

- The documentation in full: getting started in five steps, a section per setup (Blade, Livewire, Inertia, an API with Nuxt, Next or a mobile app), every attribute and setting, how it works, security and troubleshooting.

## 0.1.0 · 15 October 2026

The first release.

- **Actions** from controller routes marked `#[Tool]`, run in the same request as the signed-in user, with the app's middleware, policies and validation. The input schema comes from the form request's rules.
- **Confirmations** held on the server: only the user's click on the card runs an action. Every `DELETE` route asks for one.
- **Pages** from named GET routes, filtered per user; `#[Page]` gives one a label and a description.
- **Help** from markdown files in `resources/rekhu/help`.
- **The widget** as `<x-rekhu-assistant />`, served by the package: no npm, no build step. Answers in the user's language.
- **API mode** with `REKHU_ASSISTANT_MIDDLEWARE=api,auth:sanctum`, for Nuxt, Next and mobile front ends using `@rekhu/assistant-widget`.
- **The public chat** for visitors with `REKHU_ASSISTANT_GUESTS=true`: actions marked `guests: true`, an anonymous id per visitor, caps per visitor, per IP address and per day.
- **Customers**: `RekhuAssistant::tenantUsing()` names the company of a user, so the Rekhu account shows usage, limits and a pause per company.
- **Protocol 1** between the widget and the package, documented in `PROTOCOL.md`.
- Commands `rekhu:assistant-install` and `rekhu:assistant`.
