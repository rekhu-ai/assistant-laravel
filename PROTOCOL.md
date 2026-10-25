# Rekhu Assistant protocol, version 1

What a client (the chat widget `@rekhu/assistant-widget`, or `rekhu_assistant` for Flutter) and a **Rekhu server package** in the app's back end say to each other. A server package implements it (for Node.js: `@rekhu/assistant-node`), and a team that wants its own chat screen can talk to one directly.

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
| `done` | `message?` | always last when the turn finished; `message` is the id of the answer just given (`"m_7"`), when it has words the user sees |

A `text` after a `step` or a `card` starts a new answer bubble. All the bubbles of one turn are still one answer with one id: the history returns them as one message, and a report names that id.

### Cards

| `kind` | Fields | Show |
|---|---|---|
| `page` | `label`, `url` | a button that opens `url` (with the app's router when it has one) |
| `confirm` | `id`, `title`, `input`, `danger` | the action and the input it will run with, a Confirm and a Cancel button; `danger` is a destructive action |
| `done` | `title`, `message?`, `url?` | an action ran; the app's data changed |
| `failed` | `title`, `message` | an action was refused or failed; `message` says why |
| `file` | `title`, `name`, `url`, `size` | an action handed over a file: a download button with the file `name` and its `size` in bytes |

**Files.** `url` is a signed link the server package made for this user: it works for a short time and needs no login, so a client opens it as a plain download (`<a href download target="_blank">`), never through the app's router. `title` says what the file is ("Your transactions"), `name` is the file name with its extension (`transactions.xlsx`).

```json
{"type": "card", "kind": "file", "title": "Your transactions", "name": "transactions.xlsx", "url": "https://api.example.com/rekhu/assistant/files/f_1?expires=…&signature=…", "size": 12345}
```

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
    { "id": "m_1", "role": "user", "text": "What did I order last?" },
    { "id": "m_2", "role": "bot", "text": "Your last order was **ORD-1001** …" }
  ],
  "report": {
    "reasons": [{ "key": "wrong_answer", "label": "The answer is wrong" }, { "key": "other", "label": "Something else" }],
    "max_comment": 1000
  }
}
```

Only what the user saw: their messages and the answers (markdown), at most the last 30, without the tool traffic and without cards. Each message has an `id`, the same for the life of the conversation, also after its oldest turns are dropped; an answer that came in several bubbles is one message.

`report` is there when the user may report answers (see below): the reasons to offer, in the user's language, and the longest comment. A client builds its form from it and never hard-codes the reasons.

Without `conversation`, the answer has no messages. Either way it may carry `"greeting"`: the server's first message (markdown) in the user's language (`Accept-Language`), which a client shows when the app gave it none of its own. A client asks for it once, when the chat opens.

```json
{ "messages": [], "greeting": "Hi! Ask me anything about your orders." }
```

Without `conversation`, it may also carry `"quick"`: the developer's quick questions for a fresh chat, in the user's language, only those the user may act on. A client may show them; a click sends the text as the user's message:

```json
{ "messages": [], "greeting": "Hi! Ask me anything about your orders.", "quick": ["Where is my last order?", "Show my invoices"] }
```

When a request fails before the stream starts (4xx or 5xx), a JSON body may carry `"message"`: what to tell the user, in their language. A client shows it instead of its own words.

## POST …/report: report a problem with an answer

`POST <endpoint>/report` (the endpoint's path with `/report` added, before any query string), `Content-Type: application/json`, with the same headers and sign-in as the chat:

```json
{
  "conversation": "8f3a…",
  "message": "m_2",
  "reason": "wrong_answer",
  "comment": "My last order was ORD-1002",
  "locale": "en-GB"
}
```

| Field | |
|---|---|
| `conversation` | the user's own conversation |
| `message` | the id of the answer, from the history or the `done` event |
| `reason` | a `key` from the history's `report.reasons` |
| `comment` | optional: what the user expected instead, at most `report.max_comment` characters |
| `locale` | optional: the language the user reads |

The client sends only the id, never the answer's text: the server package reads the question, the answer and what led to it from the conversation it kept itself.

| Answer | |
|---|---|
| `201` | `{"message": "Thanks, we will look into it."}`: the thank-you to show, in the user's language |
| `404` | the conversation or the answer is not this user's |
| `422` | a reason that is not offered, or a comment that is too long; `errors` per field |
| `429` | too many reports; `message` says so |

A second report on the same answer by the same user replaces the first. The user is thanked even when the report could not be passed on at once; the server package tries again.

## DELETE: start over

`DELETE <endpoint>` with `{"conversation": "8f3a…"}` forgets that conversation. Answers `204`.

## Text

Answers are a small markdown: paragraphs, `-` and `1.` lists, tables between pipes, `**bold**`, `*italics*`, `` `code` ``, fenced code blocks, charts and links `[label](url)` to `http(s)://` or to the app's own paths. A client renders nothing else as HTML.

### Charts

A fenced block with the language `chart` holds one chart as JSON:

````
```chart
{"type": "bar", "title": "Revenue per month", "unit": "€", "labels": ["Jan", "Feb"], "series": [{"name": "2026", "data": [1200.5, 980]}]}
```
````

| Field | |
|---|---|
| `type` | `bar`, `line`, `area`, `pie` or `donut` |
| `title` | optional |
| `unit` | optional: what the values are in (`€`, `orders`) |
| `labels` | one per value |
| `series` | at least one `{ "name", "data" }`; `data` holds numbers only. `pie` and `donut` use the first series, with no negative values |

A client that draws charts escapes every text in them. A block that is not valid JSON, has an unknown type or no data is shown as an ordinary code block. While an answer streams, a client waits for the closing fence before drawing, rather than showing the JSON. A client that does not draw charts shows the block as code. The history returns the block as part of the answer's text.

## Changes

A server package and the widget agree on the version with `X-Rekhu-Protocol` and the `start` event's `protocol`. New optional fields and new card kinds can come within version 1; a client ignores what it does not know. Anything that breaks an existing client is version 2.
