# Examples

A small shop (orders, invoices, deliveries) wired up with Rekhu Assistant, file by file. Copy what you need; nothing here
is loaded by the package, and this folder is not part of what Composer installs.

| File | What it shows |
|---|---|
| [`app/Http/Controllers/OrderController.php`](app/Http/Controllers/OrderController.php) | One action of each kind: a list with filters, one record, a change through a form request, a cancel that the user confirms, an action for visitors. Each with a `description` for the assistant and a `label` for the user. |
| [`app/Http/Requests/ChangeAddressRequest.php`](app/Http/Requests/ChangeAddressRequest.php) | The input the assistant reads from a form request's rules, and the `authorize()` it must pass. |
| [`app/Providers/AssistantServiceProvider.php`](app/Providers/AssistantServiceProvider.php) | `tenantUsing`, `authorizeUsing` (Spatie, Laratrust), `pagesUsing` for a front end of its own. |
| [`config/rekhu-assistant.php`](config/rekhu-assistant.php) | The options most apps change: the chat under `api/`, `instructions`, `actions.include` and `exclude`, `quick`, `greeting`, front-end pages. |
| [`routes/api.php`](routes/api.php) | The assistant's routes in your own group, with `REKHU_ASSISTANT_ROUTES=false`. |
| [`resources/rekhu/help/`](resources/rekhu/help) | Help topics, written the way the assistant answers from them best. |
| [`resources/views/layouts/app.blade.php`](resources/views/layouts/app.blade.php) | The widget in a Blade layout, restyled with CSS variables and parts. |
| [`frontend/nuxt/AssistantChat.vue`](frontend/nuxt/AssistantChat.vue) | A chat screen of your own in Nuxt on `createClient()`: greeting, quick questions, steps, page buttons, confirm cards. |

The package README's [Writing good actions](../README.md#writing-good-actions) explains the choices made here.
