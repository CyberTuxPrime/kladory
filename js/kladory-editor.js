/*!
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Kladory – Leinwand-Editor (Etappe 5: Text, Bilder, Zeichnen)
 * Frei platzierbare Textfelder wie in OneNote. Kein Build-Schritt nötig.
 *
 * window.KladoryEditor.mount({ canvasEl, toolbarEl, content, onChange, uploadImage, imageUrl, readOnly })
 *   uploadImage(file: Blob) -> Promise<string>  (Dateiname auf dem Server)
 *   imageUrl(src: string)   -> string           (Adresse zum Anzeigen)
 *   -> { serialize(): string, destroy(): void }
 */
(function () {
	'use strict'

	const TEXT_COLORS = [
		{ c: '#d93025', n: 'Rot' }, { c: '#e8710a', n: 'Orange' }, { c: '#188038', n: 'Grün' },
		{ c: '#1a73e8', n: 'Blau' }, { c: '#9334e6', n: 'Lila' }, { c: '#5f6368', n: 'Grau' },
	]
	const HIGHLIGHTS = [
		{ c: '#fff3a3', n: 'Gelb' }, { c: '#c8f7c5', n: 'Grün' }, { c: '#cfe8ff', n: 'Blau' },
		{ c: '#ffd6e7', n: 'Rosa' }, { c: '#ffe0b2', n: 'Orange' }, { c: 'transparent', n: 'Keine' },
	]

	// ------------------------------------------------------------ Bereinigung (wie ContentSanitizer.php)

	const ALLOWED = {
		P: [], DIV: [], BR: [], B: [], STRONG: [], I: [], EM: [], U: [], S: [], STRIKE: [],
		H1: [], H2: [], H3: [], UL: ['class'], OL: [], LI: ['data-checked'], SPAN: ['style'],
		BLOCKQUOTE: [], CODE: [], PRE: [], MARK: [], SUB: [], SUP: [],
	}
	const DROP = ['SCRIPT', 'STYLE', 'IFRAME', 'FRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'NOSCRIPT', 'SVG', 'MATH',
		'HEAD', 'TITLE', 'META', 'LINK', 'BASE', 'FORM', 'INPUT', 'TEXTAREA', 'BUTTON', 'SELECT', 'OPTION',
		'AUDIO', 'VIDEO', 'CANVAS', 'IMG', 'PICTURE', 'SOURCE']
	const COLOR_RE = /^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)|transparent)$/i

	function cleanStyle(style) {
		const kept = {}
		for (const decl of String(style || '').split(';')) {
			const i = decl.indexOf(':')
			if (i < 0) continue
			const prop = decl.slice(0, i).trim().toLowerCase()
			const val = decl.slice(i + 1).trim()
			if ((prop === 'color' || prop === 'background-color') && COLOR_RE.test(val)) kept[prop] = prop + ': ' + val
		}
		return Object.values(kept).join('; ')
	}

	function cleanNode(node) {
		for (const child of [...node.childNodes]) {
			if (child.nodeType === Node.TEXT_NODE) continue
			if (child.nodeType !== Node.ELEMENT_NODE) { child.remove(); continue }
			const tag = child.tagName.toUpperCase()
			if (DROP.includes(tag)) { child.remove(); continue }
			if (!(tag in ALLOWED)) {
				cleanNode(child)
				child.replaceWith(...child.childNodes)
				continue
			}
			for (const a of [...child.attributes]) {
				if (!ALLOWED[tag].includes(a.name.toLowerCase())) child.removeAttribute(a.name)
			}
			if (tag === 'UL' && child.getAttribute('class') !== 'kl-check') child.removeAttribute('class')
			if (tag === 'LI' && child.getAttribute('data-checked') !== 'true') child.removeAttribute('data-checked')
			if (tag === 'SPAN' && child.hasAttribute('style')) {
				const st = cleanStyle(child.getAttribute('style'))
				if (st) child.setAttribute('style', st)
				else child.removeAttribute('style')
			}
			cleanNode(child)
		}
	}

	function sanitizeHtml(html) {
		if (!html || !String(html).trim()) return ''
		// DOMParser erzeugt ein inaktives Dokument: hier wird nichts ausgeführt oder geladen
		const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html')
		cleanNode(doc.body)
		return doc.body.innerHTML
	}

	// ------------------------------------------------------------ kleine Hilfen

	function el(tag, props, ...children) {
		const e = document.createElement(tag)
		for (const [k, v] of Object.entries(props || {})) {
			if (v === null || v === undefined || v === false) continue
			if (k === 'class') e.className = v
			else if (k === 'style' && typeof v === 'object') Object.assign(e.style, v)
			else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2).toLowerCase(), v)
			else e.setAttribute(k, v === true ? '' : v)
		}
		for (const c of children.flat(Infinity)) {
			if (c === null || c === undefined || c === false) continue
			e.append(c instanceof Node ? c : document.createTextNode(String(c)))
		}
		return e
	}

	const ICONS = {
		list: '<circle cx="5" cy="7" r="1.3"/><circle cx="5" cy="12" r="1.3"/><circle cx="5" cy="17" r="1.3"/><path d="M10 7h10M10 12h10M10 17h10"/>',
		olist: '<path d="M4 5h2v4M4 9h3M4 14.5c0-1 2.5-1 2.5 0 0 1-2.5 2-2.5 3H7M10 7h10M10 12h10M10 17h10"/>',
		check: '<rect x="3.5" y="4.5" width="6" height="6" rx="1.5"/><path d="M5 7.5l1.3 1.3L8.5 6M3.5 15h6M13 7.5h7.5M13 16h7.5"/>',
		color: '<path d="M7 18L12 5l5 13M8.8 13.5h6.4"/><path d="M4 21h16" stroke-width="3"/>',
		marker: '<path d="M14.5 4.5l5 5-8 8H6.5v-5z"/><path d="M4 21h16" stroke-width="3"/>',
		clear: '<path d="M6 5h12M12 5l-3 14M4 20l16-16"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		pen: '<path d="M4 20l1.5-5L16 4.5l3.5 3.5L9 18.5z"/><path d="M14 6.5l3.5 3.5"/>',
		cursor: '<path d="M8 4h4M10 4v16M8 20h4"/>',
		eraser: '<path d="M8 20h12"/><path d="M4.5 15.5l9-9a2 2 0 0 1 2.8 0l2.2 2.2a2 2 0 0 1 0 2.8L11 19H8z"/><path d="M9 11l5 5"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
		redo: '<path d="M15 14l5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/>',
		lasso: '<path d="M7 17.5c-2.4-1.2-4-3.2-4-5.5C3 7.6 7 4 12 4s9 3.6 9 8-4 8-9 8c-1 0-2-.1-2.9-.4"/><path d="M7 17.5c0 1.7-1 3-2.5 3.5M7 17.5a2 2 0 1 1 2.1 2.1" stroke-dasharray="0"/>',
		copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
		lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
		image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="M21 16l-5-5-9 9"/>',
	}

	function svg(name) {
		const s = el('span', { class: 'kl-icon', 'aria-hidden': 'true' })
		s.innerHTML = '<svg viewBox="0 0 24 24">' + ICONS[name] + '</svg>'
		return s
	}

	function uid() {
		return 'b' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-5)
	}

	function exec(cmd, value) {
		try { document.execCommand(cmd, false, value) } catch (e) { /* nicht unterstützt */ }
	}

	// Farben als <span style> statt <font>; danach wieder normal (sonst wird auch Fett zu CSS)
	function execStyled(cmd, value) {
		exec('styleWithCSS', true)
		exec(cmd, value)
		exec('styleWithCSS', false)
	}

	function isEmpty(body) {
		return body.textContent.trim() === '' && !body.querySelector('li')
	}

	const MAX_IMAGE_EDGE = 2000

	/** Große Fotos (z. B. vom iPad) vor dem Hochladen verkleinern */
	async function prepareImage(file) {
		if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) {
			// HEIC o. Ä.: über den Browser in JPEG umwandeln, falls er das Format lesen kann
			if (!file.type.startsWith('image/')) throw new Error('Das ist keine Bilddatei.')
		}
		if (file.type === 'image/gif') return { blob: file }
		let bmp
		try {
			bmp = await createImageBitmap(file)
		} catch (e) {
			if (/^image\/(png|jpeg|webp)$/.test(file.type)) return { blob: file }
			throw new Error('Dieses Bildformat wird nicht unterstützt.')
		}
		const scale = Math.min(1, MAX_IMAGE_EDGE / Math.max(bmp.width, bmp.height))
		const needsConvert = !/^image\/(png|jpeg|webp)$/.test(file.type)
		if (scale === 1 && !needsConvert && file.size < 1.5 * 1024 * 1024) {
			const r = { blob: file, width: bmp.width }
			bmp.close && bmp.close()
			return r
		}
		const w = Math.round(bmp.width * scale)
		const h = Math.round(bmp.height * scale)
		const canvas = document.createElement('canvas')
		canvas.width = w
		canvas.height = h
		canvas.getContext('2d').drawImage(bmp, 0, 0, w, h)
		bmp.close && bmp.close()
		const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg'
		const blob = await new Promise((res) => canvas.toBlob(res, type, 0.85))
		if (!blob) return { blob: file }
		return { blob, width: w }
	}

	// ------------------------------------------------------------ Editor

	function mount(opts) {
		const canvasEl = opts.canvasEl
		const toolbarEl = opts.toolbarEl
		const onChange = typeof opts.onChange === 'function' ? opts.onChange : () => {}
		const uploadImage = typeof opts.uploadImage === 'function' ? opts.uploadImage : null
		const imageUrl = typeof opts.imageUrl === 'function' ? opts.imageUrl : (src) => src
		const readOnly = !!opts.readOnly

		let data = { blocks: [] }
		try {
			const parsed = opts.content ? JSON.parse(opts.content) : null
			if (parsed && Array.isArray(parsed.blocks)) data = parsed
		} catch (e) { /* ungültig -> leere Seite */ }

		const surface = el('div', { class: 'kl-surface' + (readOnly ? ' kl-readonly' : '') })
		const hint = el('div', { class: 'kl-surface-hint' }, readOnly ? 'Diese Seite ist leer.' : 'Tippe oder klicke irgendwo auf die Seite, um zu schreiben.')
		surface.append(hint)
		canvasEl.replaceChildren(surface)

		let active = null
		let pop = null
		let savedRange = null
		const cleanups = []

		const changed = () => { updateSurface(); onChange() }

		// ---------- Blöcke ----------

		function createBlock(b) {
			return b.type === 'image' ? createImageBlock(b) : createTextBlock(b)
		}

		function isImage(node) {
			return !!node && node.classList.contains('kl-imgblock')
		}

		function createImageBlock(b) {
			const img = el('img', { alt: '', draggable: 'false' })
			if (b.src) img.src = imageUrl(b.src)
			img.addEventListener('load', updateSurface)
			const handle = el('div', { class: 'kl-block-handle', title: 'Verschieben' })
			const resize = el('div', { class: 'kl-block-resize', title: 'Größe ändern' })
			const node = el('div', { class: 'kl-block kl-imgblock', tabindex: '0', role: 'img', 'aria-label': 'Bild' }, handle, img, resize)
			node.dataset.id = b.id || uid()
			if (b.src) node.dataset.src = b.src
			node.style.left = Math.max(0, Number(b.x) || 0) + 'px'
			node.style.top = Math.max(0, Number(b.y) || 0) + 'px'
			node.style.width = Math.max(40, Number(b.w) || 320) + 'px'
			surface.append(node)

			node.addEventListener('focus', () => setActive(node))
			node.addEventListener('pointerdown', (e) => {
				if (e.target === img) { setActive(node); node.focus({ preventScroll: true }) }
			})
			// Bild auch direkt am Bild verschieben (nicht nur an der Griffleiste)
			img.addEventListener('pointerdown', (e) => startDrag(e, node, 'move'))
			node.addEventListener('focusout', () => {
				setTimeout(() => {
					if (!node.isConnected || node.contains(document.activeElement)) return
					if (active === node) setActive(null)
				}, 0)
			})
			node.addEventListener('keydown', (e) => {
				if ((e.key === 'Delete' || e.key === 'Backspace') && !readOnly) {
					e.preventDefault()
					removeBlock(node)
				} else if (e.key === 'Escape') {
					node.blur()
				}
			})
			handle.addEventListener('pointerdown', (e) => startDrag(e, node, 'move'))
			resize.addEventListener('pointerdown', (e) => startDrag(e, node, 'resize'))
			return node
		}

		function removeBlock(node) {
			if (active === node) setActive(null)
			node.remove()
			updateHint()
			changed()
		}

		function createTextBlock(b) {
			const body = el('div', {
				class: 'kl-block-body',
				contenteditable: readOnly ? 'false' : 'true',
				spellcheck: 'true',
				role: 'textbox',
				'aria-multiline': 'true',
				'aria-label': 'Textfeld',
			})
			body.innerHTML = sanitizeHtml(b.html || '')
			const handle = el('div', { class: 'kl-block-handle', title: 'Verschieben' })
			const resize = el('div', { class: 'kl-block-resize', title: 'Breite ändern' })
			const node = el('div', { class: 'kl-block' }, handle, body, resize)
			node.dataset.id = b.id || uid()
			node.style.left = Math.max(0, Number(b.x) || 0) + 'px'
			node.style.top = Math.max(0, Number(b.y) || 0) + 'px'
			node.style.width = Math.max(120, Number(b.w) || 360) + 'px'
			surface.append(node)

			body.addEventListener('focusin', () => setActive(node))
			node.addEventListener('focusout', () => {
				setTimeout(() => {
					if (node.contains(document.activeElement)) return
					if (!node.isConnected) return
					if (node.classList.contains('kl-pad')) {
						commitPad()
						if (active === node) setActive(null)
						return
					}
					ensurePad()
					if (isEmpty(body)) {
						node.remove()
						if (active === node) setActive(null)
						updateHint()
						changed()
					} else if (active === node) {
						setActive(null)
					}
				}, 0)
			})
			body.addEventListener('input', () => {
				// Schreibfeld bleibt Schreibfeld: erkannter Text wandert nach einer Pause in das Textfeld darüber
				if (node.classList.contains('kl-pad')) { schedulePadCommit(); return }
				changed()
			})
			body.addEventListener('paste', (e) => onPaste(e))
			body.addEventListener('click', (e) => onCheckClick(e, body))
			body.addEventListener('keydown', (e) => onKey(e, body))
			handle.addEventListener('pointerdown', (e) => startDrag(e, node, 'move'))
			resize.addEventListener('pointerdown', (e) => startDrag(e, node, 'resize'))
			return node
		}

		function setActive(node) {
			if (active === node) return
			if (active) active.classList.remove('active')
			active = node
			if (active) active.classList.add('active')
			closePop()
			updateToolbar()
			try { updateDrawToolbar() } catch (e) { /* beim Laden noch nicht bereit */ }
		}

		function bodyOf(node) {
			return node ? node.querySelector('.kl-block-body') : null
		}

		function startDrag(e, node, mode) {
			if (readOnly) return
			if (e.button !== undefined && e.button !== 0) return
			e.preventDefault()
			const target = e.currentTarget
			const sx = e.clientX
			const sy = e.clientY
			const ox = node.offsetLeft
			const oy = node.offsetTop
			const ow = node.offsetWidth
			let moved = false
			try { target.setPointerCapture(e.pointerId) } catch (err) { /* egal */ }
			node.classList.add('dragging')
			setActive(node)

			const move = (ev) => {
				const dx = ev.clientX - sx
				const dy = ev.clientY - sy
				if (!moved && Math.abs(dx) + Math.abs(dy) < 3) return
				moved = true
				if (mode === 'move') {
					node.style.left = Math.max(0, Math.round(ox + dx)) + 'px'
					node.style.top = Math.max(0, Math.round(oy + dy)) + 'px'
				} else {
					node.style.width = Math.max(isImage(node) ? 40 : 120, Math.round(ow + dx)) + 'px'
				}
				updateSurface()
			}
			const up = () => {
				target.removeEventListener('pointermove', move)
				target.removeEventListener('pointerup', up)
				target.removeEventListener('pointercancel', up)
				node.classList.remove('dragging')
				if (moved) changed()
				else if (isImage(node)) node.focus({ preventScroll: true })
				else bodyOf(node).focus()
			}
			target.addEventListener('pointermove', move)
			target.addEventListener('pointerup', up)
			target.addEventListener('pointercancel', up)
		}

		function updateSurface() {
			let maxR = 0
			let maxB = 0
			for (const n of surface.querySelectorAll('.kl-block')) {
				maxR = Math.max(maxR, n.offsetLeft + n.offsetWidth)
				maxB = Math.max(maxB, n.offsetTop + n.offsetHeight)
			}
			for (const st of strokes) {
				const b = st.box || (st.box = boxOf(st.p))
				maxR = Math.max(maxR, b[2])
				maxB = Math.max(maxB, b[3])
			}
			surface.style.minWidth = 'max(100%, ' + (maxR + 240) + 'px)'
			surface.style.minHeight = 'max(100%, ' + (maxB + 320) + 'px)'
		}

		function updateHint() {
			hint.hidden = !!surface.querySelector('.kl-block') || strokes.length > 0
		}

		// ---------- Klick auf freie Fläche = neues Textfeld ----------

		let down = null
		const onSurfaceDown = (e) => {
			down = null
			// Apple Pencil / Stift zeichnet auf freier Fläche immer direkt
			if (e.pointerType === 'pen' && !readOnly && mode === 'text' && (e.target === surface || e.target === hint || e.target === ink)) {
				if (penText) {
					// Stift → Text: Schreibfeld dorthin legen, Scribble übernimmt die Erkennung
					const [x, y] = toLocal(e)
					movePadTo(Math.max(0, x - 12), Math.max(0, y - 30))
					return
				}
				onInkDown(e, true)
				return
			}
			down = (e.target === surface || e.target === hint) ? { x: e.clientX, y: e.clientY } : null
			if (down && active && !active.contains(document.activeElement)) setActive(null)
		}
		const onSurfaceClick = (e) => {
			if (readOnly) return
			if (!down || (e.target !== surface && e.target !== hint)) return
			if (Math.hypot(e.clientX - down.x, e.clientY - down.y) > 8) return
			down = null
			const r = surface.getBoundingClientRect()
			const x = Math.max(0, Math.round(e.clientX - r.left - 8))
			const y = Math.max(0, Math.round(e.clientY - r.top - 18))
			addBlockAt(x, y)
		}
		surface.addEventListener('pointerdown', onSurfaceDown)
		surface.addEventListener('click', onSurfaceClick)

		function addBlockAt(x, y) {
			const w = Math.min(480, Math.max(220, canvasEl.clientWidth - x - 24))
			const node = createBlock({ id: uid(), x, y, w, html: '' })
			updateHint()
			updateSurface()
			bodyOf(node).focus()
			return node
		}

		function addBlockBelow() {
			let maxB = 0
			for (const n of surface.querySelectorAll('.kl-block')) maxB = Math.max(maxB, n.offsetTop + n.offsetHeight)
			const y = maxB ? maxB + 24 : 24
			const node = addBlockAt(24, y)
			node.style.width = Math.min(640, Math.max(240, canvasEl.clientWidth - 48)) + 'px'
			node.scrollIntoView({ block: 'nearest' })
		}

		// ---------- Bilder einfügen ----------

		function nextFreeY() {
			let maxB = 0
			for (const n of surface.querySelectorAll('.kl-block:not(.kl-pad)')) maxB = Math.max(maxB, n.offsetTop + n.offsetHeight)
			return maxB ? maxB + 24 : 24
		}

		async function insertImageFiles(files, x, y) {
			if (!uploadImage) return
			let offset = 0
			for (const file of files) {
				const node = createImageBlock({ id: uid(), x: x + offset, y: y + offset, w: 240 })
				node.classList.add('kl-uploading')
				updateHint()
				updateSurface()
				offset += 24
				try {
					const prepared = await prepareImage(file)
					const src = await uploadImage(prepared.blob)
					if (!node.isConnected) continue
					node.dataset.src = src
					const w = Math.min(prepared.width || 480, 480, Math.max(160, canvasEl.clientWidth - x - 32))
					node.style.width = w + 'px'
					node.querySelector('img').src = imageUrl(src)
					node.classList.remove('kl-uploading')
					changed()
				} catch (e) {
					node.remove()
					updateHint()
					if (typeof opts.onError === 'function') opts.onError(e.message || 'Bild konnte nicht hochgeladen werden.')
				}
			}
		}

		function pickImages() {
			const input = el('input', { type: 'file', accept: 'image/*', multiple: true, style: { display: 'none' } })
			input.addEventListener('change', () => {
				const files = [...(input.files || [])]
				input.remove()
				if (files.length) {
					let x = 24
					let y = nextFreeY()
					if (active) { x = active.offsetLeft; y = active.offsetTop + active.offsetHeight + 16 }
					insertImageFiles(files, x, y)
				}
			})
			document.body.append(input)
			input.click()
		}

		const onDragOver = (e) => {
			if (readOnly) return
			if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) {
				e.preventDefault()
				surface.classList.add('kl-dropping')
			}
		}
		const onDragLeave = () => surface.classList.remove('kl-dropping')
		const onDrop = (e) => {
			surface.classList.remove('kl-dropping')
			if (readOnly) return
			const files = [...((e.dataTransfer && e.dataTransfer.files) || [])].filter((f) => f.type.startsWith('image/'))
			if (!files.length) return
			e.preventDefault()
			const r = surface.getBoundingClientRect()
			insertImageFiles(files, Math.max(0, Math.round(e.clientX - r.left)), Math.max(0, Math.round(e.clientY - r.top)))
		}
		surface.addEventListener('dragover', onDragOver)
		surface.addEventListener('dragleave', onDragLeave)
		surface.addEventListener('drop', onDrop)

		// ---------- Text-Eingaben ----------

		function onPaste(e) {
			const cd = e.clipboardData
			if (!cd) return
			const files = [...(cd.files || [])].filter((f) => f.type.startsWith('image/'))
			if (files.length && uploadImage) {
				e.preventDefault()
				const node = active
				insertImageFiles(files, node ? node.offsetLeft : 24, node ? node.offsetTop + node.offsetHeight + 16 : nextFreeY())
				return
			}
			e.preventDefault()
			const html = cd.getData('text/html')
			if (html) exec('insertHTML', sanitizeHtml(html))
			else exec('insertText', cd.getData('text/plain'))
			changed()
		}

		function onCheckClick(e, body) {
			if (readOnly) return
			const li = e.target.closest ? e.target.closest('li') : null
			if (!li || !body.contains(li)) return
			const ul = li.parentElement
			if (!ul || !ul.classList.contains('kl-check')) return
			const r = li.getBoundingClientRect()
			if (e.clientX - r.left > 26) return
			e.preventDefault()
			if (li.dataset.checked === 'true') delete li.dataset.checked
			else li.dataset.checked = 'true'
			changed()
		}

		function onKey(e, body) {
			if (e.key === 'Escape') {
				e.preventDefault()
				body.blur()
			} else if (e.key === 'Enter') {
				// Neuer Checklisten-Punkt soll nicht schon abgehakt sein
				setTimeout(() => {
					const li = closestInSelection('LI', body)
					if (li && li.dataset.checked === 'true' && li.textContent.trim() === '') {
						delete li.dataset.checked
					}
				}, 0)
			} else if (e.key === 'Tab') {
				const li = closestInSelection('LI', body)
				if (li) {
					e.preventDefault()
					exec(e.shiftKey ? 'outdent' : 'indent')
					changed()
				}
			}
		}

		function closestInSelection(tag, body) {
			const sel = window.getSelection()
			if (!sel || !sel.rangeCount) return null
			let n = sel.anchorNode
			if (n && n.nodeType === 1 && n.childNodes[sel.anchorOffset]) n = n.childNodes[sel.anchorOffset]
			while (n && n !== body) {
				if (n.nodeType === 1 && n.tagName === tag) return n
				n = n.parentNode
			}
			return null
		}

		// ---------- Werkzeugleiste ----------

		const TOOLS = [
			{ cmd: 'add', icon: 'plus', title: 'Neues Textfeld', always: true },
			{ cmd: 'image', icon: 'image', title: 'Bild einfügen', always: true },
			{ cmd: 'pentext', icon: 'pen', label: 'Stift → Text', title: 'Apple Pencil schreibt Text: Handschrift wird vom iPad (Scribble) in Text umgewandelt', always: true, toggle: true },
			'|',
			{ cmd: 'p', text: 'Text', title: 'Normaler Text' },
			{ cmd: 'h1', text: 'H1', title: 'Überschrift 1' },
			{ cmd: 'h2', text: 'H2', title: 'Überschrift 2' },
			{ cmd: 'h3', text: 'H3', title: 'Überschrift 3' },
			'|',
			{ cmd: 'bold', text: 'B', title: 'Fett (Strg+B)', state: 'bold', cls: 'kl-tb-b' },
			{ cmd: 'italic', text: 'I', title: 'Kursiv (Strg+I)', state: 'italic', cls: 'kl-tb-i' },
			{ cmd: 'underline', text: 'U', title: 'Unterstrichen (Strg+U)', state: 'underline', cls: 'kl-tb-u' },
			{ cmd: 'strikeThrough', text: 'S', title: 'Durchgestrichen', state: 'strikeThrough', cls: 'kl-tb-s' },
			'|',
			{ cmd: 'ul', icon: 'list', title: 'Aufzählung' },
			{ cmd: 'ol', icon: 'olist', title: 'Nummerierung' },
			{ cmd: 'check', icon: 'check', title: 'Checkliste' },
			'|',
			{ cmd: 'color', icon: 'color', title: 'Textfarbe' },
			{ cmd: 'highlight', icon: 'marker', title: 'Hervorheben' },
			{ cmd: 'clear', icon: 'clear', title: 'Formatierung entfernen' },
			'-',
			{ cmd: 'delete', icon: 'trash', title: 'Ausgewähltes Element löschen', cls: 'kl-tb-danger', anyBlock: true },
		]

		let toolButtons = []

		function buildTextBar() {
			toolButtons = []
			const bar = el('div', { class: 'kl-toolbar-inner', role: 'toolbar', 'aria-label': 'Formatierung' })
			for (const t of TOOLS) {
				if (t === '|') { bar.append(el('span', { class: 'kl-tb-sep' })); continue }
				if (t === '-') { bar.append(el('span', { class: 'kl-tb-spacer' })); continue }
				const b = el('button', {
					class: 'kl-tb' + (t.cls ? ' ' + t.cls : ''),
					type: 'button',
					title: t.title,
					'aria-label': t.title,
					onclick: (e) => run(t, e.currentTarget),
				}, t.icon ? svg(t.icon) : t.text, t.label ? el('span', { class: 'kl-tb-label' }, t.label) : null)
				b._tool = t
				toolButtons.push(b)
				bar.append(b)
			}
			return bar
		}

		function renderToolbar() {
			if (readOnly) {
				toolbarEl.replaceChildren(el('div', { class: 'kl-toolbar-ro' }, svg('lock'), 'Nur lesen – diese Seite kann nur von Lehrkräften bearbeitet werden.'))
				return
			}
			const bar = mode === 'draw' ? buildDrawBar() : buildTextBar()
			// Fokus/Markierung im Textfeld behalten, wenn auf die Leiste getippt wird
			const keepFocus = (e) => { if (e.target.closest('button')) e.preventDefault() }
			bar.addEventListener('mousedown', keepFocus)
			bar.addEventListener('pointerdown', keepFocus)
			// Umschalter bleibt immer sichtbar, nur die Werkzeuge scrollen
			historyButtons = null
			const hist = historyGroup()
			hist.addEventListener('mousedown', keepFocus)
			hist.addEventListener('pointerdown', keepFocus)
			toolbarEl.replaceChildren(el('div', { class: 'kl-toolbar-row' }, modeSwitch(), hist, bar))
			updateToolbar()
			updateDrawToolbar()
		}

		function updateToolbar() {
			for (const b of toolButtons) {
				const t = b._tool
				const enabled = t.always || (!!active && (t.anyBlock || !isImage(active)))
				b.setAttribute('aria-disabled', enabled ? 'false' : 'true')
				if (t.toggle) {
					b.classList.toggle('on', penText)
					b.setAttribute('aria-pressed', penText ? 'true' : 'false')
					continue
				}
				let on = false
				if (enabled && t.state && active && !isImage(active)) {
					try { on = document.queryCommandState(t.state) } catch (e) { on = false }
				}
				b.classList.toggle('on', on)
			}
		}
		const onSelChange = () => { if (active) { updateToolbar(); updateDrawToolbar() } }
		document.addEventListener('selectionchange', onSelChange)
		cleanups.push(() => document.removeEventListener('selectionchange', onSelChange))

		function ensureFocus() {
			if (isImage(active)) return null
			const body = bodyOf(active)
			if (body && !body.contains(document.activeElement)) body.focus()
			return body
		}

		function run(t, btn) {
			if (t.cmd === 'add') { addBlockBelow(); return }
			if (t.cmd === 'image') { pickImages(); return }
			if (t.cmd === 'pentext') {
				penText = !penText
				try { localStorage.setItem('kladory.penText', penText ? '1' : '0') } catch (e) { /* egal */ }
				if (penText) ensurePad()
				else removePad()
				updateToolbar()
				return
			}
			if (!active) return
			if (t.cmd === 'delete') { removeBlock(active); return }
			if (isImage(active)) return
			const body = ensureFocus()
			switch (t.cmd) {
			case 'p': exec('formatBlock', '<p>'); break
			case 'h1': case 'h2': case 'h3': exec('formatBlock', '<' + t.cmd + '>'); break
			case 'bold': case 'italic': case 'underline': case 'strikeThrough': exec(t.cmd); break
			case 'ul': {
				const ul = closestInSelection('UL', body)
				if (ul && ul.classList.contains('kl-check')) ul.classList.remove('kl-check')
				else exec('insertUnorderedList')
				break
			}
			case 'ol': exec('insertOrderedList'); break
			case 'check': {
				let ul = closestInSelection('UL', body)
				if (ul) {
					ul.classList.toggle('kl-check')
				} else {
					if (closestInSelection('OL', body)) exec('insertOrderedList')
					const before = new Set(body.querySelectorAll('ul'))
					exec('insertUnorderedList')
					ul = closestInSelection('UL', body)
						|| [...body.querySelectorAll('ul')].find((u) => !before.has(u))
					if (ul) ul.classList.add('kl-check')
				}
				break
			}
			case 'color':
				openPop(btn, TEXT_COLORS, (c) => execStyled('foreColor', c))
				return
			case 'highlight':
				openPop(btn, HIGHLIGHTS, (c) => execStyled('hiliteColor', c))
				return
			case 'clear': exec('removeFormat'); break
			}
			changed()
			updateToolbar()
		}

		// ---------- Farbauswahl ----------

		function closePop() {
			if (!pop) return
			pop.remove()
			pop = null
			document.removeEventListener('pointerdown', onPopOutside, true)
		}
		function onPopOutside(e) {
			if (pop && !pop.contains(e.target) && !(e.target.closest && e.target.closest('.kl-tb'))) closePop()
		}

		function openPop(btn, colors, apply) {
			const wasOpen = pop && pop._btn === btn
			closePop()
			if (wasOpen) return
			const sel = window.getSelection()
			savedRange = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null
			pop = el('div', { class: 'kl-pop', role: 'menu' }, colors.map((col) => el('button', {
				class: 'kl-swatch' + (col.c === 'transparent' ? ' kl-swatch-none' : ''),
				type: 'button',
				title: col.n,
				'aria-label': col.n,
				style: { background: col.c === 'transparent' ? '' : col.c },
				onclick: () => {
					const body = ensureFocus()
					if (savedRange && body && body.contains(savedRange.startContainer)) {
						const s = window.getSelection()
						s.removeAllRanges()
						s.addRange(savedRange)
					}
					apply(col.c)
					closePop()
					changed()
				},
			})))
			pop._btn = btn
			pop.addEventListener('mousedown', (e) => e.preventDefault())
			pop.addEventListener('pointerdown', (e) => e.preventDefault())
			document.body.append(pop)
			const r = btn.getBoundingClientRect()
			pop.style.top = (r.bottom + 6) + 'px'
			pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8)) + 'px'
			setTimeout(() => document.addEventListener('pointerdown', onPopOutside, true))
		}

		// ---------- Zeichnen (Freihand mit Finger oder Stift) ----------

		const NS = 'http://www.w3.org/2000/svg'
		const INK_COLORS = [
			{ c: 'auto', n: 'Schwarz' }, { c: '#1a73e8', n: 'Blau' }, { c: '#d93025', n: 'Rot' },
			{ c: '#188038', n: 'Grün' }, { c: '#9334e6', n: 'Lila' },
		]
		const MARKERS = [{ c: '#ffd400', n: 'Textmarker gelb' }, { c: '#7ee081', n: 'Textmarker grün' }]
		const SIZES = [{ s: 2, n: 'Fein' }, { s: 4, n: 'Mittel' }, { s: 8, n: 'Dick' }]

		const ink = document.createElementNS(NS, 'svg')
		ink.setAttribute('class', 'kl-ink')
		ink.setAttribute('aria-hidden', 'true')
		surface.append(ink)
		const capture = el('div', { class: 'kl-draw-capture' })
		const strokes = [] // { c, s, t, p: [[x,y],...], el, box }
		const undoStack = []
		const redoStack = []
		const activePointers = new Map()
		let current = null
		let erasing = null
		let panLast = null
		let penSeen = false

		let mode = 'text'
		const tool = { kind: 'pen', color: 'auto', size: 4, markerColor: '#ffd400' }
		try {
			if (!readOnly && localStorage.getItem('kladory.mode') === 'draw') mode = 'draw'
			Object.assign(tool, JSON.parse(localStorage.getItem('kladory.tool') || '{}'))
		} catch (e) { /* egal */ }

		function saveTool() {
			try {
				localStorage.setItem('kladory.tool', JSON.stringify(tool))
				localStorage.setItem('kladory.mode', mode)
			} catch (e) { /* egal */ }
		}

		function boxOf(p) {
			let a = Infinity; let b = Infinity; let c = -Infinity; let d = -Infinity
			for (const [x, y] of p) { a = Math.min(a, x); b = Math.min(b, y); c = Math.max(c, x); d = Math.max(d, y) }
			return [a, b, c, d]
		}

		function pathData(p) {
			if (!p.length) return ''
			if (p.length === 1) return 'M' + p[0][0] + ' ' + p[0][1] + 'l0.01 0'
			let d = 'M' + p[0][0] + ' ' + p[0][1]
			for (let i = 1; i < p.length - 1; i++) {
				const mx = (p[i][0] + p[i + 1][0]) / 2
				const my = (p[i][1] + p[i + 1][1]) / 2
				d += 'Q' + p[i][0] + ' ' + p[i][1] + ' ' + mx + ' ' + my
			}
			const l = p[p.length - 1]
			return d + 'L' + l[0] + ' ' + l[1]
		}

		function renderStroke(st) {
			const path = document.createElementNS(NS, 'path')
			path.setAttribute('fill', 'none')
			path.setAttribute('stroke-linecap', 'round')
			path.setAttribute('stroke-linejoin', 'round')
			path.setAttribute('stroke-width', String(st.t === 'marker' ? st.s * 4 : st.s))
			path.style.stroke = st.c === 'auto' ? 'var(--color-main-text, #222)' : st.c
			if (st.t === 'marker') path.setAttribute('class', 'kl-ink-marker')
			path.setAttribute('d', pathData(st.p))
			st.el = path
			// Textmarker liegt unter der Schrift
			if (st.t === 'marker') ink.insertBefore(path, ink.firstChild)
			else ink.append(path)
		}

		function toLocal(e) {
			const r = surface.getBoundingClientRect()
			return [Math.round(e.clientX - r.left), Math.round(e.clientY - r.top)]
		}

		function startStroke(e, kind) {
			const st = kind === 'marker'
				? { c: tool.markerColor, s: tool.size, t: 'marker', p: [toLocal(e)] }
				: { c: tool.color, s: tool.size, t: 'pen', p: [toLocal(e)] }
			renderStroke(st)
			strokes.push(st)
			current = { st, id: e.pointerId }
			updateHint()
		}

		function extendStroke(e) {
			const list = (e.getCoalescedEvents && e.getCoalescedEvents().length) ? e.getCoalescedEvents() : [e]
			const p = current.st.p
			for (const ev of list) {
				const pt = toLocal(ev)
				const last = p[p.length - 1]
				if (Math.abs(pt[0] - last[0]) + Math.abs(pt[1] - last[1]) >= 2 && p.length < 5000) p.push(pt)
			}
			current.st.el.setAttribute('d', pathData(p))
		}

		function endStroke() {
			const st = current.st
			current = null
			// Punkte ausdünnen, die auf (fast) gerader Linie liegen – optisch kein Unterschied,
			// aber deutlich weniger Daten beim Speichern
			st.p = simplify(st.p, 0.75)
			st.d = null // gespeicherte Kurzform neu berechnen
			st.el.setAttribute('d', pathData(st.p))
			st.box = boxOf(st.p)
			pushAction({ type: 'add', st })
			updateSurface()
			changed()
			updateDrawToolbar()
		}

		function cancelStroke() {
			if (!current) return
			const i = strokes.indexOf(current.st)
			if (i >= 0) strokes.splice(i, 1)
			current.st.el.remove()
			current = null
			updateHint()
		}

		// Ramer-Douglas-Peucker: entfernt Punkte, die weniger als eps Pixel von der Verbindungslinie abweichen
		function simplify(p, eps) {
			if (p.length < 3) return p
			const keep = new Uint8Array(p.length)
			keep[0] = keep[p.length - 1] = 1
			const stack = [[0, p.length - 1]]
			while (stack.length) {
				const [a, b] = stack.pop()
				let max = 0
				let idx = -1
				for (let i = a + 1; i < b; i++) {
					const d = distSeg(p[i][0], p[i][1], p[a], p[b])
					if (d > max) { max = d; idx = i }
				}
				if (idx >= 0 && max > eps) {
					keep[idx] = 1
					stack.push([a, idx], [idx, b])
				}
			}
			return p.filter((_, i) => keep[i])
		}

		// kompaktes Speicherformat: erster Punkt absolut, danach Differenzen
		function encodeStroke(st) {
			if (!st.d) {
				const d = [st.p[0][0], st.p[0][1]]
				for (let i = 1; i < st.p.length; i++) d.push(st.p[i][0] - st.p[i - 1][0], st.p[i][1] - st.p[i - 1][1])
				st.d = d
			}
			return { c: st.c, s: st.s, t: st.t, d: st.d }
		}

		function decodePoints(st) {
			if (Array.isArray(st.d) && st.d.length >= 2) {
				const p = []
				let x = 0
				let y = 0
				for (let i = 0; i + 1 < st.d.length; i += 2) {
					x += Number(st.d[i]) || 0
					y += Number(st.d[i + 1]) || 0
					p.push([x, y])
				}
				return p
			}
			return Array.isArray(st.p) ? st.p : []
		}

		function distSeg(x, y, a, b) {
			const dx = b[0] - a[0]
			const dy = b[1] - a[1]
			const l = dx * dx + dy * dy
			let t = l ? ((x - a[0]) * dx + (y - a[1]) * dy) / l : 0
			t = Math.max(0, Math.min(1, t))
			return Math.hypot(a[0] + t * dx - x, a[1] + t * dy - y)
		}

		function eraseAt(e) {
			const [x, y] = toLocal(e)
			for (let i = strokes.length - 1; i >= 0; i--) {
				const st = strokes[i]
				const r = 10 + (st.t === 'marker' ? st.s * 2 : st.s / 2)
				const b = st.box || (st.box = boxOf(st.p))
				if (x < b[0] - r || x > b[2] + r || y < b[1] - r || y > b[3] + r) continue
				let hit = st.p.length === 1 && Math.hypot(st.p[0][0] - x, st.p[0][1] - y) <= r
				for (let j = 0; !hit && j < st.p.length - 1; j++) hit = distSeg(x, y, st.p[j], st.p[j + 1]) <= r
				if (hit) {
					strokes.splice(i, 1)
					st.el.remove()
					erasing.removed.push(st)
				}
			}
		}

		// ---------- Rückgängig / Wiederholen ----------
		function pushAction(a) {
			undoStack.push(a)
			if (undoStack.length > 300) undoStack.shift()
			redoStack.length = 0
		}

		function addStrokes(list) {
			for (const st of list) { strokes.push(st); renderStroke(st) }
		}

		function removeStrokes(list) {
			for (const st of list) {
				const i = strokes.indexOf(st)
				if (i >= 0) { strokes.splice(i, 1); st.el.remove() }
			}
		}

		function refreshStroke(st) {
			st.d = null
			st.box = boxOf(st.p)
			st.el.removeAttribute('transform')
			st.el.setAttribute('d', pathData(st.p))
			st.el.setAttribute('stroke-width', String(st.t === 'marker' ? st.s * 4 : st.s))
		}

		function applyAction(a, forward) {
			if (a.type === 'add') (forward ? addStrokes : removeStrokes)([a.st])
			else if (a.type === 'addMany') (forward ? addStrokes : removeStrokes)(a.sts)
			else if (a.type === 'erase') (forward ? removeStrokes : addStrokes)(a.removed)
			else if (a.type === 'transform') {
				for (const it of a.items) {
					const v = forward ? it.after : it.before
					it.st.p = v.p
					it.st.s = v.s
					refreshStroke(it.st)
				}
			}
		}

		function afterHistory() {
			clearSelection()
			updateHint()
			updateSurface()
			changed()
			updateDrawToolbar()
		}

		function undo() {
			if (current || selDrag || lasso) return
			const a = undoStack.pop()
			if (!a) return
			applyAction(a, false)
			redoStack.push(a)
			afterHistory()
		}

		function redo() {
			if (current || selDrag || lasso) return
			const a = redoStack.pop()
			if (!a) return
			applyAction(a, true)
			undoStack.push(a)
			afterHistory()
		}

		// ---------- Auswählen: umkreisen oder antippen, dann verschieben/skalieren ----------
		let sel = null // { sts: [...], box: [x0, y0, x1, y1] }
		let selDrag = null
		let lasso = null
		const selLayer = document.createElementNS(NS, 'g')
		selLayer.setAttribute('class', 'kl-sel-layer')
		const HANDLE_HIT = 26

		const selBar = el('div', { class: 'kl-sel-bar', role: 'toolbar', 'aria-label': 'Auswahl' },
			el('button', { class: 'kl-tb', type: 'button', title: 'Duplizieren', 'aria-label': 'Duplizieren', onclick: () => duplicateSelection() }, svg('copy'), el('span', { class: 'kl-tb-label' }, 'Duplizieren')),
			el('button', { class: 'kl-tb kl-tb-danger', type: 'button', title: 'Löschen', 'aria-label': 'Auswahl löschen', onclick: () => deleteSelection() }, svg('trash'), el('span', { class: 'kl-tb-label' }, 'Löschen')))
		selBar.addEventListener('pointerdown', (e) => e.stopPropagation())

		function strokeHalfWidth(st) {
			return (st.t === 'marker' ? st.s * 4 : st.s) / 2
		}

		function selectionBox(list) {
			let a = Infinity; let b = Infinity; let c = -Infinity; let d = -Infinity
			for (const st of list) {
				const bx = st.box || (st.box = boxOf(st.p))
				const w = strokeHalfWidth(st)
				a = Math.min(a, bx[0] - w); b = Math.min(b, bx[1] - w); c = Math.max(c, bx[2] + w); d = Math.max(d, bx[3] + w)
			}
			const pad = 6
			return [a - pad, b - pad, c + pad, d + pad]
		}

		function corners(box) {
			return [[box[0], box[1]], [box[2], box[1]], [box[2], box[3]], [box[0], box[3]]]
		}

		function svgEl(tag, attrs) {
			const n = document.createElementNS(NS, tag)
			for (const k in attrs) n.setAttribute(k, String(attrs[k]))
			return n
		}

		function drawSelection(box) {
			selLayer.replaceChildren()
			if (!box) { selBar.remove(); return }
			selLayer.append(svgEl('rect', { class: 'kl-sel-rect', x: box[0], y: box[1], width: Math.max(1, box[2] - box[0]), height: Math.max(1, box[3] - box[1]) }))
			for (const [x, y] of corners(box)) selLayer.append(svgEl('circle', { class: 'kl-sel-handle', cx: x, cy: y, r: 8 }))
			ink.append(selLayer)
			if (!selDrag) {
				surface.append(selBar)
				const top = box[1] - 52
				selBar.style.left = Math.max(0, box[0]) + 'px'
				selBar.style.top = (top < 4 ? box[3] + 10 : top) + 'px'
			} else {
				selBar.remove()
			}
		}

		function setSelection(list) {
			if (!list || !list.length) { clearSelection(); return }
			let a = Infinity; let b = Infinity; let c = -Infinity; let d = -Infinity
			for (const st of list) {
				const bx = st.box || (st.box = boxOf(st.p))
				a = Math.min(a, bx[0]); b = Math.min(b, bx[1]); c = Math.max(c, bx[2]); d = Math.max(d, bx[3])
			}
			// raw = reine Strich-Ausdehnung (Bezugspunkt beim Skalieren), box = Rahmen mit Abstand
			sel = { sts: list, box: selectionBox(list), raw: [a, b, c, d] }
			drawSelection(sel.box)
		}

		function clearSelection() {
			sel = null
			selDrag = null
			drawSelection(null)
		}

		function hitStroke(x, y, extra) {
			for (let i = strokes.length - 1; i >= 0; i--) {
				const st = strokes[i]
				const r = extra + strokeHalfWidth(st)
				const b = st.box || (st.box = boxOf(st.p))
				if (x < b[0] - r || x > b[2] + r || y < b[1] - r || y > b[3] + r) continue
				if (st.p.length === 1 && Math.hypot(st.p[0][0] - x, st.p[0][1] - y) <= r) return st
				for (let j = 0; j < st.p.length - 1; j++) if (distSeg(x, y, st.p[j], st.p[j + 1]) <= r) return st
			}
			return null
		}

		function inPolygon(x, y, poly) {
			let inside = false
			for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
				const [xi, yi] = poly[i]
				const [xj, yj] = poly[j]
				if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) inside = !inside
			}
			return inside
		}

		function selectDown(e) {
			const pt = toLocal(e)
			if (sel) {
				const cs = corners(sel.box)
				for (let i = 0; i < 4; i++) {
					if (Math.hypot(cs[i][0] - pt[0], cs[i][1] - pt[1]) <= HANDLE_HIT) {
						const rc = corners(sel.raw)
						selDrag = { id: e.pointerId, kind: 'scale', start: pt, anchor: rc[(i + 2) % 4], corner: rc[i], grab: [pt[0] - rc[i][0], pt[1] - rc[i][1]], k: 1, tx: 0, ty: 0 }
						drawSelection(sel.box)
						return
					}
				}
				const b = sel.box
				if (pt[0] >= b[0] && pt[0] <= b[2] && pt[1] >= b[1] && pt[1] <= b[3]) {
					selDrag = { id: e.pointerId, kind: 'move', start: pt, k: 1, tx: 0, ty: 0 }
					drawSelection(sel.box)
					return
				}
				clearSelection()
			}
			const path = svgEl('path', { class: 'kl-lasso', d: '' })
			ink.append(path)
			lasso = { id: e.pointerId, pts: [pt], el: path }
		}

		function selectMove(e) {
			const pt = toLocal(e)
			if (lasso && lasso.id === e.pointerId) {
				const l = lasso.pts[lasso.pts.length - 1]
				if (Math.abs(pt[0] - l[0]) + Math.abs(pt[1] - l[1]) >= 3) lasso.pts.push(pt)
				lasso.el.setAttribute('d', 'M' + lasso.pts.map((q) => q[0] + ' ' + q[1]).join('L') + 'Z')
				return
			}
			if (!selDrag || selDrag.id !== e.pointerId) return
			const b = sel.box
			if (selDrag.kind === 'move') {
				// nicht über den linken/oberen Rand hinaus
				selDrag.tx = Math.max(-b[0], pt[0] - selDrag.start[0])
				selDrag.ty = Math.max(-b[1], pt[1] - selDrag.start[1])
				selDrag.k = 1
			} else {
				const [ax, ay] = selDrag.anchor
				const cx = selDrag.corner[0] - ax
				const cy = selDrag.corner[1] - ay
				const len = cx * cx + cy * cy || 1
				// Abstand zwischen Finger und Ecke beim Greifen ausgleichen
				const px = pt[0] - selDrag.grab[0]
				const py = pt[1] - selDrag.grab[1]
				const k = ((px - ax) * cx + (py - ay) * cy) / len
				selDrag.k = Math.max(0.1, Math.min(10, k))
				selDrag.tx = ax - selDrag.k * ax
				selDrag.ty = ay - selDrag.k * ay
			}
			const { k, tx, ty } = selDrag
			const m = 'matrix(' + k + ' 0 0 ' + k + ' ' + tx + ' ' + ty + ')'
			for (const st of sel.sts) st.el.setAttribute('transform', m)
			drawSelection([b[0] * k + tx, b[1] * k + ty, b[2] * k + tx, b[3] * k + ty])
		}

		function selectUp(e) {
			if (lasso && lasso.id === e.pointerId) {
				const pts = lasso.pts
				lasso.el.remove()
				lasso = null
				const bx = boxOf(pts)
				if (bx[2] - bx[0] < 8 && bx[3] - bx[1] < 8) {
					// Antippen: einzelnen Strich auswählen
					const st = hitStroke(pts[0][0], pts[0][1], 12)
					setSelection(st ? [st] : [])
					return
				}
				const found = strokes.filter((st) => {
					let inside = 0
					for (const [x, y] of st.p) if (inPolygon(x, y, pts)) inside++
					return inside > 0 && inside >= st.p.length * 0.5
				})
				setSelection(found)
				return
			}
			if (!selDrag || selDrag.id !== e.pointerId) return
			const { k, tx, ty } = selDrag
			selDrag = null
			if (k === 1 && Math.abs(tx) < 1 && Math.abs(ty) < 1) {
				for (const st of sel.sts) st.el.removeAttribute('transform')
				drawSelection(sel.box)
				return
			}
			const items = []
			for (const st of sel.sts) {
				const before = { p: st.p, s: st.s }
				const after = {
					p: st.p.map(([x, y]) => [Math.max(0, Math.round(x * k + tx)), Math.max(0, Math.round(y * k + ty))]),
					s: k === 1 ? st.s : Math.max(1, Math.min(40, Math.round(st.s * k))),
				}
				st.p = after.p
				st.s = after.s
				refreshStroke(st)
				items.push({ st, before, after })
			}
			pushAction({ type: 'transform', items })
			setSelection(sel.sts)
			updateSurface()
			changed()
			updateDrawToolbar()
		}

		function deleteSelection() {
			if (!sel) return
			const list = sel.sts
			removeStrokes(list)
			pushAction({ type: 'erase', removed: list })
			clearSelection()
			updateHint()
			changed()
			updateDrawToolbar()
		}

		function duplicateSelection() {
			if (!sel) return
			const copies = sel.sts.map((st) => ({ c: st.c, s: st.s, t: st.t, p: st.p.map(([x, y]) => [x + 24, y + 24]) }))
			addStrokes(copies)
			for (const st of copies) st.box = boxOf(st.p)
			pushAction({ type: 'addMany', sts: copies })
			setSelection(copies)
			updateHint()
			updateSurface()
			changed()
			updateDrawToolbar()
		}

		function selectAll() {
			setSelection(strokes.slice())
		}

		function touches() {
			return [...activePointers.values()].filter((p) => p.type === 'touch')
		}

		function centroid(ps) {
			if (!ps.length) return null
			return { x: ps.reduce((a, p) => a + p.x, 0) / ps.length, y: ps.reduce((a, p) => a + p.y, 0) / ps.length }
		}

		function onInkDown(e, penInTextMode) {
			if (readOnly) return
			if (e.pointerType === 'pen') penSeen = true
			activePointers.set(e.pointerId, { x: e.clientX, y: e.clientY, type: e.pointerType })
			const t = touches()
			// Finger scrollt, sobald ein Stift benutzt wird (Handballen) oder mit zwei Fingern
			if (e.pointerType === 'touch' && (penSeen || t.length >= 2)) {
				cancelStroke()
				erasing = null
				if (lasso) { lasso.el.remove(); lasso = null }
				panLast = centroid(t)
				e.preventDefault()
				return
			}
			if (e.button !== undefined && e.button > 0) return
			e.preventDefault()
			// Tastatur schließen (z. B. noch aktiver Seitentitel), damit sie beim Zeichnen nicht stört
			const ae = document.activeElement
			if (ae && ae !== document.body && (ae.matches('input, textarea') || ae.isContentEditable)) ae.blur()
			try { e.target.setPointerCapture(e.pointerId) } catch (err) { /* egal */ }
			const kind = penInTextMode ? (tool.kind === 'marker' ? 'marker' : 'pen') : tool.kind
			if (kind === 'select') {
				selectDown(e)
			} else if (kind === 'eraser') {
				erasing = { id: e.pointerId, removed: [] }
				eraseAt(e)
			} else {
				startStroke(e, kind)
			}
		}

		const onInkMove = (e) => {
			const ap = activePointers.get(e.pointerId)
			if (!ap) return
			ap.x = e.clientX
			ap.y = e.clientY
			if (panLast && e.pointerType === 'touch') {
				const c = centroid(touches())
				if (c) {
					canvasEl.scrollLeft -= c.x - panLast.x
					canvasEl.scrollTop -= c.y - panLast.y
					panLast = c
				}
				return
			}
			if (current && current.id === e.pointerId) extendStroke(e)
			else if (erasing && erasing.id === e.pointerId) eraseAt(e)
			else if (lasso || selDrag) selectMove(e)
		}

		const onInkUp = (e) => {
			if (!activePointers.has(e.pointerId)) return
			activePointers.delete(e.pointerId)
			if (current && current.id === e.pointerId) endStroke()
			if (lasso || selDrag) selectUp(e)
			if (erasing && erasing.id === e.pointerId) {
				if (erasing.removed.length) {
					pushAction({ type: 'erase', removed: erasing.removed })
					updateHint()
					changed()
					updateDrawToolbar()
				}
				erasing = null
			}
			const t = touches()
			panLast = t.length ? centroid(t) : null
		}

		capture.addEventListener('pointerdown', (e) => onInkDown(e, false))
		window.addEventListener('pointermove', onInkMove)
		window.addEventListener('pointerup', onInkUp)
		window.addEventListener('pointercancel', onInkUp)
		// Safari: Pencil soll auf der freien Fläche nicht scrollen, sondern zeichnen
		const onTouchStart = (e) => {
			if (readOnly || mode !== 'text' || penText) return
			const stylus = [...e.changedTouches].some((t) => t.touchType === 'stylus')
			if (stylus && (e.target === surface || e.target === hint || e.target === ink)) e.preventDefault()
		}
		surface.addEventListener('touchstart', onTouchStart, { passive: false })
		const onUndoKey = (e) => {
			// in Textfeldern übernimmt der Browser Strg+Z selbst
			if (e.target.closest('input, textarea, [contenteditable="true"]')) return
			const key = e.key.toLowerCase()
			if ((e.ctrlKey || e.metaKey) && key === 'z') {
				e.preventDefault()
				if (e.shiftKey) redo()
				else undo()
			} else if ((e.ctrlKey || e.metaKey) && key === 'y') {
				e.preventDefault()
				redo()
			} else if ((e.ctrlKey || e.metaKey) && key === 'a' && mode === 'draw' && tool.kind === 'select') {
				e.preventDefault()
				selectAll()
			} else if (sel && (key === 'delete' || key === 'backspace')) {
				e.preventDefault()
				deleteSelection()
			} else if (sel && key === 'escape') {
				clearSelection()
			}
		}
		document.addEventListener('keydown', onUndoKey)
		cleanups.push(() => {
			window.removeEventListener('pointermove', onInkMove)
			window.removeEventListener('pointerup', onInkUp)
			window.removeEventListener('pointercancel', onInkUp)
			surface.removeEventListener('touchstart', onTouchStart)
			document.removeEventListener('keydown', onUndoKey)
		})

		function setMode(m) {
			if (readOnly) m = 'text'
			clearSelection()
			mode = m
			if (mode === 'draw') {
				const ae = document.activeElement
				if (ae && ae !== document.body && (ae.matches('input, textarea') || ae.isContentEditable)) ae.blur()
				setActive(null)
				surface.append(capture)
				capture.classList.toggle('kl-selecting', tool.kind === 'select')
			} else {
				capture.remove()
			}
			surface.classList.toggle('kl-drawing', mode === 'draw')
			saveTool()
			renderToolbar()
			if (mode === 'text') ensurePad()
			else removePad()
		}

		function modeSwitch() {
			const seg = (m, label, iconName) => el('button', {
				class: 'kl-seg-btn' + (mode === m ? ' on' : ''),
				type: 'button',
				'aria-pressed': mode === m ? 'true' : 'false',
				title: m === 'draw' ? 'Mit Finger oder Stift schreiben und zeichnen' : 'Textfelder schreiben (Tastatur)',
				onclick: () => setMode(m),
			}, svg(iconName), el('span', { class: 'kl-seg-label' }, label))
			return el('div', { class: 'kl-seg', role: 'group', 'aria-label': 'Modus' }, seg('text', 'Text', 'cursor'), seg('draw', 'Zeichnen', 'pen'))
		}

		let drawButtons = []
		function buildDrawBar() {
			drawButtons = []
			const bar = el('div', { class: 'kl-toolbar-inner', role: 'toolbar', 'aria-label': 'Zeichnen' })
			const add = (b, isOn) => { b._isOn = isOn; drawButtons.push(b); bar.append(b) }
			add(el('button', {
				class: 'kl-tb', type: 'button', title: 'Auswählen: Gezeichnetes umkreisen oder antippen, dann verschieben oder an den Ecken größer/kleiner ziehen', 'aria-label': 'Auswählen',
				onclick: () => setTool({ kind: 'select' }),
			}, svg('lasso'), el('span', { class: 'kl-tb-label' }, 'Auswählen')), () => tool.kind === 'select')
			bar.append(el('span', { class: 'kl-tb-sep' }))
			for (const col of INK_COLORS) {
				add(el('button', {
					class: 'kl-tb kl-tb-ink', type: 'button', title: 'Stift ' + col.n, 'aria-label': 'Stift ' + col.n,
					onclick: () => setTool({ kind: 'pen', color: col.c }),
				}, el('span', { class: 'kl-ink-dot', style: { background: col.c === 'auto' ? 'var(--color-main-text, #222)' : col.c } })),
				() => tool.kind === 'pen' && tool.color === col.c)
			}
			for (const mk of MARKERS) {
				add(el('button', {
					class: 'kl-tb kl-tb-marker', type: 'button', title: mk.n, 'aria-label': mk.n,
					onclick: () => setTool({ kind: 'marker', markerColor: mk.c }),
				}, el('span', { class: 'kl-marker-chip', style: { background: mk.c } })),
				() => tool.kind === 'marker' && tool.markerColor === mk.c)
			}
			bar.append(el('span', { class: 'kl-tb-sep' }))
			for (const sz of SIZES) {
				add(el('button', {
					class: 'kl-tb', type: 'button', title: 'Stärke ' + sz.n, 'aria-label': 'Stärke ' + sz.n,
					onclick: () => setTool({ size: sz.s, kind: tool.kind === 'eraser' || tool.kind === 'select' ? 'pen' : tool.kind }),
				}, el('span', { class: 'kl-size-dot', style: { width: (sz.s + 3) + 'px', height: (sz.s + 3) + 'px' } })),
				() => tool.size === sz.s && tool.kind !== 'eraser' && tool.kind !== 'select')
			}
			bar.append(el('span', { class: 'kl-tb-sep' }))
			add(el('button', {
				class: 'kl-tb', type: 'button', title: 'Radierer (ganze Striche)', 'aria-label': 'Radierer',
				onclick: () => setTool({ kind: 'eraser' }),
			}, svg('eraser')), () => tool.kind === 'eraser')
			bar.append(el('span', { class: 'kl-tb-spacer' }))
			bar.append(el('span', { class: 'kl-draw-hint' }, 'Zwei Finger zum Scrollen'))
			return bar
		}

		function setTool(change) {
			Object.assign(tool, change)
			if (tool.kind !== 'select') clearSelection()
			capture.classList.toggle('kl-selecting', tool.kind === 'select')
			saveTool()
			updateDrawToolbar()
		}

		// Rückgängig/Wiederholen sitzen fest neben dem Umschalter und scrollen nicht weg
		let historyButtons = null
		// Im Textmodus wirken die Pfeile auf das gerade bearbeitete Textfeld, sonst auf Gezeichnetes
		function editingText() {
			if (mode !== 'text' || !active || isImage(active)) return null
			const body = bodyOf(active)
			return body && body.contains(document.activeElement) ? body : null
		}

		function historyStep(back) {
			if (editingText()) {
				try { document.execCommand(back ? 'undo' : 'redo') } catch (e) { /* egal */ }
				return
			}
			if (back) undo()
			else redo()
		}

		function historyGroup() {
			const u = el('button', { class: 'kl-tb', type: 'button', title: 'Rückgängig (Strg+Z)', 'aria-label': 'Rückgängig', onclick: () => historyStep(true) }, svg('undo'))
			const r = el('button', { class: 'kl-tb', type: 'button', title: 'Wiederholen (Strg+Umschalt+Z)', 'aria-label': 'Wiederholen', onclick: () => historyStep(false) }, svg('redo'))
			historyButtons = [u, r]
			return el('div', { class: 'kl-history', role: 'group', 'aria-label': 'Verlauf' }, u, r)
		}

		function updateDrawToolbar() {
			for (const b of drawButtons) b.classList.toggle('on', !!b._isOn())
			if (historyButtons) {
				const text = !!editingText()
				historyButtons[0].setAttribute('aria-disabled', text || undoStack.length ? 'false' : 'true')
				historyButtons[1].setAttribute('aria-disabled', text || redoStack.length ? 'false' : 'true')
			}
		}

		// ---------- Stift → Text (Apple Scribble) ----------
		// Scribble wandelt Handschrift nur in vorhandenen Textfeldern um. Deshalb liegt in diesem
		// Modus immer ein leeres, großes Schreibfeld bereit, in das man direkt mit dem Pencil schreibt.

		let penText = false
		try { penText = localStorage.getItem('kladory.penText') === '1' } catch (e) { /* egal */ }

		function padNode() {
			return surface.querySelector('.kl-pad')
		}

		// Ziel-Textfeld, in das der erkannte Text des Schreibfelds wandert
		let padTarget = null
		let padParagraph = false
		let padTimer = null
		let padPenDown = false
		const PAD_GAP = 14

		function padWidth(x) {
			return Math.max(280, Math.min(900, canvasEl.clientWidth - x - 24))
		}

		function placePadBelow(node) {
			const pad = padNode()
			if (!pad || !node) return
			pad.style.left = node.offsetLeft + 'px'
			pad.style.top = (node.offsetTop + node.offsetHeight + PAD_GAP) + 'px'
			pad.style.width = padWidth(node.offsetLeft) + 'px'
			updateSurface()
		}

		function schedulePadCommit() {
			clearTimeout(padTimer)
			padTimer = setTimeout(() => {
				// solange der Stift aufliegt, weiter warten
				if (padPenDown) { schedulePadCommit(); return }
				commitPad()
			}, 1300)
		}

		/** Erkannten Text aus dem Schreibfeld in das Ziel-Textfeld übernehmen und das Feld leeren. */
		function commitPad() {
			clearTimeout(padTimer)
			const pad = padNode()
			if (!pad) return
			const body = bodyOf(pad)
			const text = (body.innerText || '').replace(/\u00a0/g, ' ').split(/\n+/).map((l) => l.replace(/\s+/g, ' ').trim()).filter(Boolean)
			if (!text.length) {
				if (body.innerHTML !== '') body.innerHTML = ''
				return
			}
			if (!padTarget || !padTarget.isConnected) {
				padTarget = createTextBlock({ id: uid(), x: pad.offsetLeft, y: pad.offsetTop, w: pad.offsetWidth, html: '' })
				padParagraph = false
			}
			const tb = bodyOf(padTarget)
			text.forEach((line, i) => {
				const newPara = isEmpty(tb) ? false : (padParagraph || i > 0)
				if (isEmpty(tb)) {
					tb.textContent = line
				} else if (newPara) {
					tb.append(el('div', {}, line))
				} else {
					const last = tb.lastElementChild && /^(DIV|P|LI|H[1-3])$/.test(tb.lastElementChild.tagName) ? tb.lastElementChild : tb
					const prev = last.textContent
					last.append(document.createTextNode((prev && !/\s$/.test(prev) ? ' ' : '') + line))
				}
			})
			padParagraph = false
			const hadFocus = pad.contains(document.activeElement)
			body.innerHTML = ''
			if (hadFocus) {
				// Schreibmarke im (jetzt leeren) Schreibfeld lassen, damit Scribble weiterschreibt
				const r = document.createRange()
				r.selectNodeContents(body)
				r.collapse(true)
				const sel = window.getSelection()
				sel.removeAllRanges()
				sel.addRange(r)
			}
			placePadBelow(padTarget)
			updateHint()
			changed()
		}

		function padButton(label, title, onUse) {
			const b = el('button', { class: 'kl-pad-btn', type: 'button', title, contenteditable: 'false' }, label)
			// Fokus im Schreibfeld behalten (sonst schließt/öffnet sich die Tastatur)
			b.addEventListener('pointerdown', (e) => { e.preventDefault(); e.stopPropagation() })
			b.addEventListener('mousedown', (e) => e.preventDefault())
			b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); onUse() })
			return b
		}

		function ensurePad() {
			if (readOnly || !penText || mode !== 'text') { removePad(); return }
			let pad = padNode()
			if (!pad) {
				const y = nextFreeY()
				pad = createTextBlock({ id: uid(), x: 24, y, w: padWidth(24), html: '' })
				pad.classList.add('kl-pad')
				const body = bodyOf(pad)
				body.setAttribute('aria-label', 'Schreibfeld für den Apple Pencil')
				body.addEventListener('keydown', (e) => {
					if (e.key === 'Enter') {
						e.preventDefault()
						e.stopImmediatePropagation()
						commitPad()
						padParagraph = true
					}
				}, true)
				body.addEventListener('pointerdown', (e) => { if (e.pointerType === 'pen') padPenDown = true })
				const up = () => { padPenDown = false }
				window.addEventListener('pointerup', up)
				window.addEventListener('pointercancel', up)
				cleanups.push(() => { window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up) })
				pad.append(el('div', { class: 'kl-pad-bar', contenteditable: 'false' },
					padButton('↵ Neuer Absatz', 'Nächster Text beginnt in einer neuen Zeile', () => { commitPad(); padParagraph = true }),
					padButton('＋ Neues Textfeld', 'Nächster Text kommt in ein eigenes, neues Textfeld', () => {
						commitPad()
						padTarget = null
						const pd = padNode()
						if (pd) { pd.style.top = (pd.offsetTop + 24) + 'px'; updateSurface() }
					})))
			} else if (!pad.contains(document.activeElement) && !padTarget) {
				pad.style.left = '24px'
				pad.style.top = nextFreeY() + 'px'
				pad.style.width = padWidth(24) + 'px'
			}
			updateHint()
			updateSurface()
		}

		function removePad() {
			const pad = padNode()
			if (pad && !pad.contains(document.activeElement)) {
				commitPad()
				pad.remove()
				padTarget = null
				updateHint()
			}
		}

		// Stift tippt auf eine freie Stelle: dort beginnt ein neues Textfeld
		function movePadTo(x, y) {
			commitPad()
			if (!padNode()) ensurePad()
			const pad = padNode()
			if (!pad) return
			padTarget = null
			padParagraph = false
			// am rechten Rand so weit nach links rücken, dass das Feld ganz sichtbar bleibt
			x = Math.max(0, Math.min(x, canvasEl.clientWidth - 280 - 16))
			pad.style.left = x + 'px'
			pad.style.top = y + 'px'
			pad.style.width = padWidth(x) + 'px'
			updateSurface()
		}

		// ---------- Laden ----------

		for (const b of data.blocks) {
			if (!b) continue
			if ((b.type || 'text') === 'text') createTextBlock(b)
			else if (b.type === 'image' && b.src) createImageBlock(b)
			else if (b.type === 'ink' && Array.isArray(b.strokes)) {
				for (const st of b.strokes) {
					const pts = st ? decodePoints(st) : []
					if (!pts.length) continue
					const stroke = { c: st.c || 'auto', s: Number(st.s) || 3, t: st.t === 'marker' ? 'marker' : 'pen', p: pts }
					stroke.box = boxOf(stroke.p)
					strokes.push(stroke)
					renderStroke(stroke)
				}
			}
		}
		updateHint()
		setMode(mode)
		requestAnimationFrame(updateSurface)

		return {
			serialize() {
				commitPad()
				const blocks = []
				for (const node of surface.querySelectorAll('.kl-block')) {
					if (node.classList.contains('kl-pad')) continue
					if (isImage(node)) {
						if (!node.dataset.src) continue // Upload läuft noch
						blocks.push({
							id: node.dataset.id,
							type: 'image',
							x: node.offsetLeft,
							y: node.offsetTop,
							w: node.offsetWidth,
							src: node.dataset.src,
						})
						continue
					}
					const body = bodyOf(node)
					if (isEmpty(body)) continue
					blocks.push({
						id: node.dataset.id,
						type: 'text',
						x: node.offsetLeft,
						y: node.offsetTop,
						w: node.offsetWidth,
						html: sanitizeHtml(body.innerHTML),
					})
				}
				if (strokes.some((st) => !current || st !== current.st)) {
					blocks.push({
						id: 'ink',
						type: 'ink',
						x: 0,
						y: 0,
						w: 40,
						// ein gerade entstehender Strich wird erst nach dem Absetzen gespeichert
						strokes: strokes.filter((st) => !current || st !== current.st).map(encodeStroke),
					})
				}
				return JSON.stringify({ version: 1, blocks })
			},
			destroy() {
				closePop()
				for (const fn of cleanups) fn()
				surface.removeEventListener('pointerdown', onSurfaceDown)
				surface.removeEventListener('click', onSurfaceClick)
				surface.removeEventListener('dragover', onDragOver)
				surface.removeEventListener('dragleave', onDragLeave)
				surface.removeEventListener('drop', onDrop)
			},
		}
	}

	window.KladoryEditor = { mount, sanitizeHtml }
})()
