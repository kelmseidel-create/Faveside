'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const root = __dirname;
const dataDir = process.env.DATA_DIR || path.join(root, 'data');
const storeFile = path.join(dataDir, 'faveside-node.json');
const secretFile = path.join(dataDir, '.session-secret');
const port = Number(process.env.PORT) || 3000;
fs.mkdirSync(dataDir, { recursive: true, mode: 0o750 });

function secret() {
  if (process.env.SESSION_SECRET) return process.env.SESSION_SECRET;
  try { return fs.readFileSync(secretFile, 'utf8').trim(); } catch {}
  const value = crypto.randomBytes(48).toString('base64url');
  fs.writeFileSync(secretFile, value, { mode: 0o600 });
  return value;
}
const sessionSecret = secret();
const blank = () => ({ nextUserId: 1, nextProfileId: 1, users: [], states: {}, profiles: [], approvals: {}, redemptions: {} });
function load() { try { return { ...blank(), ...JSON.parse(fs.readFileSync(storeFile, 'utf8')) }; } catch { return blank(); } }
function save(data) { const temp = `${storeFile}.${process.pid}.tmp`; fs.writeFileSync(temp, JSON.stringify(data), { mode: 0o600 }); fs.renameSync(temp, storeFile); }
const securityHeaders = { 'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'SAMEORIGIN', 'Referrer-Policy': 'strict-origin-when-cross-origin', 'Permissions-Policy': 'camera=(), microphone=(), geolocation=()' };
function reply(res, status, body, headers = {}) { res.writeHead(status, { ...securityHeaders, 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers }); res.end(JSON.stringify(body)); }
function sign(value) { return crypto.createHmac('sha256', sessionSecret).update(value).digest('base64url'); }
function cookieMap(req) { return Object.fromEntries((req.headers.cookie || '').split(';').map(v => v.trim()).filter(Boolean).map(v => { const i = v.indexOf('='); return [v.slice(0, i), decodeURIComponent(v.slice(i + 1))]; })); }
function sessionId(req) {
  const token = cookieMap(req).faveside_session; if (!token) return null;
  const [id, expires, signature] = token.split('.'); const expected = sign(`${id}.${expires}`);
  if (!signature || signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected)) || Number(expires) < Date.now() / 1000) return null;
  return Number(id) || null;
}
function newCookie(id, req) { const expires = Math.floor(Date.now() / 1000) + 2592000; const value = `${id}.${expires}`; const secure = String(req.headers['x-forwarded-proto'] || '').includes('https'); return `faveside_session=${value}.${sign(value)}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}`; }
function publicUser(u) { return u ? { id: u.id, email: u.email, name: u.name, entitlement: u.entitlement } : null; }
function current(req, res, db) { const user = db.users.find(u => u.id === sessionId(req)); if (!user) reply(res, 401, { ok: false, error: 'Please sign in.' }); return user; }
function body(req, limit = 300000) { return new Promise((resolve, reject) => { let raw = ''; req.setEncoding('utf8'); req.on('data', c => { raw += c; if (raw.length > limit) reject(Object.assign(new Error('Request is too large.'), { status: 413 })); }); req.on('end', () => { try { resolve((req.headers['content-type'] || '').includes('application/json') ? (raw ? JSON.parse(raw) : {}) : Object.fromEntries(new URLSearchParams(raw))); } catch { reject(Object.assign(new Error('Invalid request.'), { status: 400 })); } }); req.on('error', reject); }); }
function passwordHash(password) { const salt = crypto.randomBytes(16); return `scrypt$${salt.toString('base64url')}$${crypto.scryptSync(password, salt, 64).toString('base64url')}`; }
function passwordOK(password, encoded) { const [kind, salt, hash] = String(encoded).split('$'); if (kind !== 'scrypt' || !salt || !hash) return false; const expected = Buffer.from(hash, 'base64url'); return crypto.timingSafeEqual(expected, crypto.scryptSync(password, Buffer.from(salt, 'base64url'), expected.length)); }
function creatorKey(c) { const handle = String(c.handle || '').trim().toLowerCase(); return handle ? `h:${handle}` : `n:${String(c.name || '').trim().toLowerCase()}`; }

