#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Kladory contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""
Kladory-Lasttest – simuliert eine Klasse, die gleichzeitig mit dem Stift schreibt.

Jeder simulierte Schüler bekommt eine eigene Seite in einem Test-Notizbuch und
schreibt dort "Wort für Wort" mit dem Stift. Gespeichert wird genau so wie im
Browser (komprimiert, alle paar Sekunden). Nebenbei werden ab und zu der
Notizbuch-Baum geladen, Seiten geöffnet und gesucht.

Nur Python 3 nötig (keine Zusatzpakete). Beispiel:

  python3 kladory-lasttest.py --url https://cloud.schule.de --user lasttest \\
      --password 'APP-PASSWORT' --clients 35 --minutes 3

Mehrere echte Konten (eine Zeile pro Konto: benutzer:app-passwort):

  python3 kladory-lasttest.py --url https://cloud.schule.de --accounts konten.txt --clients 35

App-Passwort anlegen: Nextcloud → Persönliche Einstellungen → Sicherheit →
"Neues App-Passwort erstellen". Am Ende wird das Test-Notizbuch endgültig
gelöscht (mit --keep bleibt es zum Anschauen erhalten).
"""

import argparse
import base64
import gzip
import http.cookiejar
import json
import math
import random
import ssl
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request

API = '/index.php/apps/kladory/api'


class Client:
    """Eine Browser-Sitzung (eigene Cookies, eigener CSRF-Token)."""

    def __init__(self, base, user, password, insecure):
        self.base = base.rstrip('/')
        self.user = user
        self.auth = 'Basic ' + base64.b64encode(f'{user}:{password}'.encode()).decode()
        self.token = None
        ctx = ssl.create_default_context()
        if insecure:
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            urllib.request.HTTPSHandler(context=ctx))

    def _fetch_token(self):
        req = urllib.request.Request(self.base + '/index.php/csrftoken',
                                     headers={'Authorization': self.auth, 'Accept': 'application/json'})
        with self.opener.open(req, timeout=60) as r:
            self.token = json.loads(r.read())['token']

    def call(self, method, path, body=None, raw=None, headers=None, retry=True):
        """Gibt (status, daten, gesendete_bytes, empfangene_bytes, sekunden) zurück."""
        h = {
            'Authorization': self.auth,
            'Accept': 'application/json',
            'OCS-APIRequest': 'true',
        }
        if self.token:
            h['requesttoken'] = self.token
        data = None
        if raw is not None:
            data = raw
        elif body is not None:
            data = json.dumps(body).encode()
            h['Content-Type'] = 'application/json'
        h.update(headers or {})
        req = urllib.request.Request(self.base + API + path, data=data, method=method, headers=h)
        t0 = time.perf_counter()
        try:
            with self.opener.open(req, timeout=120) as r:
                payload = r.read()
                status = r.status
        except urllib.error.HTTPError as e:
            payload = e.read()
            status = e.code
        except Exception as e:  # Netzwerkfehler, Zeitüberschreitung
            return 0, str(e), len(data or b''), 0, time.perf_counter() - t0
        dt = time.perf_counter() - t0
        # CSRF-Prüfung verlangt einen Token → einmal holen und wiederholen
        if status == 412 and retry:
            self._fetch_token()
            return self.call(method, path, body, raw, headers, retry=False)
        try:
            parsed = json.loads(payload) if payload else None
        except ValueError:
            parsed = None
        return status, parsed, len(data or b''), len(payload), dt


class Stats:
    def __init__(self):
        self.lock = threading.Lock()
        self.rows = {}  # op -> list of (status, sekunden, gesendet, empfangen)
        self.errors = []

    def add(self, op, status, dt, sent, recv, detail=None):
        with self.lock:
            self.rows.setdefault(op, []).append((status, dt, sent, recv))
            if not (200 <= status < 300):
                self.errors.append(f'{op}: HTTP {status} {str(detail)[:120] if detail else ""}')


def word_stroke(ox, oy, rnd):
    """Ein handschriftähnlicher Strich, fein abgetastet wie ein Apple Pencil (≈ 240 Punkte/s)."""
    pts = []
    n = rnd.randint(40, 110)
    a, b = rnd.uniform(3, 6), rnd.uniform(1.4, 2.2)
    for i in range(n):
        pts.append((round(ox + i * 0.9), round(oy + 11 * math.sin(i / a) + 5 * math.sin(i / b))))
    # wie im Browser: Mindestabstand 2 px, dann verdichten (Ramer-Douglas-Peucker, 0,75 px)
    thin = [pts[0]]
    for p in pts[1:]:
        if abs(p[0] - thin[-1][0]) + abs(p[1] - thin[-1][1]) >= 2:
            thin.append(p)
    pts = simplify(thin, 0.75)
    d = [pts[0][0], pts[0][1]]
    for i in range(1, len(pts)):
        d += [pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]]
    return {'c': 'auto', 's': 3, 't': 'pen', 'd': d}


def simplify(p, eps):
    if len(p) < 3:
        return p
    keep = [False] * len(p)
    keep[0] = keep[-1] = True
    stack = [(0, len(p) - 1)]
    while stack:
        a, b = stack.pop()
        mx, idx = 0, -1
        ax, ay = p[a]
        bx, by = p[b]
        dx, dy = bx - ax, by - ay
        ll = dx * dx + dy * dy
        for i in range(a + 1, b):
            x, y = p[i]
            t = max(0, min(1, ((x - ax) * dx + (y - ay) * dy) / ll)) if ll else 0
            dist = math.hypot(ax + t * dx - x, ay + t * dy - y)
            if dist > mx:
                mx, idx = dist, i
        if idx >= 0 and mx > eps:
            keep[idx] = True
            stack += [(a, idx), (idx, b)]
    return [q for q, k in zip(p, keep) if k]


def student(n, client, section_id, args, stats, stop_at, start_barrier):
    rnd = random.Random(n)
    st, page, s, r, dt = client.call('POST', f'/sections/{section_id}/pages', {'title': f'Schüler {n + 1}'})
    stats.add('Seite anlegen', st, dt, s, r, page)
    if st not in (200, 201) or not page:
        return
    pid, rev = page['id'], page.get('revision', 0)
    strokes = []
    x, y = 40, 60
    start_barrier.wait()
    next_tree = time.time() + rnd.uniform(5, 30)
    while time.time() < stop_at:
        # 2–4 s schreiben (ein paar Wörter), dann speichert der Browser
        for _ in range(rnd.randint(3, 7)):
            strokes.append(word_stroke(x, y, rnd))
            x += rnd.randint(55, 95)
            if x > 900:
                x, y = 40, y + 34
                if y > 1400:  # Seite voll → neue Seite wie ein Schüler
                    st, page, s, r, dt = client.call('POST', f'/sections/{section_id}/pages', {'title': f'Schüler {n + 1} (Forts.)'})
                    stats.add('Seite anlegen', st, dt, s, r, page)
                    if st in (200, 201) and page:
                        pid, rev, strokes, y = page['id'], 0, [], 60
        time.sleep(rnd.uniform(2.0, 4.0) * args.pace)
        content = json.dumps({'version': 1, 'blocks': [{'id': 'ink', 'type': 'ink', 'x': 0, 'y': 0, 'w': 40, 'strokes': strokes}]})
        body = json.dumps({'title': f'Schüler {n + 1}', 'content': content, 'revision': rev}).encode()
        packed = gzip.compress(body, 6)
        st, res, s, r, dt = client.call('POST', f'/pages/{pid}/save', raw=packed,
                                        headers={'Content-Type': 'application/octet-stream', 'X-Kladory-Encoding': 'gzip'})
        stats.add('Seite speichern', st, dt, s, r, res)
        if st == 200 and res:
            rev = res['revision']
        elif st == 409 and res and res.get('page'):
            rev = res['page']['revision']
        if time.time() > next_tree:
            next_tree = time.time() + rnd.uniform(20, 40)
            st, res, s, r, dt = client.call('GET', '/tree')
            stats.add('Notizbücher laden', st, dt, s, r, res)
            st, res, s, r, dt = client.call('GET', f'/pages/{pid}')
            stats.add('Seite öffnen', st, dt, s, r, res)
            if rnd.random() < 0.3:
                st, res, s, r, dt = client.call('GET', '/search?q=Sch%C3%BCler')
                stats.add('Suche', st, dt, s, r, res)


def pct(values, p):
    if not values:
        return 0
    values = sorted(values)
    k = min(len(values) - 1, max(0, round(p / 100 * (len(values) - 1))))
    return values[k]


def main():
    ap = argparse.ArgumentParser(description='Kladory-Lasttest: simulierte Klasse schreibt gleichzeitig mit dem Stift.')
    ap.add_argument('--url', required=True, help='Nextcloud-Adresse, z. B. https://cloud.schule.de')
    ap.add_argument('--user', help='Benutzername (wenn alle Clients ein Konto teilen)')
    ap.add_argument('--password', help='App-Passwort zu --user')
    ap.add_argument('--accounts', help='Datei mit Zeilen benutzer:app-passwort (Clients werden verteilt)')
    ap.add_argument('--clients', type=int, default=30, help='gleichzeitige Schüler (Standard 30)')
    ap.add_argument('--minutes', type=float, default=2, help='Dauer in Minuten (Standard 2)')
    ap.add_argument('--pace', type=float, default=1.0, help='Tempo: 0.5 = doppelt so oft speichern (härter)')
    ap.add_argument('--keep', action='store_true', help='Test-Notizbuch nicht löschen')
    ap.add_argument('--insecure', action='store_true', help='TLS-Zertifikat nicht prüfen (nur Testsysteme)')
    args = ap.parse_args()

    accounts = []
    if args.accounts:
        for line in open(args.accounts, encoding='utf-8'):
            line = line.strip()
            if line and not line.startswith('#') and ':' in line:
                u, p = line.split(':', 1)
                accounts.append((u.strip(), p.strip()))
    elif args.user and args.password:
        accounts = [(args.user, args.password)]
    if not accounts:
        sys.exit('Bitte --user/--password oder --accounts angeben.')

    stats = Stats()
    # Pro Konto ein Test-Notizbuch
    setup = {}
    for u, p in accounts:
        c = Client(args.url, u, p, args.insecure)
        st, nb, s, r, dt = c.call('POST', '/notebooks', {'name': 'Kladory-Lasttest ' + time.strftime('%H:%M')})
        if st not in (200, 201) or not nb:
            sys.exit(f'Anmeldung/Notizbuch für {u} fehlgeschlagen: HTTP {st} {nb}')
        setup[u] = (p, nb['id'], nb['sections'][0]['id'])
    print(f'Start: {args.clients} Schüler, {len(accounts)} Konto/Konten, {args.minutes} min …')

    stop_at = time.time() + args.minutes * 60
    barrier = threading.Barrier(args.clients)
    threads = []
    for n in range(args.clients):
        u, _ = accounts[n % len(accounts)]
        p, _, sec = setup[u]
        c = Client(args.url, u, p, args.insecure)
        t = threading.Thread(target=student, args=(n, c, sec, args, stats, stop_at, barrier), daemon=True)
        t.start()
        threads.append(t)
    t_start = time.time()
    while any(t.is_alive() for t in threads):
        time.sleep(5)
        done = sum(len(v) for v in stats.rows.values())
        left = max(0, int(stop_at - time.time()))
        print(f'  … {done} Anfragen, {len(stats.errors)} Fehler, noch {left} s', flush=True)
    elapsed = time.time() - t_start

    print('\nErgebnis')
    print(f'{"Vorgang":<20}{"Anzahl":>8}{"Fehler":>8}{"Median":>10}{"95 %":>10}{"Max":>10}{"Ø Upload":>11}')
    verdict = True
    for op, rows in stats.rows.items():
        ok = [r[1] for r in rows if 200 <= r[0] < 300]
        err = len(rows) - len(ok)
        up = statistics.mean(r[2] for r in rows) / 1024 if rows else 0
        print(f'{op:<20}{len(rows):>8}{err:>8}{pct(ok, 50) * 1000:>8.0f}ms{pct(ok, 95) * 1000:>8.0f}ms{(max(ok) if ok else 0) * 1000:>8.0f}ms{up:>9.1f}KB')
        if op == 'Seite speichern' and (pct(ok, 95) > 2.0 or err > len(rows) * 0.01):
            verdict = False
    total = sum(len(v) for v in stats.rows.values())
    print(f'\n{total} Anfragen in {elapsed:.0f} s ({total / max(elapsed, 1):.1f}/s)')
    if stats.errors:
        print('Erste Fehler:')
        for e in stats.errors[:8]:
            print('  ' + e)
    print('\nBewertung:', 'GUT – Speichern bleibt auch unter Last flott.' if verdict
          else 'KRITISCH – Speichern zu langsam oder zu viele Fehler (Server/Datenbank prüfen).')

    if not args.keep:
        for u, (p, nb_id, _) in setup.items():
            c = Client(args.url, u, p, args.insecure)
            c.call('DELETE', f'/notebooks/{nb_id}')
            c.call('DELETE', f'/trash/notebook/{nb_id}')
        print('Test-Notizbücher wurden endgültig gelöscht.')


if __name__ == '__main__':
    main()
