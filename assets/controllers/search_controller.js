import { Controller } from '@hotwired/stimulus';

const EMPTY_HTML = `
    <div class="text-center py-5">
        <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 24 24" stroke-width="1" stroke="#ccc" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
        <p class="mt-3 mb-1" style="font-size:1.1rem; font-weight:600; color:#888;">Wpisz frazę, aby wyszukać</p>
        <p style="font-size:.85rem; color:#bbb;">Przeszukaj teksty Prymasa Wyszyńskiego</p>
    </div>`;

const LOADING_HTML = `
    <div class="text-center py-5">
        <div class="spinner-border mb-3" role="status" style="width:2rem; height:2rem;"></div>
        <p style="font-size:.88rem; color:#999;">Wyszukiwanie...</p>
    </div>`;

const DOC_LOADING_HTML = `
    <div class="text-center py-5">
        <div class="spinner-border mb-3" role="status" style="width:2rem; height:2rem;"></div>
        <p style="font-size:.88rem; color:#999;">Ładowanie dokumentu...</p>
    </div>`;

const ERROR_HTML = `
    <div class="text-center py-5">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1" stroke="#e55" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v2m0 4v.01"/><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.84 2.75"/></svg>
        <p class="mt-3 mb-1" style="font-size:1.1rem; font-weight:600; color:#888;">Wystąpił błąd</p>
        <p style="font-size:.85rem; color:#bbb;">Spróbuj ponownie za chwilę</p>
    </div>`;

export default class extends Controller {
    static values = {
        url: String,
        previewUrl: String,
    };
    static targets = [
        'input', 'volume', 'type', 'dateFrom', 'dateTo', 'tagsContainer', 'results',
        'hero', 'filters', 'documentViewer', 'documentContent',
    ];

    connect() {
        this.debounceTimer = null;
        this.selectedTagId = null;
        this.currentPage = 1;
        this.abortController = null;
        this.docAbortController = null;
        this.viewingDocument = false;

        // Listen for browser back/forward
        this._onPopState = this._handlePopState.bind(this);
        window.addEventListener('popstate', this._onPopState);
    }

    // ─── Search ───

    onInput() {
        clearTimeout(this.debounceTimer);
        this.currentPage = 1;
        this.debounceTimer = setTimeout(() => {
            this.fetchResults();
        }, 400);
    }

    onFilterChange() {
        this.currentPage = 1;
        this.fetchResults();
    }

    onTagClick(event) {
        const chip = event.currentTarget;
        const tagId = chip.dataset.tagId;
        this.currentPage = 1;

        if (this.selectedTagId === tagId) {
            this.selectedTagId = null;
            chip.classList.remove('active');
        } else {
            if (this.hasTagsContainerTarget) {
                this.tagsContainerTarget.querySelectorAll('.tag-chip').forEach(c => c.classList.remove('active'));
            }
            this.selectedTagId = tagId;
            chip.classList.add('active');
        }

        this.fetchResults();
    }

    resetFilters() {
        if (this.hasInputTarget) this.inputTarget.value = '';
        if (this.hasVolumeTarget) this.volumeTarget.value = '';
        if (this.hasTypeTarget) this.typeTarget.value = '';
        if (this.hasDateFromTarget) this.dateFromTarget.value = '';
        if (this.hasDateToTarget) this.dateToTarget.value = '';
        this.selectedTagId = null;
        this.currentPage = 1;
        if (this.hasTagsContainerTarget) {
            this.tagsContainerTarget.querySelectorAll('.tag-chip').forEach(c => c.classList.remove('active'));
        }
        this.resultsTarget.innerHTML = EMPTY_HTML;
    }

    goToPage(event) {
        event.preventDefault();
        const page = parseInt(event.currentTarget.dataset.page, 10);
        if (page && page >= 1) {
            this.currentPage = page;
            this.fetchResults();
            this.resultsTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    async fetchResults() {
        const query = this.hasInputTarget ? this.inputTarget.value.trim() : '';
        const volume = this.hasVolumeTarget ? this.volumeTarget.value : '';
        const type = this.hasTypeTarget ? this.typeTarget.value : '';
        const dateFrom = this.hasDateFromTarget ? this.dateFromTarget.value : '';
        const dateTo = this.hasDateToTarget ? this.dateToTarget.value : '';

        if (!query && !volume && !type && !this.selectedTagId && !dateFrom && !dateTo) {
            this.resultsTarget.innerHTML = EMPTY_HTML;
            return;
        }

        this.resultsTarget.innerHTML = LOADING_HTML;

        const params = new URLSearchParams();
        if (query) params.set('q', query);
        if (volume) params.set('volume', volume);
        if (type) params.set('type', type);
        if (this.selectedTagId) params.set('tag', this.selectedTagId);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        if (this.currentPage > 1) params.set('page', this.currentPage);

        const url = this.urlValue + '?' + params.toString();

        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.abortController.signal,
            });

            if (response.ok) {
                this.resultsTarget.innerHTML = await response.text();
            }
        } catch (e) {
            if (e.name !== 'AbortError') {
                this.resultsTarget.innerHTML = ERROR_HTML;
            }
        }
    }

    // ─── Document viewer ───

    openDocument(event) {
        const card = event.currentTarget;
        const docId = card.dataset.docId;
        if (!docId) return;

        // Build preview URL, pass search query for highlighting
        let previewUrl = this.previewUrlValue.replace('/0/', '/' + docId + '/');
        const query = this.hasInputTarget ? this.inputTarget.value.trim() : '';
        if (query) {
            previewUrl += '?q=' + encodeURIComponent(query);
        }

        // Push history state so browser Back returns to results
        history.pushState({ view: 'document', docId: docId }, '', '');
        this.viewingDocument = true;

        this._showDocumentViewer();
        this.loadDocument(previewUrl);
    }

    async loadDocument(url) {
        if (this.docAbortController) {
            this.docAbortController.abort();
        }
        this.docAbortController = new AbortController();

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.docAbortController.signal,
            });

            if (response.ok) {
                this.documentContentTarget.innerHTML = await response.text();
            } else {
                this.documentContentTarget.innerHTML = ERROR_HTML;
            }
        } catch (e) {
            if (e.name !== 'AbortError') {
                this.documentContentTarget.innerHTML = ERROR_HTML;
            }
        }
    }

    closeDocument() {
        if (this.viewingDocument) {
            // Go back in history (triggers popstate which calls _showSearchResults)
            history.back();
        }
    }

    _showDocumentViewer() {
        document.getElementById('search-area').classList.add('hidden');
        this.documentViewerTarget.classList.add('active');
        this.documentContentTarget.innerHTML = DOC_LOADING_HTML;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    _showSearchResults() {
        this.viewingDocument = false;
        this.documentViewerTarget.classList.remove('active');
        document.getElementById('search-area').classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    _handlePopState(event) {
        // If we were viewing a document and user pressed Back, return to results
        if (this.viewingDocument) {
            this._showSearchResults();
        }
    }

    // ─── Cleanup ───

    disconnect() {
        clearTimeout(this.debounceTimer);
        if (this.abortController) {
            this.abortController.abort();
        }
        if (this.docAbortController) {
            this.docAbortController.abort();
        }
        window.removeEventListener('popstate', this._onPopState);
    }
}
