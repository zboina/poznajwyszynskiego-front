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
        openDoc: Number,
    };
    static targets = [
        'input', 'volume', 'type', 'dateFrom', 'dateTo', 'tagsContainer', 'tagSelect', 'results',
        'hero', 'filters', 'documentViewer', 'documentContent',
    ];

    connect() {
        this.debounceTimer = null;
        this.selectedTagId = null;
        this.currentPage = 1;
        this.abortController = null;
        this.docAbortController = null;
        this.viewingDocument = false;
        this.currentDocIndex = -1;
        this.resultDocIds = [];
        this.resultDocSlugs = {};
        this._searchUrl = null;

        // Listen for browser back/forward
        this._onPopState = this._handlePopState.bind(this);
        window.addEventListener('popstate', this._onPopState);

        // Keyboard nav: left/right arrows for prev/next document
        this._onKeyDown = this._handleKeyDown.bind(this);
        window.addEventListener('keydown', this._onKeyDown);

        // Auto-open document if URL is /tekst/{id}-{slug}
        if (this.openDocValue) {
            // Save search URL so we can go back
            this._searchUrl = this.element.dataset.searchBaseUrl || '/szukaj';
            this._openDocById(this.openDocValue.toString(), false);
        }
    }

    // ─── Search ───

    onInput() {
        const el = this.element;
        if (el.classList.contains('layout-v4') || el.classList.contains('layout-v5') || el.classList.contains('layout-v6')) {
            return;
        }
        clearTimeout(this.debounceTimer);
        this.currentPage = 1;
        this.debounceTimer = setTimeout(() => {
            this.fetchResults();
        }, 400);
    }

    onEnterSearch(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.currentPage = 1;
            this.fetchResults();
        }
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

        if (this.hasTagSelectTarget) {
            this.tagSelectTarget.value = this.selectedTagId || '';
        }

        this.fetchResults();
    }

    onTagSelectChange(event) {
        const val = event.currentTarget.value;
        this.currentPage = 1;
        this.selectedTagId = val || null;

        if (this.hasTagsContainerTarget) {
            this.tagsContainerTarget.querySelectorAll('.tag-chip').forEach(c => {
                c.classList.toggle('active', c.dataset.tagId === val);
            });
        }

        this.fetchResults();
    }

    goHome(event) {
        event.preventDefault();
        // If viewing a document, close it first
        if (this.viewingDocument) {
            this._showSearchResults();
        }
        this.resetFilters();
        history.pushState({ view: 'search' }, '', '/szukaj');
        window.scrollTo({ top: 0, behavior: 'smooth' });
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
        if (this.hasTagSelectTarget) this.tagSelectTarget.value = '';
        this.element.classList.remove('search-active');
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

        const hasAnything = query || volume || type || this.selectedTagId || dateFrom || dateTo;
        this.element.classList.toggle('search-active', !!hasAnything);

        if (!hasAnything) {
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

        // Collect all result doc IDs + slugs for prev/next navigation
        this.resultDocIds = [];
        this.resultDocSlugs = {};
        this.resultsTarget.querySelectorAll('[data-doc-id]').forEach(el => {
            this.resultDocIds.push(el.dataset.docId);
            this.resultDocSlugs[el.dataset.docId] = el.dataset.docSlug || '';
        });
        this.currentDocIndex = this.resultDocIds.indexOf(docId);

        this._openDocById(docId, true);
    }

    prevDocument() {
        if (this.currentDocIndex > 0) {
            this.currentDocIndex--;
            this._openDocById(this.resultDocIds[this.currentDocIndex], true);
        }
    }

    nextDocument() {
        if (this.currentDocIndex < this.resultDocIds.length - 1) {
            this.currentDocIndex++;
            this._openDocById(this.resultDocIds[this.currentDocIndex], true);
        }
    }

    _buildDocUrl(docId) {
        const slug = this.resultDocSlugs[docId] || '';
        if (slug) {
            return '/tekst/' + docId + '-' + slug;
        }
        return '/tekst/' + docId;
    }

    _openDocById(docId, pushState) {
        let previewUrl = this.previewUrlValue.replace('/0/', '/' + docId + '/');
        const query = this.hasInputTarget ? this.inputTarget.value.trim() : '';
        if (query) {
            previewUrl += '?q=' + encodeURIComponent(query);
        }

        const docUrl = this._buildDocUrl(docId);

        if (pushState) {
            history.pushState({ view: 'document', docId: docId }, '', docUrl);
        } else {
            // Replace current URL without adding history entry (for initial load)
            history.replaceState({ view: 'document', docId: docId }, '', docUrl);
        }
        this.viewingDocument = true;

        this._showDocumentViewer();
        this._updateDocNav();
        this.loadDocument(previewUrl);
    }

    _updateDocNav() {
        const nav = document.getElementById('doc-nav-info');
        const prevBtn = document.getElementById('doc-prev-btn');
        const nextBtn = document.getElementById('doc-next-btn');
        if (!nav) return;

        const total = this.resultDocIds.length;
        const idx = this.currentDocIndex;

        if (total > 0 && idx >= 0) {
            nav.textContent = (idx + 1) + ' / ' + total;
            nav.style.display = '';
        } else {
            nav.style.display = 'none';
        }

        if (prevBtn) {
            prevBtn.style.opacity = idx > 0 ? '1' : '.3';
            prevBtn.style.pointerEvents = idx > 0 ? 'auto' : 'none';
        }
        if (nextBtn) {
            nextBtn.style.opacity = idx < total - 1 ? '1' : '.3';
            nextBtn.style.pointerEvents = idx < total - 1 ? 'auto' : 'none';
        }
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
            this._showSearchResults();
            // Push search URL to history
            history.pushState({ view: 'search' }, '', '/szukaj');
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
        const state = event.state;
        if (state && state.view === 'document' && state.docId) {
            // Forward navigation to a document
            this._openDocById(state.docId, false);
        } else if (this.viewingDocument) {
            this._showSearchResults();
        }
    }

    _handleKeyDown(event) {
        if (!this.viewingDocument) return;
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.prevDocument();
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.nextDocument();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.closeDocument();
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
        window.removeEventListener('keydown', this._onKeyDown);
    }
}
