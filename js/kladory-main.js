/*!
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Kladory – Oberfläche (Kursnotizbücher, Export/Import)
 * Ohne Build-Schritt: reines JavaScript, wird direkt von Nextcloud geladen.
 */
(function () {
	'use strict'

	const COLORS = ['#4f6bed', '#1a73e8', '#00897b', '#0f9d58', '#7cb342', '#f9ab00', '#e8710a', '#d93025', '#c2185b', '#9334e6']
	const STORAGE_KEY = 'kladory.state.v1'

	const state = {
		notebooks: [],
		expanded: new Set(),
		sectionId: null,
		pages: [],
		pageId: null,
		page: null,
		tags: [], // alle Tags des Nutzers [{tag, count}]
		search: { q: '', results: null, loading: false },
		canCreateClass: false,
		expandedStudents: new Set(),
	}

	let root, navEl, pagesEl, contentEl
	let navBody, searchInput
	let selectSeq = 0

	// ------------------------------------------------------------------ Hilfen

	function el(tag, props, ...children) {
		const e = document.createElement(tag)
		for (const [k, v] of Object.entries(props || {})) {
			if (v === null || v === undefined || v === false) continue
			if (k === 'class') e.className = v
			else if (k === 'style' && typeof v === 'object') Object.assign(e.style, v)
			else if (k === 'dataset') Object.assign(e.dataset, v)
			else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2).toLowerCase(), v)
			else if (k === 'value') e.value = v
			else e.setAttribute(k, v === true ? '' : v)
		}
		for (const c of children.flat(Infinity)) {
			if (c === null || c === undefined || c === false) continue
			e.append(c instanceof Node ? c : document.createTextNode(String(c)))
		}
		return e
	}

	const ICONS = {
		plus: '<path d="M12 5v14M5 12h14"/>',
		more: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
		chevron: '<path d="M9 6l6 6-6 6"/>',
		back: '<path d="M15 6l-6 6 6 6"/>',
		notebook: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v18M12 8h4M12 12h4"/>',
		page: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
		section: '<path d="M4 6h16M4 12h16M4 18h10"/>',
		menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
		panelClose: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M16 9l-3 3 3 3"/>',
		panelOpen: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M13 9l3 3-3 3"/>',
		trashcan: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		restore: '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5"/>',
		search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
		tag: '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8" r="1.3"/>',
		close: '<path d="M6 6l12 12M18 6L6 18"/>',
		school: '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5M22 9v6"/>',
		library: '<path d="M4 19V5M8 19V5M12 19l3-14 4 1-3 14z"/><path d="M3 19h18"/>',
		group: '<circle cx="9" cy="8" r="3"/><path d="M3 19c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.3"/><path d="M16 13.6c2.9.1 5 2 5 5"/>',
		user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>',
		lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
		download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
		share: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.9l7.6-3.8M8.2 13.1l7.6 3.8"/>',
	}

	function icon(name) {
		const span = el('span', { class: 'kl-icon', 'aria-hidden': 'true' })
		span.innerHTML = '<svg viewBox="0 0 24 24">' + (ICONS[name] || '') + '</svg>'
		return span
	}

	function iconButton(name, label, onClick, extraClass) {
		return el('button', {
			class: 'kl-icon-btn' + (extraClass ? ' ' + extraClass : ''),
			type: 'button',
			title: label,
			'aria-label': label,
			onclick: (ev) => { ev.stopPropagation(); onClick(ev) },
		}, icon(name))
	}

	function backButton(view) {
		return iconButton('back', 'Zurück', () => setView(view), 'kl-back')
	}

	// Tablet (iPad hochkant): Notizbücher als ausklappbare Leiste
	function navToggleButton() {
		return iconButton('menu', 'Notizbücher anzeigen', () => setNavOpen(!root.classList.contains('kl-nav-open')), 'kl-nav-toggle')
	}

	function setNavOpen(open) {
		root.classList.toggle('kl-nav-open', open)
	}

	// Seitenleisten einklappen: mehr Platz zum Schreiben (wird pro Gerät gemerkt)
	let focusMode = false
	try { focusMode = localStorage.getItem('kladory.focus') === '1' } catch (e) { /* egal */ }

	function applyFocus(pageShown) {
		root.classList.toggle('kl-focus', focusMode && pageShown)
	}

	function focusToggleButton() {
		const b = iconButton(focusMode ? 'panelOpen' : 'panelClose',
			focusMode ? 'Notizbücher und Seiten einblenden' : 'Notizbücher und Seiten ausblenden (mehr Platz)',
			() => {
				focusMode = !focusMode
				try { localStorage.setItem('kladory.focus', focusMode ? '1' : '0') } catch (e) { /* egal */ }
				applyFocus(true)
				setNavOpen(false)
				b.replaceWith(focusToggleButton())
			}, 'kl-focus-toggle')
		b.setAttribute('aria-pressed', focusMode ? 'true' : 'false')
		return b
	}

	function fmtDate(ts) {
		if (!ts) return ''
		return new Date(ts * 1000).toLocaleString('de-DE', {
			day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
		})
	}

	function findSection(id) {
		for (const nb of state.notebooks) {
			for (const s of nb.sections) {
				if (s.id === id) return { nb, s }
			}
		}
		return null
	}

	function setView(view) {
		root.dataset.view = view
	}

	// ------------------------------------------------------------------ API

	function requestToken() {
		return document.head.dataset.requesttoken || (window.OC && window.OC.requestToken) || ''
	}

	function apiUrl(path) {
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl('/apps/kladory/api' + path)
		}
		return '/index.php/apps/kladory/api' + path
	}

	async function api(method, path, body, keepalive) {
		const res = await fetch(apiUrl(path), {
			method,
			credentials: 'same-origin',
			keepalive: !!keepalive,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				requesttoken: requestToken(),
			},
			body: body !== undefined ? JSON.stringify(body) : undefined,
		})
		let data = null
		try { data = await res.json() } catch (e) { /* leer */ }
		if (!res.ok) {
			const err = new Error((data && data.message) || ('Serverfehler (' + res.status + ')'))
			err.status = res.status
			err.data = data
			throw err
		}
		return data
	}

	// ------------------------------------------------------------------ Speicher (letzte Auswahl)

	function saveLocal() {
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify({
				sectionId: state.sectionId,
				pageId: state.pageId,
				expanded: [...state.expanded],
			}))
		} catch (e) { /* ignorieren */ }
	}

	function loadLocal() {
		try {
			return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}
		} catch (e) {
			return {}
		}
	}

	// ------------------------------------------------------------------ Toast, Dialog, Menü

	let toastTimer = null
	function toast(text, actionLabel, action) {
		let t = document.querySelector('.kl-toast')
		if (!t) {
			t = el('div', { class: 'kl-toast', role: 'status' })
			document.body.append(t)
		}
		t.replaceChildren(el('span', {}, text))
		if (actionLabel && action) {
			t.append(el('button', {
				class: 'kl-toast-action',
				type: 'button',
				onclick: () => { t.classList.remove('show'); action() },
			}, actionLabel))
		}
		t.classList.add('show')
		clearTimeout(toastTimer)
		toastTimer = setTimeout(() => t.classList.remove('show'), actionLabel ? 8000 : 4000)
	}

	function confirmDialog(text, okLabel, cancelLabel, okClass) {
		return new Promise((resolve) => {
			const close = (v) => {
				overlay.remove()
				document.removeEventListener('keydown', onKey, true)
				resolve(v)
			}
			const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(false) } }
			const okBtn = el('button', { class: 'kl-btn ' + (okClass || 'kl-btn-danger'), type: 'button', onclick: () => close(true) }, okLabel || 'Löschen')
			const overlay = el('div', { class: 'kl-overlay', onclick: (e) => { if (e.target === overlay) close(false) } },
				el('div', { class: 'kl-dialog', role: 'dialog', 'aria-modal': 'true' },
					el('p', {}, text),
					el('div', { class: 'kl-dialog-actions' },
						el('button', { class: 'kl-btn', type: 'button', onclick: () => close(false) }, cancelLabel || 'Abbrechen'),
						okBtn,
					),
				),
			)
			document.body.append(overlay)
			document.addEventListener('keydown', onKey, true)
			okBtn.focus()
		})
	}

	let menuEl = null
	function closeMenu() {
		if (!menuEl) return
		menuEl.remove()
		menuEl = null
		document.removeEventListener('mousedown', onOutside, true)
		document.removeEventListener('keydown', onMenuKey, true)
	}
	function onOutside(e) { if (menuEl && !menuEl.contains(e.target)) closeMenu() }
	function onMenuKey(e) { if (e.key === 'Escape') closeMenu() }

	function openMenu(anchor, items) {
		closeMenu()
		const m = el('div', { class: 'kl-menu', role: 'menu' }, items.map((it) => {
			if (it.colors) {
				return el('div', {},
					el('div', { class: 'kl-colors-label' }, 'Farbe'),
					el('div', { class: 'kl-colors' }, COLORS.map((c) => el('button', {
						class: 'kl-swatch' + (c === it.current ? ' active' : ''),
						type: 'button',
						style: { background: c },
						title: c,
						'aria-label': 'Farbe ' + c,
						onclick: () => { closeMenu(); it.onPick(c) },
					}))),
				)
			}
			return el('button', {
				class: 'kl-menu-item' + (it.danger ? ' danger' : ''),
				type: 'button',
				role: 'menuitem',
				onclick: () => { closeMenu(); it.action() },
			}, icon(it.icon), it.label)
		}))
		document.body.append(m)
		const r = anchor.getBoundingClientRect()
		const top = Math.min(r.bottom + 4, window.innerHeight - m.offsetHeight - 8)
		const left = Math.min(r.left, window.innerWidth - m.offsetWidth - 8)
		m.style.top = Math.max(8, top) + 'px'
		m.style.left = Math.max(8, left) + 'px'
		menuEl = m
		setTimeout(() => {
			document.addEventListener('mousedown', onOutside, true)
			document.addEventListener('keydown', onMenuKey, true)
		})
		const first = m.querySelector('button')
		if (first) first.focus()
	}

	function startRename(labelEl, value, onSave) {
		if (!labelEl) return
		const input = el('input', { class: 'kl-rename', type: 'text', value, maxlength: '200', 'aria-label': 'Neuer Name' })
		labelEl.replaceWith(input)
		input.focus()
		input.select()
		let done = false
		const finish = async (commit) => {
			if (done) return
			done = true
			const v = input.value.trim()
			if (commit && v && v !== value) {
				try { await onSave(v) } catch (e) { toast(e.message) }
			}
			renderNav()
			renderPages()
		}
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') { e.preventDefault(); finish(true) } else if (e.key === 'Escape') { e.preventDefault(); finish(false) }
		})
		input.addEventListener('blur', () => finish(true))
		input.addEventListener('click', (e) => e.stopPropagation())
	}

	// ------------------------------------------------------------------ Navigation (Notizbücher + Abschnitte)

	function buildNavSkeleton() {
		searchInput = el('input', {
			class: 'kl-search-input',
			type: 'search',
			placeholder: 'Suchen … (#tag für Tags)',
			'aria-label': 'Notizen durchsuchen',
			autocomplete: 'off',
		})
		let timer = null
		searchInput.addEventListener('input', () => {
			clearTimeout(timer)
			timer = setTimeout(() => runSearch(searchInput.value), 250)
		})
		searchInput.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { searchInput.value = ''; runSearch('') }
			if (e.key === 'Enter') { clearTimeout(timer); runSearch(searchInput.value) }
		})
		navBody = el('div', { class: 'kl-scroll' })
		navEl.replaceChildren(
			el('div', { class: 'kl-col-header' },
				el('h2', {}, 'Notizbücher'),
				iconButton('plus', 'Neues Notizbuch', (ev) => newNotebookMenu(ev.currentTarget))),
			el('div', { class: 'kl-search' },
				icon('search'),
				searchInput,
				iconButton('close', 'Suche leeren', () => { searchInput.value = ''; runSearch(''); searchInput.focus() }, 'kl-search-clear')),
			navBody,
			el('div', { class: 'kl-nav-footer' },
				el('button', { class: 'kl-trash-link', type: 'button', onclick: openTrash }, icon('trashcan'), 'Papierkorb'),
				el('button', { class: 'kl-trash-link', type: 'button', onclick: exportImportDialog }, icon('download'), 'Export / Import')),
		)
	}

	function renderNav() {
		if (!navBody) buildNavSkeleton()
		navEl.classList.toggle('kl-searching', state.search.results !== null || state.search.loading)
		if (state.search.results !== null || state.search.loading) {
			navBody.replaceChildren(renderSearchResults())
			return
		}
		const tree = state.notebooks.length
			? el('ul', { class: 'kl-tree' }, state.notebooks.map(notebookItem))
			: el('div', { class: 'kl-empty' },
				el('p', {}, 'Noch keine Notizbücher.'),
				el('button', { class: 'kl-btn kl-btn-primary', type: 'button', onclick: createNotebook }, 'Erstes Notizbuch anlegen'),
				state.canCreateClass ? el('button', { class: 'kl-btn kl-btn-secondary', type: 'button', onclick: createClassNotebook }, 'Kursnotizbuch anlegen') : null)
		const tags = state.tags.length
			? el('div', { class: 'kl-nav-tags' },
				el('div', { class: 'kl-nav-subhead' }, 'Tags'),
				el('div', { class: 'kl-chips' }, state.tags.map((t) => el('button', {
					class: 'kl-chip',
					type: 'button',
					title: t.count + (t.count === 1 ? ' Seite' : ' Seiten'),
					onclick: () => { searchInput.value = '#' + t.tag; runSearch(searchInput.value) },
				}, '#' + t.tag, el('span', { class: 'kl-chip-count' }, String(t.count))))))
			: null
		navBody.replaceChildren(tree, tags || '')
	}

	// ------------------------------------------------------------------ Suche

	let searchSeq = 0
	async function runSearch(raw) {
		const q = String(raw || '').trim()
		state.search.q = q
		if (!q || (q.length < 2 && !q.startsWith('#'))) {
			state.search.results = null
			state.search.loading = false
			renderNav()
			return
		}
		const seq = ++searchSeq
		state.search.loading = true
		renderNav()
		const params = q.startsWith('#')
			? 'tag=' + encodeURIComponent(q.slice(1))
			: 'q=' + encodeURIComponent(q)
		try {
			const results = await api('GET', '/search?' + params)
			if (seq !== searchSeq) return
			state.search.results = results
		} catch (e) {
			if (seq !== searchSeq) return
			state.search.results = []
			toast(e.message)
		}
		state.search.loading = false
		renderNav()
	}

	function highlight(text, q) {
		if (!q || q.startsWith('#')) return [text]
		const out = []
		const lower = text.toLowerCase()
		const needle = q.toLowerCase()
		let i = 0
		while (i < text.length) {
			const j = lower.indexOf(needle, i)
			if (j < 0) { out.push(text.slice(i)); break }
			if (j > i) out.push(text.slice(i, j))
			out.push(el('mark', {}, text.slice(j, j + needle.length)))
			i = j + needle.length
		}
		return out
	}

	function renderSearchResults() {
		const q = state.search.q
		if (state.search.loading && !state.search.results) return el('div', { class: 'kl-loading' }, 'Sucht …')
		const results = state.search.results || []
		const head = el('div', { class: 'kl-nav-subhead' },
			q.startsWith('#') ? 'Seiten mit ' + q : 'Treffer für „' + q + '“',
			el('span', { class: 'kl-chip-count' }, String(results.length)))
		if (!results.length) return el('div', {}, head, el('div', { class: 'kl-empty' }, 'Nichts gefunden.'))
		return el('div', {}, head, el('ul', { class: 'kl-results' }, results.map((r) => el('li', {
			class: 'kl-result' + (r.id === state.pageId ? ' active' : ''),
			tabindex: '0',
			onclick: () => openResult(r),
			onkeydown: (e) => { if (e.key === 'Enter') openResult(r) },
		},
		el('div', { class: 'kl-result-title' }, highlight(r.title || 'Unbenannte Seite', q)),
		el('div', { class: 'kl-result-path' },
			el('span', { class: 'kl-dot', style: { background: r.sectionColor } }),
			r.notebookName + ' › ' + r.sectionName),
		r.snippet ? el('div', { class: 'kl-result-snippet' }, highlight(r.snippet, q)) : null,
		))))
	}

	async function openResult(r) {
		state.expanded.add(r.notebookId)
		if (state.sectionId === r.sectionId) await selectPage(r.id)
		else await selectSection(r.sectionId, r.id)
		renderNav()
		setView('page')
	}

	async function refreshTags() {
		try {
			state.tags = await api('GET', '/tags')
		} catch (e) {
			state.tags = []
		}
		if (state.search.results === null) renderNav()
	}

	function newNotebookMenu(anchor) {
		if (!state.canCreateClass) { createNotebook(); return }
		openMenu(anchor, [
			{ label: 'Notizbuch', icon: 'notebook', action: createNotebook },
			{ label: 'Kursnotizbuch (Klasse/Kurs)', icon: 'school', action: createClassNotebook },
		])
	}

	const AREA_INFO = {
		library: { label: 'Inhaltsbibliothek', icon: 'library', title: 'Lehrkräfte schreiben, Schüler können nur lesen' },
		collab: { label: 'Gemeinsamer Bereich', icon: 'group', title: 'Alle können schreiben' },
		teacher: { label: 'Nur Lehrkräfte', icon: 'lock', title: 'Für Schüler unsichtbar' },
	}

	function notebookItem(nb) {
		const open = state.expanded.has(nb.id)
		const isClass = nb.kind === 'class'
		const menu = notebookMenu(nb)
		const row = el('div', {
			class: 'kl-row kl-nb-row',
			dataset: { key: 'nb-' + nb.id },
			onclick: () => toggleNotebook(nb.id),
		},
		el('span', { class: 'kl-chevron' + (open ? ' open' : '') }, icon('chevron')),
		el('span', { class: 'kl-nb-icon', style: { color: nb.color }, title: isClass ? 'Kursnotizbuch' : 'Notizbuch' }, icon(isClass ? 'school' : 'notebook')),
		el('span', { class: 'kl-label', title: nb.name + (isClass && !nb.isOwner ? ' – ' + nb.ownerName : '') }, nb.name),
		menu.length ? iconButton('more', 'Aktionen für Notizbuch', (ev) => openMenu(ev.currentTarget, menu), 'kl-more') : null,
		)
		const li = el('li', {}, row)
		if (open) {
			li.append(el('ul', { class: 'kl-sections' }, isClass
				? classSectionItems(nb)
				: [
					nb.sections.map((s) => sectionItem(nb, s)),
					el('li', {}, el('button', { class: 'kl-add-link', type: 'button', onclick: () => createSection(nb) },
						icon('plus'), 'Abschnitt hinzufügen')),
				]))
		}
		return li
	}

	function areaHead(iconName, label, title, addLabel, onAdd) {
		return el('li', { class: 'kl-area-head', title: title || '' },
			icon(iconName),
			el('span', { class: 'kl-area-label' }, label),
			onAdd ? iconButton('plus', addLabel, onAdd, 'kl-area-add') : null)
	}

	function classSectionItems(nb) {
		const items = []
		for (const area of ['library', 'collab', 'teacher']) {
			const secs = nb.sections.filter((s) => s.area === area)
			if (!secs.length && !nb.canManage) continue
			const info = AREA_INFO[area]
			items.push(areaHead(info.icon, info.label, info.title,
				'Abschnitt in „' + info.label + '“ anlegen', nb.canManage ? () => createSection(nb, area) : null))
			for (const s of secs) items.push(sectionItem(nb, s))
		}
		const studentSecs = nb.sections.filter((s) => s.area === 'student')
		if (nb.canManage) {
			items.push(areaHead('user', 'Schüler', 'Jeder Schüler sieht nur seinen eigenen Bereich',
				'Abschnitt für alle Schüler anlegen', () => addStudentSectionDialog(nb)))
			const byStudent = new Map()
			for (const st of nb.students || []) byStudent.set(st.id, { name: st.name, secs: [], former: false })
			for (const s of studentSecs) {
				if (!byStudent.has(s.studentId)) byStudent.set(s.studentId, { name: s.studentName || s.studentId, secs: [], former: true })
				byStudent.get(s.studentId).secs.push(s)
			}
			if (!byStudent.size) {
				items.push(el('li', { class: 'kl-area-empty' }, 'Noch keine Schüler – über „Mitglieder verwalten“ hinzufügen.'))
			}
			for (const [uid, info] of byStudent) {
				const key = nb.id + ':' + uid
				const containsCurrent = info.secs.some((s) => s.id === state.sectionId)
				const openSt = state.expandedStudents.has(key) || containsCurrent
				items.push(el('li', {}, el('div', {
					class: 'kl-row kl-student-row',
					onclick: () => {
						if (state.expandedStudents.has(key)) state.expandedStudents.delete(key)
						else state.expandedStudents.add(key)
						renderNav()
					},
				},
				el('span', { class: 'kl-chevron' + (openSt ? ' open' : '') }, icon('chevron')),
				el('span', { class: 'kl-label', title: info.name }, info.name, info.former ? el('span', { class: 'kl-muted' }, ' (nicht mehr Mitglied)') : null),
				el('span', { class: 'kl-count' }, String(info.secs.length)))))
				if (openSt) for (const s of info.secs) items.push(sectionItem(nb, s, 'kl-sec-deep'))
			}
		} else if (studentSecs.length) {
			items.push(areaHead('user', 'Mein Bereich', 'Nur du und deine Lehrkräfte sehen diese Abschnitte'))
			for (const s of studentSecs) items.push(sectionItem(nb, s))
		}
		return items
	}

	function sectionItem(nb, s, extraClass) {
		const menu = sectionMenu(nb, s)
		return el('li', {}, el('div', {
			class: 'kl-row kl-sec-row' + (extraClass ? ' ' + extraClass : '') + (s.id === state.sectionId ? ' active' : ''),
			dataset: { key: 'sec-' + s.id },
			onclick: () => selectSection(s.id),
		},
		el('span', { class: 'kl-dot', style: { background: s.color } }),
		el('span', { class: 'kl-label', title: s.name }, s.name),
		s.readOnly ? el('span', { class: 'kl-ro-icon', title: 'Nur lesen' }, icon('lock')) : null,
		menu.length ? iconButton('more', 'Aktionen für Abschnitt', (ev) => openMenu(ev.currentTarget, menu), 'kl-more') : null,
		))
	}

	function labelOf(key) {
		return navEl.querySelector('[data-key="' + key + '"] .kl-label')
	}

	function notebookMenu(nb) {
		const isClass = nb.kind === 'class'
		if (isClass && !nb.canManage) return []
		const items = [
			{ label: 'Umbenennen', icon: 'edit', action: () => startRename(labelOf('nb-' + nb.id), nb.name, (v) => renameNotebook(nb, v)) },
		]
		if (isClass) {
			items.push({ label: 'Mitglieder verwalten …', icon: 'group', action: () => membersDialog(nb) })
			items.push({ label: 'Abschnitt für alle Schüler …', icon: 'plus', action: () => addStudentSectionDialog(nb) })
		} else {
			items.push({ label: 'Abschnitt hinzufügen', icon: 'plus', action: () => createSection(nb) })
		}
		items.push({ colors: true, current: nb.color, onPick: (c) => recolorNotebook(nb, c) })
		if (!isClass || nb.isOwner) {
			items.push({ label: 'In den Papierkorb', icon: 'trash', danger: true, action: () => deleteNotebook(nb) })
		}
		return items
	}

	function sectionMenu(nb, s) {
		if (nb.kind === 'class' && !nb.canManage) return []
		return [
			{ label: 'Umbenennen', icon: 'edit', action: () => startRename(labelOf('sec-' + s.id), s.name, (v) => renameSection(s, v)) },
			{ colors: true, current: s.color, onPick: (c) => recolorSection(s, c) },
			{ label: 'In den Papierkorb', icon: 'trash', danger: true, action: () => deleteSection(nb, s) },
		]
	}

	function toggleNotebook(id) {
		if (state.expanded.has(id)) state.expanded.delete(id)
		else state.expanded.add(id)
		saveLocal()
		renderNav()
	}

	async function createNotebook() {
		try {
			const nb = await api('POST', '/notebooks', {
				name: 'Neues Notizbuch',
				color: COLORS[state.notebooks.length % COLORS.length],
			})
			state.notebooks.push(nb)
			state.expanded.add(nb.id)
			renderNav()
			if (nb.sections.length) await selectSection(nb.sections[0].id)
			startRename(labelOf('nb-' + nb.id), nb.name, (v) => renameNotebook(nb, v))
		} catch (e) {
			toast(e.message)
		}
	}

	async function renameNotebook(nb, name) {
		const r = await api('PUT', '/notebooks/' + nb.id, { name })
		nb.name = r.name
	}

	async function recolorNotebook(nb, color) {
		try {
			const r = await api('PUT', '/notebooks/' + nb.id, { color })
			nb.color = r.color
			renderNav()
		} catch (e) { toast(e.message) }
	}

	async function deleteNotebook(nb) {
		const ok = await confirmDialog('Notizbuch „' + nb.name + '“ mit allen Abschnitten und Seiten in den Papierkorb verschieben? Du kannst es dort wiederherstellen.', 'In den Papierkorb')
		if (!ok) return
		try {
			await flushSave()
			await api('DELETE', '/notebooks/' + nb.id)
			toast('Notizbuch „' + nb.name + '“ in den Papierkorb verschoben.', 'Rückgängig', () => restoreItem('notebook', nb.id))
			refreshTags()
			const hadCurrent = nb.sections.some((s) => s.id === state.sectionId)
			state.notebooks = state.notebooks.filter((x) => x.id !== nb.id)
			state.expanded.delete(nb.id)
			if (hadCurrent) await selectFirstAvailable()
			else renderNav()
			saveLocal()
		} catch (e) { toast(e.message) }
	}

	async function createSection(nb, area) {
		try {
			const s = await api('POST', '/notebooks/' + nb.id + '/sections', {
				name: 'Neuer Abschnitt',
				color: COLORS[(nb.sections.length + 1) % COLORS.length],
				area: area || null,
			})
			s.readOnly = false
			nb.sections.push(s)
			state.expanded.add(nb.id)
			await selectSection(s.id)
			startRename(labelOf('sec-' + s.id), s.name, (v) => renameSection(s, v))
		} catch (e) { toast(e.message) }
	}

	async function renameSection(s, name) {
		const r = await api('PUT', '/sections/' + s.id, { name })
		s.name = r.name
	}

	async function recolorSection(s, color) {
		try {
			const r = await api('PUT', '/sections/' + s.id, { color })
			s.color = r.color
			renderNav()
			renderPages()
		} catch (e) { toast(e.message) }
	}

	async function deleteSection(nb, s) {
		const ok = await confirmDialog('Abschnitt „' + s.name + '“ mit allen Seiten in den Papierkorb verschieben? Du kannst ihn dort wiederherstellen.', 'In den Papierkorb')
		if (!ok) return
		try {
			await flushSave()
			await api('DELETE', '/sections/' + s.id)
			toast('Abschnitt „' + s.name + '“ in den Papierkorb verschoben.', 'Rückgängig', () => restoreItem('section', s.id))
			refreshTags()
			nb.sections = nb.sections.filter((x) => x.id !== s.id)
			if (state.sectionId === s.id) await selectFirstAvailable()
			else renderNav()
			saveLocal()
		} catch (e) { toast(e.message) }
	}

	async function selectFirstAvailable() {
		for (const nb of state.notebooks) {
			if (nb.sections.length) {
				state.expanded.add(nb.id)
				await selectSection(nb.sections[0].id)
				return
			}
		}
		await flushSave()
		state.sectionId = null
		state.pages = []
		state.pageId = null
		state.page = null
		renderNav()
		renderPages()
		renderContent()
		setView('nav')
	}

	// ------------------------------------------------------------------ Seitenliste

	async function selectSection(id, preferredPageId) {
		const seq = ++selectSeq
		setNavOpen(false)
		await flushSave()
		state.sectionId = id
		state.pageId = null
		state.page = null
		const found = findSection(id)
		if (found) state.expanded.add(found.nb.id)
		renderNav()
		pagesEl.replaceChildren(el('div', { class: 'kl-loading' }, 'Lädt …'))
		renderContent()
		setView('pages')
		try {
			const pages = await api('GET', '/sections/' + id + '/pages')
			if (seq !== selectSeq) return
			state.pages = pages
		} catch (e) {
			if (seq !== selectSeq) return
			state.pages = []
			toast(e.message)
		}
		renderPages()
		saveLocal()
		const target = state.pages.find((p) => p.id === preferredPageId) || state.pages[0]
		if (target) await selectPage(target.id, true)
	}

	function renderPages() {
		const found = findSection(state.sectionId)
		if (!found) {
			pagesEl.style.removeProperty('--kl-section-color')
			pagesEl.replaceChildren(
				el('div', { class: 'kl-col-header' }, backButton('nav'), navToggleButton(), el('h2', {}, 'Seiten')),
				el('div', { class: 'kl-empty' }, 'Wähle links einen Abschnitt.'),
			)
			return
		}
		const s = found.s
		const nb = found.nb
		const ro = !!s.readOnly
		const canDistribute = nb.kind === 'class' && nb.canManage && (nb.students || []).length > 0
		pagesEl.style.setProperty('--kl-section-color', s.color)
		const pageMenu = (p) => {
			const items = []
			if (canDistribute) items.push({ label: 'An alle Schüler verteilen …', icon: 'share', action: () => distributeDialog(nb, p) })
			if (!ro) items.push({ label: 'In den Papierkorb', icon: 'trash', danger: true, action: () => deletePage(p) })
			return items
		}

		const list = state.pages.length
			? el('ul', { class: 'kl-pagelist' }, state.pages.map((p) => el('li', {
				class: 'kl-page-item' + (p.id === state.pageId ? ' active' : ''),
				dataset: { pageId: String(p.id) },
				tabindex: '0',
				onclick: () => selectPage(p.id),
				onkeydown: (e) => { if (e.key === 'Enter') selectPage(p.id) },
			},
			el('div', { class: 'kl-page-title' }, p.title || 'Unbenannte Seite'),
			el('div', { class: 'kl-page-date' }, fmtDate(p.updatedAt)),
			pageMenu(p).length ? iconButton('more', 'Aktionen für Seite', (ev) => openMenu(ev.currentTarget, pageMenu(p)), 'kl-more') : null,
			)))
			: el('div', { class: 'kl-empty' },
				el('p', {}, 'Dieser Abschnitt ist leer.'),
				ro ? null : el('button', { class: 'kl-btn kl-btn-primary', type: 'button', onclick: createPage }, 'Seite anlegen'))

		pagesEl.replaceChildren(
			el('div', { class: 'kl-col-header kl-pages-header' },
				backButton('nav'),
				navToggleButton(),
				el('div', { class: 'kl-pages-titles' },
					el('div', { class: 'kl-crumb', title: nb.name }, nb.name + (s.area === 'student' && s.studentName && nb.canManage ? ' › ' + s.studentName : '')),
					el('h2', { title: s.name }, s.name, ro ? el('span', { class: 'kl-ro-badge' }, icon('lock'), 'Nur lesen') : null)),
				ro ? null : iconButton('plus', 'Neue Seite', createPage)),
			el('div', { class: 'kl-scroll' }, list),
		)
	}

	async function createPage() {
		if (!state.sectionId) return
		try {
			await flushSave()
			const p = await api('POST', '/sections/' + state.sectionId + '/pages', { title: '' })
			state.pages.push({ id: p.id, sectionId: p.sectionId, title: p.title, sortOrder: p.sortOrder, createdAt: p.createdAt, updatedAt: p.updatedAt })
			state.pageId = p.id
			p.tags = []
			p.readOnly = false
			state.page = p
			renderPages()
			renderContent()
			setView('page')
			saveLocal()
			const t = contentEl.querySelector('.kl-title')
			if (t) t.focus()
		} catch (e) { toast(e.message) }
	}

	async function deletePage(p) {
		try {
			if (state.page && state.page.id === p.id) {
				await flushSave() // letzte Änderungen sichern, damit sie im Papierkorb enthalten sind
				destroyEditor()
			}
			await api('DELETE', '/pages/' + p.id)
			toast('Seite „' + (p.title || 'Unbenannte Seite') + '“ in den Papierkorb verschoben.', 'Rückgängig', () => restoreItem('page', p.id))
			refreshTags()
			const idx = state.pages.findIndex((x) => x.id === p.id)
			state.pages = state.pages.filter((x) => x.id !== p.id)
			if (state.pageId === p.id) {
				state.pageId = null
				state.page = null
				const next = state.pages[Math.min(idx, state.pages.length - 1)]
				renderPages()
				if (next) await selectPage(next.id, true)
				else renderContent()
			} else {
				renderPages()
			}
			saveLocal()
		} catch (e) { toast(e.message) }
	}

	// ------------------------------------------------------------------ Kursnotizbücher

	/** Allgemeiner Dialog. actions: [{label, primary, danger, onClick}] – onClick false = offen lassen */
	function openDialog(opts) {
		const close = () => {
			overlay.remove()
			document.removeEventListener('keydown', onKey, true)
		}
		const onKey = (e) => {
			if (e.key === 'Escape' && overlay === [...document.querySelectorAll('.kl-overlay')].pop()) close()
		}
		const actions = (opts.actions || []).map((a) => {
			const b = el('button', {
				class: 'kl-btn' + (a.primary ? ' kl-btn-primary' : '') + (a.danger ? ' kl-btn-danger' : ''),
				type: 'button',
			}, a.label)
			b.addEventListener('click', async () => {
				if (!a.onClick) { close(); return }
				b.disabled = true
				const keep = await a.onClick()
				b.disabled = false
				if (keep !== false) close()
			})
			return b
		})
		const overlay = el('div', { class: 'kl-overlay', onclick: (e) => { if (e.target === overlay && !opts.modal) close() } },
			el('div', { class: 'kl-dialog kl-form' + (opts.wide ? ' kl-wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title },
				el('div', { class: 'kl-trash-head' },
					el('h2', {}, opts.title),
					el('button', { class: 'kl-icon-btn', type: 'button', 'aria-label': 'Schließen', onclick: close }, icon('close'))),
				el('div', { class: 'kl-form-body' }, opts.body),
				el('div', { class: 'kl-dialog-actions' }, actions)))
		document.body.append(overlay)
		document.addEventListener('keydown', onKey, true)
		const first = overlay.querySelector('input, select, textarea')
		if (first) first.focus()
		return close
	}

	function field(label, control, help) {
		return el('label', { class: 'kl-field-wrap' },
			el('span', { class: 'kl-field-label' }, label),
			control,
			help ? el('span', { class: 'kl-help' }, help) : null)
	}

	/** Auswahl von Benutzern/Gruppen mit Rolle Schüler/Lehrkraft */
	function memberPicker(initial) {
		const selected = initial.map((m) => ({ ...m }))
		const list = el('div', { class: 'kl-mp-list' })
		const results = el('div', { class: 'kl-mp-results' })
		const input = el('input', { class: 'kl-field', type: 'search', placeholder: 'Name, Benutzer oder Gruppe suchen …', autocomplete: 'off' })

		const renderList = () => {
			list.replaceChildren(...(selected.length
				? selected.map((m, i) => {
					const select = el('select', { class: 'kl-mp-role', 'aria-label': 'Rolle' },
						el('option', { value: 'student' }, 'Schüler'),
						el('option', { value: 'teacher' }, 'Lehrkraft'))
					select.value = m.role
					select.addEventListener('change', () => { m.role = select.value })
					return el('div', { class: 'kl-mp-item' },
						icon(m.type === 'group' ? 'group' : 'user'),
						el('span', { class: 'kl-mp-name' }, m.name || m.principal,
							el('span', { class: 'kl-muted' }, m.type === 'group' ? ' · Gruppe' : ' · ' + m.principal)),
						select,
						el('button', { class: 'kl-icon-btn', type: 'button', 'aria-label': 'Entfernen', onclick: () => { selected.splice(i, 1); renderList() } }, icon('close')))
				})
				: [el('div', { class: 'kl-mp-empty' }, 'Noch niemand hinzugefügt.')]))
		}

		let timer = null
		let seq = 0
		input.addEventListener('input', () => {
			clearTimeout(timer)
			const q = input.value.trim()
			if (q.length < 2) { results.replaceChildren(); return }
			timer = setTimeout(async () => {
				const my = ++seq
				let found = []
				try { found = await api('GET', '/principals?q=' + encodeURIComponent(q)) } catch (e) { toast(e.message) }
				if (my !== seq) return
				results.replaceChildren(...(found.length
					? found.map((f) => el('button', {
						class: 'kl-mp-result',
						type: 'button',
						onclick: () => {
							if (!selected.some((m) => m.type === f.type && m.principal === f.id)) {
								selected.push({ type: f.type, principal: f.id, name: f.name, role: 'student' })
								renderList()
							}
							input.value = ''
							results.replaceChildren()
							input.focus()
						},
					}, icon(f.type === 'group' ? 'group' : 'user'), f.name, el('span', { class: 'kl-muted' }, f.type === 'group' ? ' · Gruppe' : ' · ' + f.id)))
					: [el('div', { class: 'kl-mp-empty' }, 'Nichts gefunden.')]))
			}, 250)
		})
		input.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault() })
		renderList()
		return {
			el: el('div', { class: 'kl-mp' }, input, results, list),
			get: () => selected.map(({ type, principal, role }) => ({ type, principal, role })),
		}
	}

	function createClassNotebook() {
		const name = el('input', { class: 'kl-field', type: 'text', placeholder: 'z. B. Mathe 9b', maxlength: '200' })
		const picker = memberPicker([])
		const secs = el('input', { class: 'kl-field', type: 'text', value: 'Notizen, Hausaufgaben' })
		openDialog({
			title: 'Neues Kursnotizbuch',
			wide: true,
			modal: true,
			body: [
				field('Name', name),
				field('Mitglieder', picker.el, 'Füge die Klassengruppe oder einzelne Schüler hinzu, außerdem weitere Lehrkräfte. Du selbst bist automatisch Lehrkraft.'),
				field('Abschnitte für jeden Schüler', secs, 'Durch Komma getrennt. Jeder Schüler bekommt diese Abschnitte in seinem eigenen, privaten Bereich.'),
				el('div', { class: 'kl-help kl-structure' },
					el('strong', {}, 'Aufbau: '),
					'Inhaltsbibliothek (nur Lehrkräfte schreiben) · Gemeinsamer Bereich (alle schreiben) · Nur Lehrkräfte (für Schüler unsichtbar) · ein eigener Bereich pro Schüler'),
			],
			actions: [
				{ label: 'Abbrechen' },
				{
					label: 'Anlegen',
					primary: true,
					onClick: async () => {
						if (!name.value.trim()) { toast('Bitte einen Namen eingeben.'); name.focus(); return false }
						try {
							const nb = await api('POST', '/classes', {
								name: name.value,
								color: COLORS[state.notebooks.length % COLORS.length],
								members: picker.get(),
								studentSections: secs.value.split(',').map((x) => x.trim()).filter(Boolean),
							})
							state.notebooks.push(nb)
							state.expanded.add(nb.id)
							renderNav()
							const first = nb.sections.find((x) => x.area === 'library') || nb.sections[0]
							if (first) await selectSection(first.id)
							toast('Kursnotizbuch „' + nb.name + '“ angelegt.')
						} catch (e) {
							toast(e.message)
							return false
						}
					},
				},
			],
		})
	}

	async function membersDialog(nb) {
		let data
		try { data = await api('GET', '/notebooks/' + nb.id + '/members') } catch (e) { toast(e.message); return }
		const picker = memberPicker(data.members.map((m) => ({ type: m.type, principal: m.principal, role: m.role, name: m.name })))
		openDialog({
			title: 'Mitglieder – ' + nb.name,
			wide: true,
			body: [
				el('p', { class: 'kl-help' }, 'Besitzer: ' + data.owner.name + ' · aktuell ' + data.studentCount + ' Schüler. '
					+ 'Neue Schüler bekommen automatisch ihre Abschnitte. Entfernte Schüler verlieren den Zugriff, ihre Seiten bleiben für Lehrkräfte erhalten.'),
				picker.el,
			],
			actions: [
				{ label: 'Abbrechen' },
				{
					label: 'Speichern',
					primary: true,
					onClick: async () => {
						try {
							await api('PUT', '/notebooks/' + nb.id + '/members', { members: picker.get() })
							await reloadTree()
							toast('Mitglieder gespeichert.')
						} catch (e) { toast(e.message); return false }
					},
				},
			],
		})
	}

	function addStudentSectionDialog(nb) {
		const name = el('input', { class: 'kl-field', type: 'text', placeholder: 'z. B. Tests', maxlength: '60' })
		openDialog({
			title: 'Abschnitt für alle Schüler',
			body: [field('Name des Abschnitts', name, 'Jeder Schüler bekommt diesen Abschnitt in seinem Bereich. Auch später hinzukommende Schüler.')],
			actions: [
				{ label: 'Abbrechen' },
				{
					label: 'Anlegen',
					primary: true,
					onClick: async () => {
						if (!name.value.trim()) { name.focus(); return false }
						try {
							const r = await api('POST', '/notebooks/' + nb.id + '/student-sections', { name: name.value })
							await reloadTree()
							toast('Abschnitt bei ' + r.created + ' Schülern angelegt.')
						} catch (e) { toast(e.message); return false }
					},
				},
			],
		})
	}

	function distributeDialog(nb, p) {
		const names = nb.studentSections && nb.studentSections.length ? nb.studentSections : ['Hausaufgaben']
		const group = 'kl-dist-' + p.id
		const radios = names.map((n, i) => {
			const r = el('input', { type: 'radio', name: group, value: n })
			if (i === Math.min(1, names.length - 1)) r.checked = true
			return el('label', { class: 'kl-radio' }, r, n)
		})
		const otherRadio = el('input', { type: 'radio', name: group, value: '__other' })
		const other = el('input', { class: 'kl-field', type: 'text', placeholder: 'anderer Abschnitt …', maxlength: '60' })
		other.addEventListener('focus', () => { otherRadio.checked = true })
		const count = (nb.students || []).length
		openDialog({
			title: 'Seite verteilen',
			body: [
				el('p', { class: 'kl-help' }, '„' + (p.title || 'Unbenannte Seite') + '“ wird als eigene Kopie an alle ' + count + ' Schüler verteilt. '
					+ 'Jeder Schüler kann seine Kopie bearbeiten, das Original bleibt unverändert.'),
				el('div', { class: 'kl-field-label' }, 'In welchen Abschnitt der Schüler?'),
				el('div', { class: 'kl-radios' }, radios, el('label', { class: 'kl-radio' }, otherRadio, other)),
			],
			actions: [
				{ label: 'Abbrechen' },
				{
					label: 'An ' + count + ' Schüler verteilen',
					primary: true,
					onClick: async () => {
						const checked = document.querySelector('input[name="' + group + '"]:checked')
						const target = !checked ? '' : checked.value === '__other' ? other.value.trim() : checked.value
						if (!target) { toast('Bitte einen Abschnitt wählen.'); return false }
						try {
							await flushSave()
							const r = await api('POST', '/pages/' + p.id + '/distribute', { sectionName: target })
							await reloadTree()
							toast('An ' + r.distributed + ' Schüler verteilt (Abschnitt „' + r.sectionName + '“).')
						} catch (e) { toast(e.message); return false }
					},
				},
			],
		})
	}

	// ------------------------------------------------------------------ Export / Import

	function count(n, one, many) {
		return n + ' ' + (n === 1 ? one : many)
	}

	function fmtSize(bytes) {
		if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB'
		return Math.max(1, Math.round(bytes / 1024)) + ' KB'
	}

	async function exportImportDialog() {
		setNavOpen(false)
		let opts
		try { opts = await api('GET', '/export/options') } catch (e) { toast(e.message); return }

		const checks = []
		const checkbox = (kind, item, label, sub) => {
			const cb = el('input', { type: 'checkbox' })
			cb.checked = true
			checks.push({ kind, id: item.id, cb })
			return el('label', { class: 'kl-check-row' }, cb,
				el('span', { class: 'kl-dot', style: { background: item.color || '#999' } }),
				el('span', { class: 'kl-mp-name' }, label, sub ? el('span', { class: 'kl-muted' }, ' · ' + sub) : null))
		}
		const personal = opts.notebooks.filter((n) => n.kind === 'personal')
		const classes = opts.notebooks.filter((n) => n.kind === 'class')
		const groups = []
		if (personal.length) groups.push(el('div', { class: 'kl-field-label' }, 'Meine Notizbücher'), personal.map((n) => checkbox('nb', n, n.name)))
		if (classes.length) {
			groups.push(el('div', { class: 'kl-field-label' }, 'Kursnotizbücher (komplett, mit allen Schülerbereichen)'),
				classes.map((n) => checkbox('nb', n, n.name, n.role === 'owner' ? 'Besitzer' : 'Mitlehrkraft')))
		}
		if (opts.studentAreas.length) {
			groups.push(el('div', { class: 'kl-field-label' }, 'Mein Bereich in Kursnotizbüchern'),
				opts.studentAreas.map((n) => checkbox('area', n, n.name)))
		}
		const selection = () => ({
			notebooks: checks.filter((c) => c.kind === 'nb' && c.cb.checked).map((c) => c.id),
			studentAreas: checks.filter((c) => c.kind === 'area' && c.cb.checked).map((c) => c.id),
		})
		const status = el('div', { class: 'kl-help kl-ei-status', role: 'status' })
		const busy = (text) => { status.textContent = text }

		const doDownload = async () => {
			const sel = selection()
			if (!sel.notebooks.length && !sel.studentAreas.length) { toast('Bitte mindestens ein Notizbuch auswählen.'); return }
			busy('Export wird erstellt …')
			try {
				await flushSave()
				const res = await fetch(apiUrl('/export'), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
					body: JSON.stringify({ ...sel, target: 'download' }),
				})
				if (!res.ok) {
					let msg = 'Export fehlgeschlagen.'
					try { msg = (await res.json()).message || msg } catch (e) { /* egal */ }
					throw new Error(msg)
				}
				const blob = await res.blob()
				const m = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') || '')
				const a = el('a', { href: URL.createObjectURL(blob), download: m ? m[1] : 'Kladory-Export.kladory' })
				document.body.append(a)
				a.click()
				setTimeout(() => { URL.revokeObjectURL(a.href); a.remove() }, 2000)
				busy('Export heruntergeladen (' + fmtSize(blob.size) + ').')
			} catch (e) { busy(''); toast(e.message) }
		}

		const doSaveFiles = async () => {
			const sel = selection()
			if (!sel.notebooks.length && !sel.studentAreas.length) { toast('Bitte mindestens ein Notizbuch auswählen.'); return }
			busy('Export wird in deinen Nextcloud-Dateien gespeichert …')
			try {
				await flushSave()
				const r = await api('POST', '/export', { ...sel, target: 'files' })
				busy('Gespeichert in deinen Dateien: ' + r.path + ' (' + count(r.notebooks, 'Notizbuch', 'Notizbücher') + ', ' + count(r.pages, 'Seite', 'Seiten') + ').')
				loadFiles()
			} catch (e) { busy(''); toast(e.message) }
		}

		const showResult = async (r) => {
			await reloadTree()
			busy('Importiert: ' + count(r.notebooks, 'Notizbuch', 'Notizbücher') + ', ' + count(r.pages, 'Seite', 'Seiten') + ', ' + count(r.images, 'Bild', 'Bilder') + '.')
			if (r.warnings && r.warnings.length) {
				status.append(el('ul', { class: 'kl-ei-warnings' }, r.warnings.map((w) => el('li', {}, w))))
			}
			toast('Import abgeschlossen.')
		}

		const fileInput = el('input', { type: 'file', accept: '.kladory,.zip,application/zip', style: { display: 'none' } })
		fileInput.addEventListener('change', async () => {
			const f = fileInput.files && fileInput.files[0]
			fileInput.value = ''
			if (!f) return
			busy('„' + f.name + '“ wird importiert …')
			try {
				const form = new FormData()
				form.append('file', f, f.name)
				const res = await fetch(apiUrl('/import'), {
					method: 'POST', credentials: 'same-origin', headers: { requesttoken: requestToken(), Accept: 'application/json' }, body: form,
				})
				const data = await res.json().catch(() => ({}))
				if (!res.ok) throw new Error(data.message || 'Import fehlgeschlagen.')
				await showResult(data)
			} catch (e) { busy(''); toast(e.message) }
		})

		const fileList = el('div', { class: 'kl-ei-files' }, el('div', { class: 'kl-loading' }, 'Lädt …'))
		const loadFiles = async () => {
			let files = []
			try { files = await api('GET', '/import/files') } catch (e) { /* egal */ }
			fileList.replaceChildren(...(files.length
				? files.map((f) => el('div', { class: 'kl-mp-item' },
					icon('download'),
					el('span', { class: 'kl-mp-name' }, f.name, el('span', { class: 'kl-muted' }, ' · ' + fmtSize(f.size) + ' · ' + fmtDate(f.mtime))),
					el('button', {
						class: 'kl-btn kl-btn-small',
						type: 'button',
						onclick: async () => {
							busy('„' + f.name + '“ wird importiert …')
							try { await showResult(await api('POST', '/import', { fileId: f.id })) } catch (e) { busy(''); toast(e.message) }
						},
					}, 'Importieren')))
				: [el('div', { class: 'kl-mp-empty' }, 'Keine Exportdateien im Ordner „Kladory-Export“ deiner Nextcloud-Dateien.')]))
		}
		loadFiles()

		openDialog({
			title: 'Export / Import',
			wide: true,
			body: [
				el('h3', { class: 'kl-ei-head' }, 'Sichern (Export)'),
				el('p', { class: 'kl-help' }, 'Erstellt eine .kladory-Datei mit Seiten, Zeichnungen, Bildern und Tags. Inhalte im Papierkorb werden nicht exportiert.'),
				groups.length ? el('div', { class: 'kl-ei-list' }, groups) : el('div', { class: 'kl-mp-empty' }, 'Keine Notizbücher zum Exportieren.'),
				el('div', { class: 'kl-ei-actions' },
					el('button', { class: 'kl-btn kl-btn-primary', type: 'button', onclick: doDownload }, 'Herunterladen'),
					el('button', { class: 'kl-btn', type: 'button', onclick: doSaveFiles }, 'In Nextcloud-Dateien speichern')),
				el('h3', { class: 'kl-ei-head' }, 'Wiederherstellen (Import)'),
				el('p', { class: 'kl-help' }, 'Beim Import wird alles neu angelegt, vorhandene Notizbücher werden nicht überschrieben. Kursnotizbücher bekommen ihre Mitglieder zurück, soweit es die Benutzer und Gruppen noch gibt.'),
				el('div', { class: 'kl-ei-actions' },
					el('button', { class: 'kl-btn', type: 'button', onclick: () => fileInput.click() }, 'Datei vom Gerät wählen …'),
					fileInput),
				el('div', { class: 'kl-field-label' }, 'Aus deinen Nextcloud-Dateien (Ordner „Kladory-Export“)'),
				fileList,
				status,
			],
			actions: [{ label: 'Schließen' }],
		})
	}

	// ------------------------------------------------------------------ Papierkorb

	const TYPE_LABELS = { notebook: 'Notizbuch', section: 'Abschnitt', page: 'Seite' }

	async function reloadTree() {
		const data = await api('GET', '/tree')
		state.notebooks = data.notebooks || []
		refreshTags()
		if (state.sectionId && findSection(state.sectionId)) {
			renderNav()
			const pages = await api('GET', '/sections/' + state.sectionId + '/pages')
			state.pages = pages
			renderPages()
		} else {
			await selectFirstAvailable()
		}
	}

	async function restoreItem(type, id) {
		try {
			await api('POST', '/trash/' + type + '/' + id + '/restore')
			await reloadTree()
			toast(TYPE_LABELS[type] + ' wiederhergestellt.')
			return true
		} catch (e) {
			toast(e.message)
			return false
		}
	}

	async function openTrash() {
		setNavOpen(false)
		const list = el('div', { class: 'kl-trash-list' }, el('div', { class: 'kl-loading' }, 'Lädt …'))
		const info = el('p', { class: 'kl-trash-info' })
		const emptyBtn = el('button', { class: 'kl-btn kl-btn-danger-soft', type: 'button' }, 'Papierkorb leeren')
		const close = () => {
			overlay.remove()
			document.removeEventListener('keydown', onKey, true)
		}
		const onKey = (e) => {
			if (e.key === 'Escape' && !document.querySelector('.kl-overlay .kl-dialog:not(.kl-trash)')) close()
		}
		const overlay = el('div', { class: 'kl-overlay', onclick: (e) => { if (e.target === overlay) close() } },
			el('div', { class: 'kl-dialog kl-trash', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Papierkorb' },
				el('div', { class: 'kl-trash-head' },
					el('h2', {}, 'Papierkorb'),
					el('button', { class: 'kl-icon-btn kl-trash-close', type: 'button', 'aria-label': 'Schließen', onclick: close }, '✕')),
				info,
				list,
				el('div', { class: 'kl-dialog-actions' },
					emptyBtn,
					el('button', { class: 'kl-btn', type: 'button', onclick: close }, 'Schließen'))))
		document.body.append(overlay)
		document.addEventListener('keydown', onKey, true)

		const load = async () => {
			let data
			try {
				data = await api('GET', '/trash')
			} catch (e) {
				list.replaceChildren(el('div', { class: 'kl-empty' }, e.message))
				return
			}
			info.textContent = data.retentionDays > 0
				? 'Gelöschte Einträge werden nach ' + data.retentionDays + ' Tagen automatisch endgültig gelöscht.'
				: 'Gelöschte Einträge bleiben hier, bis du sie endgültig löschst.'
			emptyBtn.hidden = data.items.length === 0
			if (!data.items.length) {
				list.replaceChildren(el('div', { class: 'kl-empty kl-trash-empty' }, icon('trashcan'), el('p', {}, 'Der Papierkorb ist leer.')))
				return
			}
			list.replaceChildren(...data.items.map((item) => el('div', { class: 'kl-trash-item' },
				el('span', { class: 'kl-dot', style: { background: item.color || '#999' } }),
				el('div', { class: 'kl-trash-main' },
					el('div', { class: 'kl-trash-name' }, item.name || 'Unbenannte Seite'),
					el('div', { class: 'kl-trash-meta' },
						TYPE_LABELS[item.type],
						item.path ? ' · ' + item.path : '',
						' · gelöscht ' + fmtDate(item.deletedAt),
						item.purgeAt ? ' · endgültig weg am ' + new Date(item.purgeAt * 1000).toLocaleDateString('de-DE') : '')),
				el('div', { class: 'kl-trash-actions' },
					el('button', {
						class: 'kl-btn kl-btn-small',
						type: 'button',
						onclick: async () => { if (await restoreItem(item.type, item.id)) load() },
					}, icon('restore'), 'Wiederherstellen'),
					el('button', {
						class: 'kl-icon-btn kl-trash-del',
						type: 'button',
						title: 'Endgültig löschen',
						'aria-label': 'Endgültig löschen',
						onclick: async () => {
							const ok = await confirmDialog(TYPE_LABELS[item.type] + ' „' + (item.name || 'Unbenannte Seite') + '“ endgültig löschen? Das kann nicht rückgängig gemacht werden.', 'Endgültig löschen')
							if (!ok) return
							try {
								await api('DELETE', '/trash/' + item.type + '/' + item.id)
								load()
							} catch (e) { toast(e.message) }
						},
					}, icon('trash'))))))
		}

		emptyBtn.addEventListener('click', async () => {
			const ok = await confirmDialog('Alle Einträge im Papierkorb endgültig löschen? Das kann nicht rückgängig gemacht werden.', 'Papierkorb leeren')
			if (!ok) return
			try {
				await api('DELETE', '/trash')
				load()
			} catch (e) { toast(e.message) }
		})

		load()
	}

	// ------------------------------------------------------------------ Seiteninhalt

	async function selectPage(id, keepView) {
		if (state.page && state.page.id === id) {
			if (!keepView) setView('page')
			return
		}
		const seq = ++selectSeq
		await flushSave()
		state.pageId = id
		state.page = null
		renderPages()
		contentEl.replaceChildren(el('div', { class: 'kl-loading' }, 'Lädt …'))
		if (!keepView) setView('page')
		try {
			const page = await api('GET', '/pages/' + id)
			page._savedTitle = page.title || ''
			page._savedContent = page.content
			if (seq !== selectSeq) return
			state.page = page
		} catch (e) {
			if (seq !== selectSeq) return
			toast(e.message)
		}
		renderContent()
		saveLocal()
	}

	let editor = null
	let editorPageId = null

	function destroyEditor() {
		if (editor) editor.destroy()
		editor = null
		editorPageId = null
	}

	function renderContent() {
		destroyEditor()
		const p = state.page
		applyFocus(!!p)
		if (!p) {
			contentEl.replaceChildren(el('div', { class: 'kl-empty kl-empty-big' },
				icon('page'),
				el('p', {}, state.sectionId
					? (state.pages.length ? 'Wähle eine Seite aus.' : 'Lege eine Seite an, um zu schreiben.')
					: 'Lege links ein Notizbuch an, um loszulegen.')))
			return
		}
		const title = el('input', {
			class: 'kl-title',
			type: 'text',
			value: p.title || '',
			placeholder: 'Seitentitel',
			maxlength: '255',
			'aria-label': 'Seitentitel',
			readonly: p.readOnly ? true : null,
		})
		title.addEventListener('input', () => {
			p.title = title.value
			const summary = state.pages.find((x) => x.id === p.id)
			if (summary) summary.title = title.value
			const li = pagesEl.querySelector('.kl-page-item[data-page-id="' + p.id + '"] .kl-page-title')
			if (li) li.textContent = title.value || 'Unbenannte Seite'
			markDirty()
		})
		title.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); flushSave() } })

		const toolbar = el('div', { class: 'kl-toolbar' })
		const canvas = el('div', { class: 'kl-canvas' })

		contentEl.replaceChildren(
			el('div', { class: 'kl-page-head' },
				backButton('pages'),
				focusToggleButton(),
				el('div', { class: 'kl-page-head-main' },
					title,
					el('div', { class: 'kl-meta' },
						el('span', {}, 'Zuletzt geändert: ', el('span', { class: 'kl-meta-date' }, fmtDate(p.updatedAt))),
						el('span', { class: 'kl-save-state' })),
					renderTagRow(p))),
			toolbar,
			canvas,
		)
		if (p.readOnly) {
			const ss = contentEl.querySelector('.kl-save-state')
			if (ss) ss.replaceChildren(el('span', { class: 'kl-save-text' }, 'Nur lesen'))
		} else {
			setSaveState(p._dirty ? 'dirty' : 'saved')
		}

		if (window.KladoryEditor) {
			editor = window.KladoryEditor.mount({
				canvasEl: canvas,
				toolbarEl: toolbar,
				content: p.content,
				readOnly: !!p.readOnly,
				onChange: markDirty,
				uploadImage: (blob) => uploadImage(p.id, blob),
				imageUrl: (src) => apiUrl('/pages/' + p.id + '/images/' + src),
				onError: (msg) => toast(msg),
			})
			editorPageId = p.id
		} else {
			canvas.append(el('div', { class: 'kl-empty' }, 'Der Editor konnte nicht geladen werden.'))
		}
	}

	async function uploadImage(pageId, blob) {
		const form = new FormData()
		const ext = blob.type === 'image/png' ? 'png' : blob.type === 'image/gif' ? 'gif' : blob.type === 'image/webp' ? 'webp' : 'jpg'
		form.append('image', blob, 'bild.' + ext)
		const res = await fetch(apiUrl('/pages/' + pageId + '/images'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { requesttoken: requestToken(), Accept: 'application/json' },
			body: form,
		})
		let data = null
		try { data = await res.json() } catch (e) { /* leer */ }
		if (!res.ok) throw new Error((data && data.message) || ('Upload fehlgeschlagen (' + res.status + ')'))
		return data.src
	}

	function normalizeTag(t) {
		return String(t || '').trim().toLowerCase().replace(/^#+/, '').replace(/\s+/g, '-').replace(/[^\p{L}\p{N}_-]/gu, '').slice(0, 40)
	}

	function renderTagRow(p) {
		if (!Array.isArray(p.tags)) p.tags = []
		const row = el('div', { class: 'kl-tags' })
		if (p.readOnly) {
			for (const t of p.tags) row.append(el('span', { class: 'kl-chip kl-chip-static' }, '#' + t))
			return row
		}
		const save = async (tags) => {
			try {
				const r = await api('PUT', '/pages/' + p.id + '/tags', { tags })
				p.tags = r.tags
				row.replaceWith(renderTagRow(p))
				refreshTags()
			} catch (e) { toast(e.message) }
		}
		for (const t of p.tags) {
			row.append(el('span', { class: 'kl-chip kl-chip-static' }, '#' + t,
				el('button', {
					class: 'kl-chip-x',
					type: 'button',
					title: 'Tag entfernen',
					'aria-label': 'Tag ' + t + ' entfernen',
					onclick: () => save(p.tags.filter((x) => x !== t)),
				}, '✕')))
		}
		const addBtn = el('button', { class: 'kl-tag-add', type: 'button' }, icon('tag'), p.tags.length ? 'Tag' : 'Tag hinzufügen')
		addBtn.addEventListener('click', () => {
			const listId = 'kl-taglist'
			let dl = document.getElementById(listId)
			if (!dl) { dl = el('datalist', { id: listId }); document.body.append(dl) }
			dl.replaceChildren(...state.tags.filter((t) => !p.tags.includes(t.tag)).map((t) => el('option', { value: t.tag })))
			const input = el('input', { class: 'kl-tag-input', type: 'text', list: listId, placeholder: 'Tag …', maxlength: '40', 'aria-label': 'Neuer Tag' })
			addBtn.replaceWith(input)
			input.focus()
			let done = false
			const finish = (commit) => {
				if (done) return
				done = true
				const tag = normalizeTag(input.value)
				if (commit && tag && !p.tags.includes(tag)) save([...p.tags, tag])
				else row.replaceWith(renderTagRow(p))
			}
			input.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); finish(true) }
				if (e.key === 'Escape') { e.preventDefault(); finish(false) }
			})
			input.addEventListener('blur', () => finish(true))
		})
		row.append(addBtn)
		return row
	}

	let saveTimer = null
	let saving = null

	// ---- Speicher-Anzeige: Punkt + Text, z. B. „Gespeichert · vor 2 Min.“
	let saveFailures = 0
	let retryTimer = null

	function relTime(t) {
		const sec = (Date.now() - t) / 1000
		if (sec < 45) return 'gerade eben'
		if (sec < 90) return 'vor 1 Min.'
		if (sec < 3600) return 'vor ' + Math.round(sec / 60) + ' Min.'
		const d = new Date(t)
		const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
		return d.toDateString() === new Date().toDateString() ? 'um ' + time + ' Uhr' : 'am ' + fmtDate(Math.floor(t / 1000))
	}

	/** kind: saved | dirty | saving | error | offline */
	function setSaveState(kind) {
		const s = contentEl.querySelector('.kl-save-state')
		if (!s) return
		const p = state.page
		const last = p && p._lastSavedAt
		let text
		let tip = last ? 'Zuletzt gespeichert: ' + new Date(last).toLocaleString('de-DE') : ''
		switch (kind) {
		case 'dirty': text = 'Änderungen werden gleich gespeichert'; break
		case 'saving': text = 'Speichert …'; break
		case 'offline': text = 'Keine Verbindung – wird erneut versucht'; tip = 'Deine Änderungen bleiben erhalten, solange die Seite geöffnet ist. ' + tip; break
		case 'error': text = 'Speichern fehlgeschlagen – wird erneut versucht'; break
		default: text = last ? 'Gespeichert · ' + relTime(last) : 'Alles gespeichert'
		}
		s.dataset.state = kind
		s.title = tip
		s.replaceChildren(el('span', { class: 'kl-save-dot', 'aria-hidden': 'true' }), el('span', { class: 'kl-save-text' }, text))
	}

	// relative Zeit aktuell halten
	setInterval(() => {
		const s = contentEl && contentEl.querySelector('.kl-save-state[data-state="saved"]')
		if (s && document.visibilityState === 'visible') setSaveState('saved')
	}, 15000)

	// Gespeichert wird 2 s nach der letzten Änderung, bei Dauerschreiben spätestens alle 15 s.
	const SAVE_DELAY = 2000
	const SAVE_MAX_WAIT = 15000
	let dirtySince = 0

	function markDirty() {
		if (!state.page) return
		if (!state.page._dirty || !dirtySince) dirtySince = Date.now()
		state.page._dirty = true
		setSaveState(saveFailures ? (navigator.onLine === false ? 'offline' : 'error') : 'dirty')
		clearTimeout(saveTimer)
		const wait = Math.max(0, Math.min(SAVE_DELAY, dirtySince + SAVE_MAX_WAIT - Date.now()))
		saveTimer = setTimeout(flushSave, wait)
	}

	// ---- Übertragung: gzip-komprimiert (spart bei Handschrift ~90 %), sonst klassisch per JSON
	let gzipSave = typeof CompressionStream === 'function'

	async function gzip(text) {
		const stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'))
		return new Uint8Array(await new Response(stream).arrayBuffer())
	}

	async function sendSave(id, body, keepalive) {
		const json = JSON.stringify(body)
		if (gzipSave && json.length > 2048) {
			let res = null
			let data = null
			try {
				const packed = await gzip(json)
				res = await fetch(apiUrl('/pages/' + id + '/save'), {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: !!keepalive && packed.length < 60000, // Browser-Grenze für keepalive
					headers: {
						'Content-Type': 'application/octet-stream',
						'X-Kladory-Encoding': 'gzip',
						Accept: 'application/json',
						requesttoken: requestToken(),
					},
					body: packed,
				})
				try { data = await res.json() } catch (e) { /* leer */ }
			} catch (e) {
				if (e instanceof TypeError && res === null && !(e.message || '').match(/compress/i)) throw e // Netzwerkfehler
			}
			if (res && res.ok) return data
			// nur bei „versteht der Server/Proxy nicht“ auf den klassischen Weg wechseln,
			// sonst (z. B. Serverfehler, Konflikt) den Fehler normal melden
			// (eine Antwort mit Kladory-Meldung ist immer ein echter Fehler, z. B. Seite gelöscht)
			if (res && (![400, 404, 405, 411, 415].includes(res.status) || (data && data.message))) {
				const err = new Error((data && data.message) || ('Serverfehler (' + res.status + ')'))
				err.status = res.status
				err.data = data
				throw err
			}
			// Komprimierung klappt hier nicht (z. B. Proxy) → für diese Sitzung klassisch speichern
			gzipSave = false
		}
		return api('PUT', '/pages/' + id, body, keepalive && json.length < 60000)
	}

	// Speichervorgänge laufen nacheinander, nie parallel (sonst Konflikt mit sich selbst)
	async function flushSave(keepalive) {
		clearTimeout(saveTimer)
		saveTimer = null
		while (saving) await saving
		const p = state.page
		if (!p || !p._dirty) return
		saving = doSave(p, keepalive === true).finally(() => { saving = null })
		return saving
	}

	async function doSave(p, keepalive, force) {
		p._dirty = false
		dirtySince = 0
		const id = p.id
		const body = { title: p.title || '' }
		if (!force) body.revision = p.revision || 0
		if (editor && editorPageId === id) {
			p.content = editor.serialize()
			body.content = p.content
		} else if (typeof p.content === 'string') {
			body.content = p.content
		}
		// nichts geändert (z. B. Strich gezeichnet und wieder rückgängig) → nichts senden
		if (!force && body.title === p._savedTitle && body.content === p._savedContent) {
			if (state.page === p) setSaveState('saved')
			return
		}
		if (state.page === p && !saveFailures) setSaveState('saving')
		try {
			const r = await sendSave(id, body, keepalive)
			p._savedTitle = body.title
			p._savedContent = body.content
			p._lastSavedAt = Date.now()
			saveFailures = 0
			clearTimeout(retryTimer)
			p.revision = r.revision
			p.updatedAt = r.updatedAt
			const summary = state.pages.find((x) => x.id === id)
			if (summary) summary.updatedAt = r.updatedAt
			const dateEl = pagesEl.querySelector('.kl-page-item[data-page-id="' + id + '"] .kl-page-date')
			if (dateEl) dateEl.textContent = fmtDate(r.updatedAt)
			if (state.page === p) {
				const m = contentEl.querySelector('.kl-meta-date')
				if (m) m.textContent = fmtDate(r.updatedAt)
				setSaveState(p._dirty ? 'dirty' : 'saved')
			}
		} catch (e) {
			if (e.status === 409 && e.data && e.data.page) {
				await resolveConflict(p, e.data.page)
				return
			}
			p._dirty = true
			const offline = !e.status || navigator.onLine === false
			saveFailures++
			if (state.page === p) setSaveState(offline ? 'offline' : 'error')
			// nur beim ersten Fehlschlag melden, danach still im Hintergrund weiter versuchen
			if (saveFailures === 1 && !offline) toast(e.message)
			if (e.status !== 403 && e.status !== 404 && e.status !== 400) {
				clearTimeout(retryTimer)
				retryTimer = setTimeout(flushSave, Math.min(30000, 5000 * 2 ** (saveFailures - 1)))
			}
		}
	}

	async function resolveConflict(mine, theirs) {
		const keepMine = await confirmDialog(
			'Die Seite „' + (theirs.title || 'Unbenannte Seite') + '“ wurde inzwischen an anderer Stelle geändert '
			+ '(z. B. in einem anderen Tab oder auf einem anderen Gerät). Welche Version soll gelten?',
			'Meine Version behalten', 'Andere Version laden', 'kl-btn-primary')
		if (keepMine) {
			mine.revision = theirs.revision
			mine._savedContent = undefined // auf jeden Fall erneut senden
			await doSave(mine, false, false)
		} else if (state.page === mine) {
			mine._dirty = false
			theirs.tags = mine.tags
			theirs._savedTitle = theirs.title || ''
			theirs._savedContent = theirs.content
			state.page = theirs
			const summary = state.pages.find((x) => x.id === theirs.id)
			if (summary) { summary.title = theirs.title; summary.updatedAt = theirs.updatedAt }
			renderPages()
			renderContent()
		}
	}

	// ------------------------------------------------------------------ Start

	async function init() {
		root = document.getElementById('kladory-app')
		if (!root) return
		navEl = el('section', { class: 'kl-col kl-nav', 'aria-label': 'Notizbücher' })
		pagesEl = el('section', { class: 'kl-col kl-pages', 'aria-label': 'Seiten' })
		contentEl = el('section', { class: 'kl-col kl-content', 'aria-label': 'Seite' })
		const scrim = el('div', { class: 'kl-scrim', onclick: () => setNavOpen(false) })
		root.replaceChildren(navEl, pagesEl, contentEl, scrim)

		const saved = loadLocal()
		for (const id of saved.expanded || []) state.expanded.add(id)

		buildNavSkeleton()
		navBody.replaceChildren(el('div', { class: 'kl-loading' }, 'Lädt …'))
		try {
			const data = await api('GET', '/tree')
			state.notebooks = data.notebooks || []
		} catch (e) {
			navEl.replaceChildren(el('div', { class: 'kl-empty' }, 'Kladory konnte nicht geladen werden: ' + e.message))
			return
		}

		renderNav()
		refreshTags()
		api('GET', '/capabilities').then((c) => { state.canCreateClass = !!c.canCreateClass; renderNav() }).catch(() => {})
		if (saved.sectionId && findSection(saved.sectionId)) {
			await selectSection(saved.sectionId, saved.pageId)
		} else {
			await selectFirstAvailable()
		}
		// Auf großen Bildschirmen sind ohnehin alle Spalten sichtbar; mobil mit der Übersicht starten
		setView(state.page ? 'page' : 'nav')

		window.addEventListener('beforeunload', (e) => {
			if (state.page && state.page._dirty) {
				flushSave(true)
				e.preventDefault()
				e.returnValue = ''
			}
		})
		// iPad/iPhone: beim Wechsel in eine andere App sofort speichern
		document.addEventListener('visibilitychange', () => {
			if (document.visibilityState === 'hidden') flushSave(true)
			else if (state.page) setSaveState(state.page._dirty ? (saveFailures ? 'error' : 'dirty') : 'saved')
		})
		// Verbindung wieder da → sofort nachspeichern
		window.addEventListener('online', () => {
			if (state.page && state.page._dirty) flushSave()
		})
		// Warnung beim Schließen, wenn Änderungen wegen eines Fehlers noch nicht gespeichert sind
		window.addEventListener('beforeunload', (e) => {
			if (saveFailures && state.page && state.page._dirty) {
				e.preventDefault()
				e.returnValue = ''
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