async function account(req, res, url) {
  const action = url.searchParams.get('action') || 'me'; const db = load();
  if (action === 'register') {
    if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' });
    const input = await body(req); const email = String(input.email || '').trim().toLowerCase(); const password = String(input.password || ''); const name = String(input.name || '').trim().replace(/\s+/g, ' ');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 254) return reply(res, 422, { ok: false, error: 'Enter a valid email address.' });
    if (password.length < 10) return reply(res, 422, { ok: false, error: 'Use at least 10 characters for your password.' });
    if (name.length > 50) return reply(res, 422, { ok: false, error: 'Name is too long.' });
    if (db.users.some(u => u.email === email)) return reply(res, 409, { ok: false, error: 'An account with that email already exists.' });
    const now = new Date().toISOString(); const user = { id: db.nextUserId++, email, passwordHash: passwordHash(password), name, entitlement: 'free', createdAt: now, updatedAt: now };
    db.users.push(user); db.states[user.id] = {}; save(db); return reply(res, 201, { ok: true, user: publicUser(user) }, { 'Set-Cookie': newCookie(user.id, req) });
  }
  if (action === 'login') {
    if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' }); const input = await body(req);
    const user = db.users.find(u => u.email === String(input.email || '').trim().toLowerCase()); if (!user || !passwordOK(String(input.password || ''), user.passwordHash)) return reply(res, 401, { ok: false, error: 'Email or password is incorrect.' });
    return reply(res, 200, { ok: true, user: publicUser(user) }, { 'Set-Cookie': newCookie(user.id, req) });
  }
  if (action === 'logout') { if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' }); return reply(res, 200, { ok: true }, { 'Set-Cookie': 'faveside_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax' }); }
  const user = db.users.find(u => u.id === sessionId(req));
  if (action === 'me') return reply(res, 200, { ok: true, user: publicUser(user) });
  if (action === 'state') {
    if (!user) return reply(res, 401, { ok: false, error: 'Please sign in.' });
    if (req.method === 'GET') return reply(res, 200, { ok: true, state: db.states[user.id] || {}, updated_at: user.updatedAt });
    if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' }); const input = await body(req);
    if (!input.state || typeof input.state !== 'object' || Array.isArray(input.state)) return reply(res, 422, { ok: false, error: 'State must be an object.' });
    if (JSON.stringify(input.state).length > 250000) return reply(res, 413, { ok: false, error: 'Account data is too large.' });
    db.states[user.id] = input.state; user.updatedAt = new Date().toISOString(); save(db); return reply(res, 200, { ok: true, updated_at: user.updatedAt });
  }
  reply(res, 404, { ok: false, error: 'Unknown account action.' });
}

async function parent(req, res, url) {
  const db = load(); const user = current(req, res, db); if (!user) return;
  if (!['premium', 'complimentary'].includes(user.entitlement)) return reply(res, 403, { ok: false, error: 'Parent Controls are a Faveside+ feature.', entitlement: user.entitlement });
  const action = url.searchParams.get('action') || 'list'; const profiles = db.profiles.filter(p => p.userId === user.id); const creators = Array.isArray(db.states[user.id]?.creators) ? db.states[user.id].creators.filter(c => c?.name).map(c => ({ ...c, _key: creatorKey(c) })) : []; const owned = id => profiles.find(p => p.id === Number(id));
  if (action === 'list') return reply(res, 200, { ok: true, profiles: profiles.map(p => ({ id: p.id, name: p.name, active: p.active, approved_keys: db.approvals[p.id] || [] })), creators });
  if (action === 'child_feed') { const p = owned(url.searchParams.get('profile_id')); if (!p) return reply(res, 404, { ok: false, error: 'Child profile not found.' }); if (!p.active) return reply(res, 423, { ok: false, error: 'This child profile is paused.' }); const allowed = new Set(db.approvals[p.id] || []); return reply(res, 200, { ok: true, profile: { id: p.id, name: p.name }, creators: creators.filter(c => allowed.has(c._key)).map(({ _key, ...c }) => c) }); }
  if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' }); const input = await body(req);
  if (action === 'create') { const name = String(input.name || '').trim().replace(/\s+/g, ' '); if (!name || name.length > 30) return reply(res, 422, { ok: false, error: 'Enter a first name up to 30 characters.' }); if (profiles.length >= 8) return reply(res, 409, { ok: false, error: 'This account already has the maximum of 8 child profiles.' }); const p = { id: db.nextProfileId++, userId: user.id, name, active: true }; db.profiles.push(p); db.approvals[p.id] = []; save(db); return reply(res, 201, { ok: true, profile: { id: p.id, name, active: true, approved_keys: [] } }); }
  const p = owned(input.profile_id); if (!p) return reply(res, 404, { ok: false, error: 'Child profile not found.' });
  if (action === 'toggle') { p.active = Boolean(input.active); save(db); return reply(res, 200, { ok: true, active: p.active }); }
  if (action === 'delete') { db.profiles = db.profiles.filter(x => x.id !== p.id); delete db.approvals[p.id]; save(db); return reply(res, 200, { ok: true }); }
  if (action === 'approve') { const key = String(input.creator_key || ''); if (!creators.some(c => c._key === key)) return reply(res, 422, { ok: false, error: 'That creator is not on the parent account.' }); const keys = new Set(db.approvals[p.id] || []); input.approved ? keys.add(key) : keys.delete(key); db.approvals[p.id] = [...keys]; save(db); return reply(res, 200, { ok: true, approved: Boolean(input.approved) }); }
  reply(res, 404, { ok: false, error: 'Unknown Parent Controls action.' });
}

