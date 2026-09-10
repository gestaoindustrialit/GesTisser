(function (root, factory) {
    const api = factory(root && root.document);
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.GesTisserSearchableSelect = api;
}(typeof window !== 'undefined' ? window : globalThis, function (document) {
    'use strict';

    const relationshipPattern = /(^|\[|_)(raw_material|material|ink|article|product|customer|supplier|machine|operation|employee|collaborator|user|warehouse|location|production_order|order|category|department|team|project|assignee|manager|responsible|backup|schedule|reason|work_center|checklist|address)(_|\]|$)/i;
    const excludedPattern = /(^|\[|_)(status|state|active|enabled|type|level|score|period|month|year|timezone|per_page|timing|unit)(_|\]|$)/i;

    function normalize(value) {
        return String(value == null ? '' : value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-PT');
    }

    function shouldEnhance(select) {
        if (!select || select.dataset.searchableSelect === 'off' || select.classList.contains('gt-search-select-native')) return false;
        if (select.classList.contains('js-searchable-select')) return true;
        const name = select.name || select.id || '';
        return !excludedPattern.test(name) && relationshipPattern.test(name) && select.options.length >= 6;
    }

    function highlight(container, text, query) {
        container.textContent = '';
        const normalizedText = normalize(text);
        const normalizedQuery = normalize(query).trim();
        const index = normalizedQuery ? normalizedText.indexOf(normalizedQuery) : -1;
        if (index < 0) { container.textContent = text; return; }
        container.append(document.createTextNode(text.slice(0, index)));
        const mark = document.createElement('mark');
        mark.textContent = text.slice(index, index + normalizedQuery.length);
        container.append(mark, document.createTextNode(text.slice(index + normalizedQuery.length)));
    }

    class SearchableSelect {
        constructor(select) {
            this.select = select;
            this.multiple = select.multiple;
            this.remoteUrl = select.dataset.searchUrl || '';
            this.abortController = null;
            this.remoteTimer = null;
            this.activeIndex = -1;
            this.build();
            this.bind();
            this.refresh();
            select.dataset.searchSelectInitialized = 'true';
        }

        build() {
            this.root = document.createElement('div');
            this.root.className = 'gt-search-select';
            this.control = document.createElement('button');
            this.control.type = 'button';
            this.control.className = 'gt-search-select-control';
            this.control.setAttribute('role', 'combobox');
            this.control.setAttribute('aria-haspopup', 'listbox');
            this.control.setAttribute('aria-expanded', 'false');
            this.control.setAttribute('aria-required', String(this.select.required));
            const labelledBy = this.select.getAttribute('aria-labelledby');
            const label = this.select.labels && this.select.labels[0];
            if (labelledBy) this.control.setAttribute('aria-labelledby', labelledBy);
            else this.control.setAttribute('aria-label', this.select.getAttribute('aria-label') || (label && label.textContent.trim()) || 'Selecionar opção');
            this.value = document.createElement('span');
            this.value.className = 'gt-search-select-value';
            this.clear = document.createElement('button');
            this.clear.type = 'button';
            this.clear.className = 'gt-search-select-clear';
            this.clear.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            this.clear.setAttribute('aria-label', 'Limpar seleção');
            const chevron = document.createElement('i');
            chevron.className = 'bi bi-chevron-down gt-search-select-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            this.control.append(this.value, chevron);
            this.menu = document.createElement('div');
            this.menu.className = 'gt-search-select-menu';
            const searchWrap = document.createElement('div');
            searchWrap.className = 'gt-search-select-search-wrap';
            searchWrap.innerHTML = '<i class="bi bi-search gt-search-select-search-icon" aria-hidden="true"></i>';
            this.search = document.createElement('input');
            this.search.type = 'search';
            this.search.className = 'gt-search-select-search';
            this.search.autocomplete = 'off';
            this.search.placeholder = this.select.dataset.searchPlaceholder || 'Pesquisar por código, nome ou referência…';
            this.search.setAttribute('aria-label', this.search.placeholder);
            this.spinner = document.createElement('span');
            this.spinner.className = 'gt-search-select-spinner';
            this.spinner.hidden = true;
            searchWrap.append(this.search, this.spinner);
            this.list = document.createElement('ul');
            this.list.className = 'gt-search-select-list';
            this.list.id = 'gt-search-list-' + Math.random().toString(36).slice(2);
            this.list.setAttribute('role', 'listbox');
            this.list.setAttribute('aria-multiselectable', String(this.multiple));
            this.control.setAttribute('aria-controls', this.list.id);
            this.menu.append(searchWrap, this.list);
            this.select.parentNode.insertBefore(this.root, this.select);
            this.root.append(this.select, this.control, this.clear);
            document.body.appendChild(this.menu);
            this.select.classList.add('gt-search-select-native');
            this.control.disabled = this.select.disabled;
            this.menu.addEventListener('gt-close', () => this.close());
            this.select._gtSearchableSelect = this;
        }

        options() {
            return Array.from(this.select.options).map((option) => ({
                value: option.value,
                title: option.dataset.title || option.text.trim(),
                display: option.dataset.display || option.text.trim(),
                meta: option.dataset.meta || option.dataset.description || '',
                search: [option.text, option.value, option.dataset.search, option.dataset.code, option.dataset.reference, option.dataset.category, option.dataset.pantone, option.dataset.nif].filter(Boolean).join(' '),
                selected: option.selected,
                disabled: option.disabled,
                option
            }));
        }

        refresh(query) {
            const q = normalize(query || '');
            const records = this.options().filter((item) => !q || normalize(item.search + ' ' + item.meta).includes(q));
            this.render(records, query || '');
            const selected = this.options().filter((item) => item.selected);
            this.value.textContent = selected.length ? selected.map((item) => item.display).join(', ') : (this.select.dataset.placeholder || this.select.options[0]?.text || 'Selecionar…');
            this.value.classList.toggle('gt-search-select-placeholder', !selected.length || (selected.length === 1 && selected[0].value === ''));
            const canClear = !this.select.required && selected.some((item) => item.value !== '');
            this.clear.hidden = !canClear;
            this.control.disabled = this.select.disabled;
        }

        render(records, query) {
            this.list.textContent = '';
            this.activeIndex = records.length ? 0 : -1;
            if (!records.length) {
                const empty = document.createElement('li');
                empty.className = 'gt-search-select-empty';
                empty.textContent = this.remoteUrl && normalize(query).length < 2 ? 'Escreva pelo menos 2 caracteres para pesquisar.' : 'Não foram encontrados resultados.';
                this.list.append(empty);
                return;
            }
            records.forEach((item, index) => {
                const row = document.createElement('li');
                row.className = 'gt-search-select-option' + (item.selected ? ' is-selected' : '') + (index === 0 ? ' is-active' : '');
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', String(item.selected));
                row.dataset.value = item.value;
                const title = document.createElement('span');
                title.className = 'gt-search-select-option-title';
                highlight(title, item.title, query);
                const meta = document.createElement('small');
                meta.className = 'gt-search-select-option-meta';
                highlight(meta, item.meta, query);
                const check = document.createElement('i');
                check.className = 'bi bi-check2 gt-search-select-option-check';
                check.setAttribute('aria-hidden', 'true');
                row.append(title, meta, check);
                row.addEventListener('mousedown', (event) => event.preventDefault());
                row.addEventListener('click', () => this.choose(item.value));
                this.list.append(row);
            });
        }

        bind() {
            this.control.addEventListener('click', () => this.toggle());
            this.clear.addEventListener('click', () => { this.clearSelection(); });
            this.search.addEventListener('input', () => {
                if (this.remoteUrl) this.scheduleRemote(this.search.value);
                else this.refresh(this.search.value);
            });
            this.search.addEventListener('keydown', (event) => this.onKeydown(event));
            this.control.addEventListener('keydown', (event) => {
                if (event.key.length === 1 && !event.ctrlKey && !event.metaKey) { this.open(); this.search.value = event.key; this.search.dispatchEvent(new Event('input')); }
                else if (['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) { event.preventDefault(); this.open(); }
                else if (event.key === 'Escape') this.close();
            });
            this.select.addEventListener('change', () => this.refresh(this.search.value));
            this.select.addEventListener('invalid', () => { this.open(); this.control.focus(); });
            window.addEventListener('resize', () => this.isOpen() && this.position());
            window.addEventListener('scroll', () => this.isOpen() && this.position(), true);
        }

        isOpen() { return this.menu.classList.contains('is-open'); }
        toggle() { this.isOpen() ? this.close() : this.open(); }
        open() {
            document.querySelectorAll('.gt-search-select-menu.is-open').forEach((menu) => { if (menu !== this.menu) menu.dispatchEvent(new CustomEvent('gt-close')); });
            this.root.classList.add('is-open'); this.menu.classList.add('is-open'); this.control.setAttribute('aria-expanded', 'true');
            this.search.value = ''; this.refresh(); this.position();
            const selected = this.list.querySelector('.is-selected'); if (selected) selected.scrollIntoView({block: 'nearest'});
            setTimeout(() => this.search.focus(), 0);
        }
        close() { this.root.classList.remove('is-open'); this.menu.classList.remove('is-open'); this.control.setAttribute('aria-expanded', 'false'); }
        position() {
            const rect = this.control.getBoundingClientRect();
            const below = innerHeight - rect.bottom; const height = Math.min(390, innerHeight * .6);
            this.menu.style.width = rect.width + 'px'; this.menu.style.left = rect.left + 'px';
            this.menu.style.top = (below >= Math.min(height, 260) ? rect.bottom + 5 : Math.max(8, rect.top - height - 5)) + 'px';
        }
        onKeydown(event) {
            const rows = Array.from(this.list.querySelectorAll('.gt-search-select-option'));
            if (event.key === 'Escape') { event.preventDefault(); this.close(); this.control.focus(); return; }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault(); if (!rows.length) return;
                this.activeIndex = (this.activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
                rows.forEach((row, i) => row.classList.toggle('is-active', i === this.activeIndex)); rows[this.activeIndex].scrollIntoView({block: 'nearest'});
            } else if (event.key === 'Enter' && rows[this.activeIndex]) { event.preventDefault(); this.choose(rows[this.activeIndex].dataset.value); }
        }
        choose(value) {
            const option = Array.from(this.select.options).find((item) => item.value === value); if (!option || option.disabled) return;
            if (this.multiple) option.selected = !option.selected; else this.select.value = value;
            this.select.dispatchEvent(new Event('change', {bubbles: true}));
            if (!this.multiple) { this.close(); this.control.focus(); } else this.refresh(this.search.value);
        }
        clearSelection() {
            Array.from(this.select.options).forEach((option) => { option.selected = false; });
            if (!this.multiple) this.select.value = '';
            this.select.dispatchEvent(new Event('change', {bubbles: true})); this.refresh();
        }
        scheduleRemote(query) {
            clearTimeout(this.remoteTimer); if (this.abortController) this.abortController.abort();
            if (normalize(query.trim()).length < 2) { this.refresh(query); return; }
            this.remoteTimer = setTimeout(() => this.loadRemote(query), Number(this.select.dataset.searchDelay || 275));
        }
        async loadRemote(query) {
            this.abortController = new AbortController(); this.spinner.hidden = false;
            try {
                const url = new URL(this.remoteUrl, location.href); url.searchParams.set('q', query); url.searchParams.set('limit', this.select.dataset.searchLimit || '30');
                const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: this.abortController.signal, credentials: 'same-origin'});
                if (!response.ok) throw new Error('search failed');
                const payload = await response.json(); const items = Array.isArray(payload) ? payload : (payload.results || payload.items || []);
                const selectedValues = new Set(Array.from(this.select.selectedOptions).map((option) => option.value));
                Array.from(this.select.options).filter((option) => !option.selected && option.value !== '').forEach((option) => option.remove());
                items.forEach((item) => {
                    const value = String(item.value ?? item.id ?? item.code ?? ''); if (!value) return;
                    let option = Array.from(this.select.options).find((candidate) => candidate.value === value);
                    if (!option) { option = new Option(item.title || item.label || item.name || value, value); this.select.add(option); }
                    option.dataset.title = item.title || item.label || item.name || option.text;
                    option.dataset.meta = item.meta || item.description || item.secondary || '';
                    option.dataset.search = item.search || [item.code, item.reference, item.category, item.pantone, item.nif].filter(Boolean).join(' ');
                    option.selected = selectedValues.has(value);
                });
                this.refresh(query);
            } catch (error) { if (error.name !== 'AbortError') { this.list.innerHTML = '<li class="gt-search-select-empty">Não foi possível carregar os resultados. Tente novamente.</li>'; } }
            finally { this.spinner.hidden = true; }
        }
    }

    function init(scope) {
        if (!document) return [];
        const target = scope || document;
        const selects = (target.matches && target.matches('select')) ? [target] : Array.from(target.querySelectorAll('select'));
        return selects.filter(shouldEnhance).filter((select) => !select.dataset.searchSelectInitialized).map((select) => new SearchableSelect(select));
    }

    if (document) {
        const start = () => {
            init(document);
            document.addEventListener('pointerdown', (event) => document.querySelectorAll('.gt-search-select-menu.is-open').forEach((menu) => {
                const instanceRoot = Array.from(document.querySelectorAll('.gt-search-select')).find((node) => node.querySelector('[aria-controls="' + menu.querySelector('[role=listbox]')?.id + '"]'));
                if (!menu.contains(event.target) && !instanceRoot?.contains(event.target)) menu.dispatchEvent(new CustomEvent('gt-close'));
            }));
            new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                init(node);
                const ownerSelect = node.closest && node.closest('select');
                if (ownerSelect) {
                    if (ownerSelect._gtSearchableSelect) ownerSelect._gtSearchableSelect.refresh(ownerSelect._gtSearchableSelect.search.value);
                    else init(ownerSelect);
                }
            }))).observe(document.body, {childList: true, subtree: true});
        };
        document.addEventListener('gt-close', (event) => event.target.classList.remove('is-open'), true);
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
    }
    return {normalize, shouldEnhance, init, SearchableSelect};
}));
