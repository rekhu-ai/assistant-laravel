# Changelog

All notable changes to `rekhu/assistant-laravel`. Versions follow [semantic versioning](https://semver.org).

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
