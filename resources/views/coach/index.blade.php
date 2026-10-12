<x-layouts.app title="AI coach" page="coach">
    <div class="mb-6">
        <p class="eyebrow">AI coach</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Ask your coach</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted">Questions about your plan, technique, progression, recovery and food. It is an AI, not a doctor or a person: for pain or injury, see a professional.</p>
    </div>

    @if ($state === 'unavailable')
        <section class="glass p-5" aria-labelledby="un-title">
            <h2 id="un-title" class="text-sm font-bold uppercase">Not available</h2>
            <p class="mt-2 text-sm text-muted">The coach is switched on, but this server has no AI service connected yet. Whoever runs this app needs to finish the set-up (an API key and a model name). Everything else in IronPulse works without it.</p>
        </section>
    @elseif ($state === 'opt_in')
        <section class="glass glass-neon max-w-2xl p-5" aria-labelledby="opt-title">
            <h2 id="opt-title" class="text-sm font-bold uppercase">Turn on the coach?</h2>
            <p class="mt-2 text-sm text-muted">It is optional and off until you choose it. When you ask a question, the app sends the question and a short summary of your setup to an AI service run by Anthropic, so the answer can fit you.</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                <li><strong>Sent:</strong> your message, your goal, fitness level, age, height, weight, training days, equipment, diet type, today's plan, this week's progress, recent lifting records, daily nutrition targets and your average posture score.</li>
                <li><strong>Never sent:</strong> your name, email, photos, food log, measurements or anything else.</li>
                <li>Your conversation is saved in this app so you can scroll back. Switching the coach off deletes it.</li>
            </ul>
            <form method="POST" action="{{ route('coach.opt-in') }}" class="mt-4">@csrf<button class="btn btn-primary">Turn on the coach</button></form>
        </section>
    @else
        <script type="application/json" id="coach-config">@json($config)</script>
        <div x-data="coachChat" class="flex max-w-3xl flex-col gap-4">
            <div class="glass flex flex-col p-3 sm:p-4">
                <ol class="max-h-[55dvh] min-h-48 space-y-3 overflow-y-auto pr-1" x-ref="log" aria-label="Conversation" aria-live="polite" tabindex="0">
                    <template x-if="messages.length === 0">
                        <li class="px-1 py-6 text-center text-sm text-muted">Ask anything about your training. Try one of these:</li>
                    </template>
                    <template x-for="m in messages" :key="m.id">
                        <li class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                            {{-- One line on purpose: this box keeps whitespace, so line breaks here would show up as indentation. --}}
                            <div class="max-w-[88%] whitespace-pre-wrap break-words rounded-2xl px-4 py-2.5 text-sm" :class="m.role === 'user' ? 'bg-neon/15 text-ink' : 'border border-line bg-white/[0.04]'"><span class="sr-only" x-text="m.role === 'user' ? 'You: ' : 'Coach: '"></span><span x-text="m.content"></span></div>
                        </li>
                    </template>
                    <li x-show="busy" x-cloak class="flex justify-start"><div class="rounded-2xl border border-line bg-white/[0.04] px-4 py-2.5 text-sm text-muted" role="status">The coach is thinking…</div></li>
                </ol>

                <div class="mt-3 flex flex-wrap gap-2" x-show="messages.length === 0">
                    <template x-for="s in suggestions" :key="s"><button type="button" class="chip hover:!border-neon hover:!text-neon" @click="send(s)" :disabled="busy || remaining <= 0" x-text="s"></button></template>
                </div>

                <p class="mt-3 text-sm text-danger" role="alert" x-show="error" x-text="error" x-cloak></p>
                <form class="mt-3 flex items-end gap-2" @submit.prevent="send(draft)">
                    <label class="min-w-0 flex-1"><span class="sr-only">Your question</span>
                        <textarea x-model="draft" rows="2" class="textarea !min-h-12" :maxlength="maxInput" placeholder="Ask your coach" @keydown.enter.prevent="if (!$event.shiftKey) send(draft)" :disabled="remaining <= 0"></textarea></label>
                    <button class="btn btn-primary" :disabled="busy || !draft.trim() || remaining <= 0"><x-icon name="message" class="size-4"/>Send</button>
                </form>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
                    <p><span class="num text-ink" x-text="remaining"></span> of <span x-text="limit"></span> messages left today · <span x-text="draft.length"></span>/<span x-text="maxInput"></span></p>
                    <button type="button" class="underline underline-offset-4 hover:text-ink" x-show="messages.length" @click="clear()" :disabled="busy">Clear conversation</button>
                </div>
            </div>

            <form method="POST" action="{{ route('coach.opt-out') }}" onsubmit="return confirm('Turn the coach off and delete this conversation?')" class="text-sm text-muted">
                @csrf @method('DELETE')
                Answers come from an AI and can be wrong. <button class="underline underline-offset-4 hover:text-ink">Turn the coach off and delete my conversation</button>
            </form>
        </div>
    @endif
</x-layouts.app>
