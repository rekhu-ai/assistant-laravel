<!--
  A chat screen of your own in Nuxt, on the widget package's headless client (createClient). The connection, the
  sign-in and the streamed answer come from the package; everything you see is yours to change. Tailwind classes.
  Put it in your layout for signed-in users: <AssistantChat v-if="user" />
-->
<template>
  <div>
    <button v-if="!open" class="fixed bottom-5 end-5 rounded-full bg-teal-700 px-4 py-3 text-white shadow-lg" @click="toggle(true)">Ask</button>

    <aside v-else class="fixed inset-y-0 end-0 flex w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-label="Assistant">
      <header class="flex items-center justify-between border-b p-3">
        <strong>Assistant</strong>
        <span class="flex gap-2">
          <button class="text-sm text-gray-500" :disabled="busy" @click="restart">New chat</button>
          <button class="text-sm text-gray-500" @click="toggle(false)">Close</button>
        </span>
      </header>

      <div ref="log" class="grow space-y-3 overflow-y-auto p-4">
        <!-- a fresh chat: the greeting and the quick questions from your back end (only those this user may ask) -->
        <template v-if="!messages.length">
          <p v-if="greeting" class="rounded-xl bg-gray-50 p-3 text-sm" v-html="markdown(greeting)"></p>
          <div class="flex flex-wrap gap-2">
            <button v-for="q in quick" :key="q" class="rounded-full border px-3 py-1 text-xs" @click="ask(q)">{{ q }}</button>
          </div>
        </template>

        <template v-for="(m, i) in messages" :key="i">
          <p v-if="m.role === 'user'" class="ms-auto max-w-[85%] rounded-2xl bg-teal-700 px-3 py-2 text-sm text-white">{{ m.text }}</p>
          <div v-else-if="m.role === 'bot'" class="max-w-[90%] rounded-2xl bg-gray-50 px-3 py-2 text-sm" v-html="markdown(m.text)"></div>
          <p v-else-if="m.role === 'step'" class="text-xs text-gray-500">{{ m.over ? '✓' : '…' }} {{ m.text }}</p>
          <button v-else-if="m.role === 'page'" class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800" @click="go(m.url)">{{ m.text }} →</button>
          <div v-else-if="m.role === 'confirm'" class="rounded-xl border p-3 text-sm">
            <p class="font-semibold">{{ m.text }}</p>
            <dl class="mt-1 text-xs text-gray-600"><template v-for="(v, k) in m.input" :key="k"><dt class="inline font-medium">{{ k }}:</dt> <dd class="inline">{{ v }}</dd><br></template></dl>
            <div v-if="!m.answered" class="mt-2 flex gap-2">
              <button class="rounded bg-teal-700 px-3 py-1 text-xs text-white" @click="confirm(m)">Confirm</button>
              <button class="rounded border px-3 py-1 text-xs" @click="cancel(m)">Cancel</button>
            </div>
          </div>
          <p v-else-if="m.role === 'done'" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">✓ {{ m.text }}<span v-if="m.detail" class="block text-xs">{{ m.detail }}</span></p>
          <p v-else-if="m.role === 'failed' || m.role === 'error'" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ m.text }}<span v-if="m.detail" class="block text-xs">{{ m.detail }}</span></p>
        </template>
      </div>

      <form class="flex gap-2 border-t p-3" @submit.prevent="ask(draft)">
        <textarea v-model="draft" rows="1" class="grow resize-none rounded-lg border px-3 py-2 text-sm" placeholder="Ask about an order…" @keydown.enter.exact.prevent="ask(draft)"></textarea>
        <button class="rounded-lg bg-teal-700 px-3 text-sm text-white disabled:opacity-40" :disabled="busy || !draft.trim()">Send</button>
      </form>
    </aside>
  </div>
</template>

<script setup>
import { createClient, markdown } from '@rekhu/assistant-widget';

const config = useRuntimeConfig();
const router = useRouter();
const token = useCookie('token'); // wherever your app keeps the user's API token

// the address of the chat route in your Laravel app, from .env (NUXT_PUBLIC_ASSISTANT_ENDPOINT)
const client = createClient(() => ({
  endpoint: config.public.assistantEndpoint,
  headers: () => ({ Authorization: `Bearer ${token.value}` }),
  credentials: 'omit',
}));

const open = ref(false);
const busy = ref(false);
const draft = ref('');
const greeting = ref('');
const quick = ref([]);
const messages = ref([]);
const log = ref(null);
const scroll = () => nextTick(() => log.value && (log.value.scrollTop = log.value.scrollHeight));

async function toggle(value) {
  open.value = value;
  if (!value) return;
  try {
    const history = await client.history();
    greeting.value = history.greeting || '';
    quick.value = history.quick || [];
    messages.value = history.messages || [];
    scroll();
  } catch (e) { /* the chat still opens */ }
}

async function run(payload) {
  busy.value = true;
  let bubble = null;
  let step = null;
  const endStep = () => { if (step) { step.over = true; step = null; } };
  try {
    await client.send(payload, (event) => {
      if (event.type === 'text') { endStep(); if (!bubble) { bubble = reactive({ role: 'bot', text: '' }); messages.value.push(bubble); } bubble.text += event.text; }
      if (event.type === 'step') { endStep(); bubble = null; step = reactive({ role: 'step', text: event.label, over: false }); messages.value.push(step); }
      if (event.type === 'card') {
        endStep(); bubble = null;
        if (event.kind === 'page') messages.value.push({ role: 'page', text: event.label, url: event.url });
        if (event.kind === 'confirm') messages.value.push(reactive({ role: 'confirm', text: event.title, input: event.input, id: event.id, answered: false }));
        if (event.kind === 'done' || event.kind === 'failed') messages.value.push({ role: event.kind, text: event.title, detail: event.message });
        if (event.kind === 'done') refreshNuxtData(); // the app's data changed
      }
      if (event.type === 'error') messages.value.push({ role: 'error', text: event.message });
      scroll();
    });
  } catch (error) {
    messages.value.push({ role: 'error', text: error.message }); // already in the user's language
  } finally {
    endStep();
    busy.value = false;
  }
}

function ask(text) {
  const message = String(text || '').trim();
  if (!message || busy.value) return;
  draft.value = '';
  messages.value.push({ role: 'user', text: message });
  run(message);
}

function confirm(card) { card.answered = true; messages.value.push({ role: 'user', text: 'Confirmed' }); run({ confirm: card.id }); }
function cancel(card) { card.answered = true; messages.value.push({ role: 'user', text: 'Cancel that' }); run('No, cancel that.'); }
async function restart() { messages.value = []; await client.forget(); toggle(true); }

// a page button opens in the Nuxt router when it points at this front end
function go(url) {
  const target = new URL(url, window.location.href);
  if (target.origin === window.location.origin) router.push(target.pathname + target.search);
  else window.location.href = url;
}
</script>
