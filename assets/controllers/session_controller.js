import { Controller } from '@hotwired/stimulus';

/*
 * Handles session expiry in two ways:
 * 1. TIMEOUT — after `lifetime` seconds with no server request, redirect to login.
 *    Every successful fetch() resets the countdown.
 * 2. INTERCEPT — if any fetch() response lands on the login URL (redirect followed
 *    transparently by the browser), show an overlay and redirect immediately.
 */
export default class extends Controller {
    static values = {
        lifetime:  { type: Number, default: 60 },
        loginUrl:  { type: String, default: '/logowanie' },
    };

    connect() {
        this._expired = false;
        this._scheduleLogout();

        const self = this;
        const originalFetch = window.fetch;
        this._originalFetch = originalFetch;

        window.fetch = function (...args) {
            return originalFetch.apply(window, args).then(response => {
                // Did the server redirect us to the login page?
                if (response.url && response.url.includes(self.loginUrlValue)) {
                    self._expireSession();
                    throw new Error('session-expired');
                }
                // Successful response — session is alive, reset countdown
                self._scheduleLogout();
                return response;
            });
        };
    }

    disconnect() {
        clearTimeout(this._timeout);
        if (this._originalFetch) {
            window.fetch = this._originalFetch;
        }
    }

    _scheduleLogout() {
        clearTimeout(this._timeout);
        this._timeout = setTimeout(() => this._expireSession(), this.lifetimeValue * 1000);
    }

    _expireSession() {
        if (this._expired) return;
        this._expired = true;
        clearTimeout(this._timeout);

        // Full-page overlay so nothing bleeds through
        const o = document.createElement('div');
        o.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,.97);z-index:99999;display:flex;align-items:center;justify-content:center';
        o.innerHTML =
            '<div style="text-align:center">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="#722F37" fill="none" style="margin-bottom:.75rem">' +
            '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>' +
            '<rect x="5" y="11" width="14" height="10" rx="2"/>' +
            '<circle cx="12" cy="16" r="1"/><path d="M8 11v-4a4 4 0 0 1 8 0v4"/>' +
            '</svg>' +
            '<p style="font-size:1.15rem;font-weight:700;color:#722F37;margin:0 0 .25rem">Sesja wygasła</p>' +
            '<p style="color:#888;font-size:.88rem;margin:0">Przekierowywanie na stronę logowania\u2026</p>' +
            '</div>';
        document.body.appendChild(o);

        window.location.href = this.loginUrlValue;
    }
}
