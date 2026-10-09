/** AI coach chat. All the AI work happens on the server; this only posts questions and shows replies. */
export function register(Alpine) {
    const raw = document.getElementById('coach-config');
    if (!raw) return;
    const config = JSON.parse(raw.textContent);

    Alpine.data('coachChat', () => ({
        messages: config.messages,
        remaining: config.remaining,
        limit: config.limit,
        maxInput: config.maxInput,
        suggestions: config.suggestions,
        draft: '',
        busy: false,
        error: '',
        nextTempId: -1,

        init() {
            this.$nextTick(() => this.scroll());
        },

        scroll() {
            const log = this.$refs.log;
            if (log) log.scrollTop = log.scrollHeight;
        },

        async send(text) {
            const message = (text ?? '').trim();
            if (!message || this.busy || this.remaining <= 0) return;

            this.error = '';
            this.busy = true;
            const pending = { id: this.nextTempId--, role: 'user', content: message };
            this.messages.push(pending);
            this.draft = '';
            this.$nextTick(() => this.scroll());

            try {
                const res = await window.api(config.urls.messages, { method: 'POST', body: { message } });
                this.messages.splice(this.messages.indexOf(pending), 1, res.user, res.reply);
                this.remaining = res.remaining;
                if (res.truncated) this.error = 'That answer was cut short. Ask me to continue if you need the rest.';
            } catch (e) {
                // The question did not count and was not saved: put it back so it can be sent again.
                this.messages.splice(this.messages.indexOf(pending), 1);
                this.draft = message;
                this.error = e.data?.message || 'Something went wrong. Please try again.';
                if (typeof e.data?.remaining === 'number') this.remaining = e.data.remaining;
            } finally {
                this.busy = false;
                this.$nextTick(() => this.scroll());
            }
        },

        async clear() {
            if (this.busy || !window.confirm('Delete this conversation?')) return;
            try {
                const res = await window.api(config.urls.clear, { method: 'DELETE' });
                this.messages = [];
                this.remaining = res.remaining;
                this.error = '';
            } catch (e) {
                this.error = e.data?.message || 'Could not clear the conversation.';
            }
        },
    }));
}
