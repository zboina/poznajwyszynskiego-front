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
        myViewsUrl: String,
        openDoc: Number,
        accessLevel: String,
        viewsRemaining: Number,
    };
    static targets = [
        'input', 'volume', 'type', 'dateFrom', 'dateTo', 'tagsContainer', 'tagSelect', 'results',
        'hero', 'filters', 'documentViewer', 'documentContent', 'viewModal', 'myViewsDropdown',
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
        this._pendingDocId = null;
        this._viewedDocIds = new Set();

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

        this._updateFilterBadge();
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
        this._updateFilterBadge();
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

        this._updateFilterBadge();
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

        this._updateFilterBadge();
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
        this._updateFilterBadge();
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
                this._syncViewedFromDom();
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
        this.resultsTarget.querySelectorAll('.result-card[data-doc-id]').forEach(el => {
            this.resultDocIds.push(el.dataset.docId);
            this.resultDocSlugs[el.dataset.docId] = el.dataset.docSlug || '';
        });
        this.currentDocIndex = this.resultDocIds.indexOf(docId);

        if (this._shouldShowViewModal(docId)) {
            this._showViewModal(docId);
            return;
        }

        this._openDocById(docId, true);
    }

    prevDocument() {
        if (this.currentDocIndex > 0) {
            this.currentDocIndex--;
            const docId = this.resultDocIds[this.currentDocIndex];
            if (this._shouldShowViewModal(docId)) {
                this._showViewModal(docId);
                return;
            }
            this._openDocById(docId, true);
        }
    }

    nextDocument() {
        if (this.currentDocIndex < this.resultDocIds.length - 1) {
            this.currentDocIndex++;
            const docId = this.resultDocIds[this.currentDocIndex];
            if (this._shouldShowViewModal(docId)) {
                this._showViewModal(docId);
                return;
            }
            this._openDocById(docId, true);
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
            if (this._pendingDocId) {
                this.cancelViewModal();
            } else {
                this.closeDocument();
            }
        }
    }

    // ─── My views dropdown ───

    toggleMyViews(event) {
        event.preventDefault();
        event.stopPropagation();
        var dropdown = document.getElementById('myViewsDropdown');
        if (!dropdown) return;

        var isOpen = dropdown.classList.contains('active');
        if (isOpen) {
            dropdown.classList.remove('active');
            document.removeEventListener('click', this._closeMyViewsBound);
            return;
        }

        // Fetch and show
        dropdown.classList.add('active');
        var list = document.getElementById('myViewsList');
        if (list) list.innerHTML = '<div class="mv-empty">Ładowanie...</div>';

        var self = this;
        fetch(this.myViewsUrlValue, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            if (list) list.innerHTML = html;
        })
        .catch(function() {
            if (list) list.innerHTML = '<div class="mv-empty">Błąd ładowania</div>';
        });

        // Close on outside click
        this._closeMyViewsBound = function(e) {
            if (!dropdown.contains(e.target) && !e.target.closest('.views-pill')) {
                dropdown.classList.remove('active');
                document.removeEventListener('click', self._closeMyViewsBound);
            }
        };
        setTimeout(function() {
            document.addEventListener('click', self._closeMyViewsBound);
        }, 10);
    }

    openViewedDoc(event) {
        event.preventDefault();
        var el = event.currentTarget;
        var docId = el.dataset.docId;
        var slug = el.dataset.docSlug || '';
        if (!docId) return;

        // Close dropdown
        var dropdown = document.getElementById('myViewsDropdown');
        if (dropdown) dropdown.classList.remove('active');

        // Set up for navigation
        this.resultDocSlugs[docId] = slug;
        this._viewedDocIds.add(String(docId));

        this._openDocById(docId, true);
    }

    // ─── View limit modal ───

    _shouldShowViewModal(docId) {
        // Only ROLE_USER with view limits
        if (this.accessLevelValue !== 'user') return false;
        // No limit attribute at all
        if (!this.hasViewsRemainingValue) return false;
        // Limit exhausted — no modal, backend handles it
        if (this.viewsRemainingValue <= 0) return false;
        // Already viewed — check JS set
        if (this._viewedDocIds.has(String(docId))) return false;
        // Already viewed — check DOM class (backend-rendered)
        var found = document.querySelector('.result-card[data-doc-id="' + docId + '"]');
        if (found && found.classList.contains('rc-viewed')) {
            this._viewedDocIds.add(String(docId));
            return false;
        }
        return true;
    }

    _syncViewedFromDom() {
        var self = this;
        document.querySelectorAll('.result-card.rc-viewed').forEach(function(el) {
            if (el.dataset.docId) self._viewedDocIds.add(String(el.dataset.docId));
        });
    }

    _showViewModal(docId) {
        this._pendingDocId = docId;
        var remaining = this.viewsRemainingValue;

        var modal = document.getElementById('viewLimitModal');
        if (!modal) {
            this._openDocById(docId, true);
            return;
        }

        var remainingEl = document.getElementById('viewModalRemaining');
        var meterEl = document.getElementById('viewModalMeter');
        if (remainingEl) remainingEl.textContent = remaining;
        if (meterEl) meterEl.style.width = ((remaining / 5) * 100) + '%';

        if (meterEl) {
            if (remaining <= 1) {
                meterEl.style.background = '#e53e3e';
            } else if (remaining <= 2) {
                meterEl.style.background = 'linear-gradient(90deg, #e53e3e, #C9A227)';
            } else {
                meterEl.style.background = 'linear-gradient(90deg, #2fb344, #C9A227)';
            }
        }

        modal.classList.add('active');
    }

    cancelViewModal() {
        this._pendingDocId = null;
        var modal = document.getElementById('viewLimitModal');
        if (modal) modal.classList.remove('active');
    }

    confirmViewModal() {
        var docId = this._pendingDocId;
        this._pendingDocId = null;

        var modal = document.getElementById('viewLimitModal');
        if (modal) modal.classList.remove('active');

        if (!docId) return;

        // Track viewed + decrement
        this._viewedDocIds.add(String(docId));
        if (this.hasViewsRemainingValue && this.viewsRemainingValue > 0) {
            this.viewsRemainingValue--;
        }

        // Update navbar pill
        var pill = document.querySelector('.views-pill');
        if (pill) {
            pill.textContent = this.viewsRemainingValue + '/5';
            if (this.viewsRemainingValue <= 1) pill.style.color = '#e53e3e';
        }

        // Mark card as viewed in DOM — use document.querySelector (not Stimulus target)
        var card = document.querySelector('.result-card[data-doc-id="' + docId + '"]');
        if (card) {
            card.classList.add('rc-viewed');
            var title = card.querySelector('.rc-title');
            if (title && !title.querySelector('.rc-viewed-badge')) {
                var badge = document.createElement('span');
                badge.className = 'rc-viewed-badge';
                badge.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg> Odczytany';
                title.appendChild(badge);
            }
        }

        this._openDocById(docId, true);
    }

    // ─── Filter badge (mobile) ───

    _updateFilterBadge() {
        const badge = document.getElementById('filterCountBadge');
        if (!badge) return;

        let count = 0;
        if (this.hasVolumeTarget && this.volumeTarget.value) count++;
        if (this.hasTypeTarget && this.typeTarget.value) count++;
        if (this.hasDateFromTarget && this.dateFromTarget.value) count++;
        if (this.hasDateToTarget && this.dateToTarget.value) count++;
        if (this.hasTagSelectTarget && this.tagSelectTarget.value) count++;

        badge.textContent = count;
        badge.classList.toggle('visible', count > 0);
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
