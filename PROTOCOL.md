# Rekhu Assistant protocol, version 1

What the chat widget (`@rekhu/assistant-widget`) and a **Rekhu server package** in the app's back end say to each other. A server package implements it (for Node.js: `@rekhu/assistant-node`), and a team that wants its own chat screen can talk to one directly.

The server package runs inside the developer's back end. It knows the signed-in user, the actions and pages they may use, and the help. It calls the Rekhu platform for the model. The browser never talks to Rekhu and never holds a Rekhu key.

```
browser (widget or own UI)  ──protocol 1──▶  server package in the app  ──▶  Rekhu platform  ──▶  model
```

## Endpoint

One URL, chosen by the app (for example `/rekhu/assistant/chat`), with three methods:

| Method | Does |
|---|---|
| `POST` | one turn: the user's message or their click on Confirm; answered as a stream of events |
| `GET ?conversation=…` | what the user saw of a conversation, to show it again after a reload |
| `DELETE` | forgets a conversation ("New") |

## Who is talking

The server package uses the app's own login: a session cookie (with the CSRF token on a same-domain page), an API token (`Authorization: Bearer …`, for example a JWT), or whatever else the app uses. The protocol does not define it; the widget passes it on (`credentials`, `csrf`, `headers`).

- No signed-in user: `401` (or `419` for an expired CSRF token).
- A conversation belongs to the user who started it. Another user's conversation id opens a new, empty conversation for the caller; it never shows or continues someone else's.

Every request may carry `X-Rekhu-Protocol: 1`. A server package that does not speak the version it is asked for answers `400`. A server package may require the header on `POST` and `DELETE` as its defence against cross-site requests (a form on another site cannot send it), answering `403` without it; `@rekhu/assistant-node` does. The widget always sends it.

## The public chat: visitors who are not signed in

A server package can offer the same chat to visitors, on a second endpoint the app chooses (for example `/rekhu/assistant/visitor/chat`), off until the app turns it on. Everything in this document holds for it, with these differences:

- **The visitor names themselves** with an anonymous id: every request carries `X-Rekhu-Visitor: <uuid>`, a random UUID the client keeps (the widget keeps one per browser). No id, or one that is not a UUID: `422`. The id is not a login; it only keeps one visitor's conversation apart from another's and lets the server package count their messages.
- **A visitor is never a user.** Even when the browser is signed in, the public endpoint answers as a visitor.
- **What a visitor reaches:** only the actions the app marked for guests, pages that need no login (and the sign-in page), and the help.
- **Caps:** per visitor per day, per IP address per hour, and for all visitors together per day. Over a cap: `429` with a message to show.
- **Cookie apps keep their CSRF check** on the public endpoint, as on the signed-in one.
- **Metering:** all visitors together are one customer on Rekhu ("Visitors"), with its own monthly limit and pause in the developer's account.

## POST: one turn

Request, `Content-Type: application/json`, `Accept: text/event-stream`:

```json
{
  "message": "Order the business cards from ORD-1001 again",
  "conversation": "8f3a…",
  "confirm": null,
  "locale": "nl-NL"
}
```

| Field | |
|---|---|
| `message` | what the user typed, at most 4000 characters |
| `conversation` | the id from an earlier `start` event; empty or absent starts a new conversation |
| `confirm` | the `id` of a confirm card the user clicked, instead of a message |
| `locale` | the language the user reads (BCP 47, for example `en-GB`, `nl-NL`); the answer is in that language |

One of `message` and `confirm` is required; neither is `422`. Too many turns in a short time is `429`.

The answer is `200` with `Content-Type: text/event-stream`. Each event is one block:

```
event: text
data: {"type":"text","text":"Your last order was "}

```

The `data` line is JSON and always repeats the event's `type`. The events, in the order they can come:

| `type` | Fields | Meaning |
|---|---|---|
| `start` | `conversation`, `protocol` | always first; keep `conversation` and send it with the next turn |
| `text` | `text` | a piece of the answer; append it to the current answer bubble |
| `step` | `label` | the assistant is doing something ("Looking in the help", an action's name); ends when any next event comes |
| `card` | `kind`, … | something to show or click; see below |
| `error` | `message` | the turn could not finish; show `message` to the user as it is |
| `done` | | always last when the turn finished |

A `text` after a `step` or a `card` starts a new answer bubble.

### Cards

| `kind` | Fields | Show |
|---|---|---|
| `page` | `label`, `url` | a button that opens `url` (with the app's router when it has one) |
| `confirm` | `id`, `title`, `input`, `danger` | the action and the input it will run with, a Confirm and a Cancel button; `danger` is a destructive action |
| `done` | `title`, `message?`, `url?` | an action ran; the app's data changed |
| `failed` | `title`, `message` | an action was refused or failed; `message` says why |

**Confirming.** Nothing runs when the confirm card is shown. The server package keeps the action and its exact input on the server under `id`. Only a `POST` with `"confirm": "<id>"` runs it, with that stored input; a typed "yes", or an id the server package did not issue to this user in this conversation, runs nothing. Cancel is an ordinary message ("No, cancel that.").

### Errors

| | Where | The widget shows |
|---|---|---|
| `401`, `419` | HTTP status | "Your session has ended. Reload the page and sign in again." |
| `422` | HTTP status | the turn was empty or malformed |
| `429` | HTTP status | "That was a lot of messages at once. Wait a minute and try again."; on the public chat the server package's own message about its cap |
| `error` event | in the stream | its `message`, for example that the assistant is paused for this customer (the developer's limit in Rekhu) or not available right now |

## GET: the conversation so far

`GET <endpoint>?conversation=8f3a…`, `Accept: application/json`:

```json
{
  "conversation": "8f3a…",
  "messages": [
    { "role": "user", "text": "What did I order last?" },
    { "role": "bot", "text": "Your last order was **ORD-1001** …" }
  ]
}
```

Only what the user saw: their messages and the answers (markdown), at most the last 30, without the tool traffic and without cards.

## DELETE: start over

`DELETE <endpoint>` with `{"conversation": "8f3a…"}` forgets that conversation. Answers `204`.

## Text

Answers are a small markdown: paragraphs, `-` and `1.` lists, `**bold**`, `*italics*`, `` `code` `` and links `[label](url)` to `http(s)://` or to the app's own paths. A client renders nothing else as HTML.

## Changes

A server package and the widget agree on the version with `X-Rekhu-Protocol` and the `start` event's `protocol`. New optional fields and new card kinds can come within version 1; a client ignores what it does not know. Anything that breaks an existing client is version 2.
