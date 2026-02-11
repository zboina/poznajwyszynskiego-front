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
            }
        } catch {}
    }
}