async function billing(req, res, url) {
  const db = load(); const user = current(req, res, db); if (!user) return; const action = url.searchParams.get('action') || 'status';
  if (action === 'status') return reply(res, 200, { ok: true, entitlement: user.entitlement });
  if (action !== 'redeem') return reply(res, 404, { ok: false, error: 'Unknown billing action.' }); if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'Method not allowed.' });
  const input = await body(req); const code = String(input.code || '').trim().toUpperCase(); const hash = crypto.createHash('sha256').update(code).digest('hex');
  if (!code || code.length > 80) return reply(res, 422, { ok: false, error: 'Enter a valid code.' }); if (hash !== '35cace7996a66fe8e4340d074c7987e9ce5e3828df28314cfb7f6ec5f0324736') return reply(res, 404, { ok: false, error: 'That code is not valid.' });
  if (!db.redemptions[user.id] && Object.values(db.redemptions).filter(Boolean).length >= 20) return reply(res, 410, { ok: false, error: 'That code has reached its redemption limit.' });
  db.redemptions[user.id] = true; user.entitlement = 'complimentary'; user.updatedAt = new Date().toISOString(); save(db); reply(res, 200, { ok: true, entitlement: user.entitlement, promotion: 'Family & Friends', message: 'Premium access is now active on your account.' });
}

async function youtube(req, res, url) {
  const action = url.searchParams.get('action') || 'status'; const key = String(process.env.YOUTUBE_API_KEY || '').trim(); if (action === 'status') return reply(res, 200, { ok: true, configured: Boolean(key) }); if (!key) return reply(res, 503, { ok: false, setup_required: true, error: 'YouTube is ready to connect, but the server API key has not been installed yet.' });
  const google = async (endpoint, params) => { params.set('key', key); const r = await fetch(`https://www.googleapis.com/youtube/v3/${endpoint}?${params}`, { headers: { Accept: 'application/json', 'User-Agent': 'Faveside/1.0' }, signal: AbortSignal.timeout(10000) }); const d = await r.json().catch(() => null); if (!r.ok || !d) throw Object.assign(new Error(d?.error?.message || 'YouTube request failed.'), { status: r.status || 502 }); return d; };
  try {
    if (action === 'search') { const q = String(url.searchParams.get('q') || '').trim(); if (q.length < 2 || q.length > 80) return reply(res, 422, { ok: false, error: q.length < 2 ? 'Enter at least 2 characters.' : 'Search is too long.' }); const search = await google('search', new URLSearchParams({ part: 'snippet', type: 'channel', q, maxResults: '8', safeSearch: 'moderate' })); const items = new Map((search.items || []).filter(i => i.id?.channelId).map(i => [i.id.channelId, { id: i.id.channelId, name: i.snippet.channelTitle || i.snippet.title || 'YouTube creator', handle: '', category: 'YouTube', platform: 'YouTube', image: i.snippet.thumbnails?.high?.url || '', description: i.snippet.description || '', youtubeChannelId: i.id.channelId, url: `https://www.youtube.com/channel/${encodeURIComponent(i.id.channelId)}` }])); if (items.size) { const details = await google('channels', new URLSearchParams({ part: 'snippet,statistics', id: [...items.keys()].join(','), maxResults: '50' })); for (const c of details.items || []) { const item = items.get(c.id); if (item) { item.name = c.snippet?.title || item.name; item.handle = c.snippet?.customUrl || ''; item.image = c.snippet?.thumbnails?.high?.url || item.image; item.subscriberCount = c.statistics?.subscriberCount ? Number(c.statistics.subscriberCount) : null; item.videoCount = c.statistics?.videoCount ? Number(c.statistics.videoCount) : null; item.viewCount = c.statistics?.viewCount ? Number(c.statistics.viewCount) : null; } } } return reply(res, 200, { ok: true, results: [...items.values()] }); }
    if (action === 'activity') { const channelId = String(url.searchParams.get('channel_id') || ''); if (!/^UC[A-Za-z0-9_-]{20,30}$/.test(channelId)) return reply(res, 422, { ok: false, error: 'Invalid YouTube channel.' }); const videos = await google('search', new URLSearchParams({ part: 'snippet', type: 'video', channelId, order: 'date', maxResults: '5' })); return reply(res, 200, { ok: true, videos: (videos.items || []).filter(i => i.id?.videoId).map(i => ({ videoId: i.id.videoId, title: i.snippet?.title || 'New video', publishedAt: i.snippet?.publishedAt || '', thumbnail: i.snippet?.thumbnails?.medium?.url || '', url: `https://www.youtube.com/watch?v=${encodeURIComponent(i.id.videoId)}` })) }); }
    reply(res, 404, { ok: false, error: 'Unknown YouTube action.' });
  } catch (e) { reply(res, e.status || 502, { ok: false, error: e.message || 'YouTube could not be reached.' }); }
}

