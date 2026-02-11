import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.cleanups = [];
        this.setupCopyProtection();
        this.setupPrintProtection();
        this.setupDevToolsProtection();
        this.injectPrintCSS();
    }

    disconnect() {
        this.cleanups.forEach(fn => fn());
    }

    setupCopyProtection() {
        ['copy', 'cut', 'selectstart', 'contextmenu'].forEach(evt => {
            const handler = e => {
                e.preventDefault();
                if (evt === 'copy') {
                    try {
                        e.clipboardData?.setData('text/plain',
                            'Kopiowanie treści jest niedostępne.');
                    } catch {}
                }
                return false;
            };
            document.addEventListener(evt, handler, true);
            this.cleanups.push(() => document.removeEventListener(evt, handler, true));
        });

        this.element.style.userSelect = 'none';
        this.element.style.webkitUserSelect = 'none';
        this.element.style.webkitTouchCallout = 'none';

        const dragHandler = e => e.preventDefault();
        this.element.addEventListener('dragstart', dragHandler);
        this.cleanups.push(() => this.element.removeEventListener('dragstart', dragHandler));
    }

    setupPrintProtection() {
        const keyHandler = e => {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        };
        document.addEventListener('keydown', keyHandler, true);
        this.cleanups.push(() => document.removeEventListener('keydown', keyHandler, true));
    }

    setupDevToolsProtection() {
        const handler = e => {
            if (e.key === 'F12') { e.preventDefault(); return false; }
            if (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) { e.preventDefault(); return false; }
            if (e.ctrlKey && e.key === 'u') { e.preventDefault(); return false; }
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); return false; }
            if (e.ctrlKey && e.key === 'a') { e.preventDefault(); return false; }
        };
        document.addEventListener('keydown', handler, true);
        this.cleanups.push(() => document.removeEventListener('keydown', handler, true));
    }

    injectPrintCSS() {
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                body { visibility: hidden !important; }
                body::after {
                    visibility: visible !important;
                    content: "Drukowanie niedostępne.";
                    display: block !important; font-size: 18px; padding: 60px;
                    text-align: center; color: #333; position: fixed; top: 50%; left: 10%; right: 10%;
                    transform: translateY(-50%);
                }
            }`;
        document.head.appendChild(style);
    }
}
