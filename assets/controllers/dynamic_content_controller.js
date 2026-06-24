import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    async connect() {
        try {
            const response = await fetch(this.urlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.ok) {
                this.element.innerHTML = await response.text();
                if (!this.scrollToHighlight()) this.scrollToHash();
            }
        } catch {}
    }

    // Content is injected after navigation, so a #strona-N hash from an
    // assistant citation has no target yet at page load. Re-apply it once the
    // node exists to scroll there and re-trigger the :target highlight.
    // A cited passage (?frag=N) is rendered server-side as <mark class="rag-hl">.
    // Prefer scrolling to it over the page-marker hash. Returns true if found.
    scrollToHighlight() {
        const mark = this.element.querySelector('mark.rag-hl');
        if (!mark) return false;
        requestAnimationFrame(() => mark.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        return true;
    }

    scrollToHash() {
        const hash = window.location.hash;
        if (!hash || hash.length < 2) return;
        let target;
        try { target = this.element.querySelector(hash); } catch { return; }
        if (!target) return;
        requestAnimationFrame(() => {
            history.replaceState(null, '', window.location.pathname + window.location.search);
            window.location.hash = hash;
        });
    }
}