async function launch(req, res) {
  if (req.method !== 'POST') return reply(res, 405, { ok: false, message: 'Method not allowed.' }); const input = await body(req, 20000); if (input.website) return reply(res, 200, { ok: true, message: "You're on the list!" }); const email = String(input.email || '').trim().toLowerCase(); if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 254) return reply(res, 422, { ok: false, message: 'Please enter a valid email address.' }); const file = path.join(dataDir, 'launch-list.csv'); let old = ''; try { old = fs.readFileSync(file, 'utf8'); } catch {} const duplicate = old.split('\n').some(line => line.split(',')[1]?.replace(/^"|"$/g, '').toLowerCase() === email); if (!duplicate) { const safe = `"${email.replaceAll('"', '""')}"`; const ip = crypto.createHash('sha256').update(`${req.socket.remoteAddress || ''}|faveside-launch-list`).digest('hex'); fs.appendFileSync(file, `${new Date().toISOString()},${safe},${ip}\n`, { mode: 0o600 }); } reply(res, 200, { ok: true, duplicate, message: duplicate ? "You're already on the Faveside launch list!" : "You're in! We'll let you know when Faveside is ready." });
}

const pages = new Map([['/', 'index.html'], ['/index.html', 'index.html'], ['/index.php', 'index.html'], ['/app', 'app.html'], ['/app.html', 'app.html'], ['/app.php', 'app.html'], ['/account', 'account.html'], ['/account.html', 'account.html'], ['/account.php', 'account.html'], ['/family', 'family.html'], ['/family.html', 'family.html'], ['/child.html', 'child.html'], ['/privacy.html', 'privacy.html'], ['/terms.html', 'terms.html'], ['/support.html', 'support.html'], ['/robots.txt', 'robots.txt'], ['/sitemap.xml', 'sitemap.xml']]);
const mime = { '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.ico': 'image/x-icon', '.js': 'text/javascript; charset=utf-8', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.txt': 'text/plain; charset=utf-8', '.xml': 'application/xml; charset=utf-8', '.webp': 'image/webp' };
function file(req, res, target) { fs.readFile(target, (error, data) => { if (error) { res.writeHead(404, { ...securityHeaders, 'Content-Type': 'text/plain; charset=utf-8' }); return res.end('Not found'); } const ext = path.extname(target); res.writeHead(200, { ...securityHeaders, 'Content-Type': mime[ext] || 'application/octet-stream', 'Cache-Control': ['.html', '.txt', '.xml'].includes(ext) ? 'no-cache' : 'public, max-age=86400' }); req.method === 'HEAD' ? res.end() : res.end(data); }); }

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || '/', 'http://localhost'); const p = url.pathname;
    if (p === '/health') return reply(res, 200, { status: 'ok', service: 'faveside-app', storage: 'ready', youtube: Boolean(process.env.YOUTUBE_API_KEY) });
    if (p === '/api/account.php') return await account(req, res, url); if (p === '/api/parent.php') return await parent(req, res, url); if (p === '/api/billing.php') return await billing(req, res, url); if (p === '/api/youtube.php') return await youtube(req, res, url); if (p === '/launch-list.php') return await launch(req, res);
    if (pages.has(p)) return file(req, res, path.join(root, pages.get(p)));
    const relative = decodeURIComponent(p).replace(/^\/+/, ''); const target = path.resolve(root, relative); const allowed = new Set(['.css', '.ico', '.jpg', '.jpeg', '.js', '.png', '.svg', '.webp']); if (!relative || relative.split('/').some(x => x.startsWith('.')) || !target.startsWith(`${root}${path.sep}`) || !allowed.has(path.extname(target).toLowerCase())) { res.writeHead(404, { ...securityHeaders, 'Content-Type': 'text/plain; charset=utf-8' }); return res.end('Not found'); } file(req, res, target);
  } catch (e) { if (!res.headersSent) reply(res, e.status || 500, { ok: false, error: e.status ? e.message : 'The server could not complete that request.' }); }
});
server.listen(port, process.env.HOST || '0.0.0.0', () => console.log(`Faveside is listening on http://0.0.0.0:${port}`));
function shutdown() { server.close(() => process.exit(0)); }
process.on('SIGTERM', shutdown); process.on('SIGINT', shutdown);
