'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const root = __dirname;
const host = process.env.HOST || '0.0.0.0';
const port = Number(process.env.PORT) || 3000;

const routes = new Map([
  ['/', 'index.html'],
  ['/app', 'app.html'],
  ['/app.html', 'app.html'],
  ['/index.html', 'index.html']
]);

const contentTypes = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp'
};

function sendFile(request, response, filePath) {
  fs.readFile(filePath, (error, data) => {
    if (error) {
      response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      response.end('Not found');
      return;
    }

    response.writeHead(200, {
      'Content-Type': contentTypes[path.extname(filePath).toLowerCase()] || 'application/octet-stream',
      'Cache-Control': path.extname(filePath) === '.html' ? 'no-cache' : 'public, max-age=86400',
      'X-Content-Type-Options': 'nosniff',
      'X-Frame-Options': 'SAMEORIGIN',
      'Referrer-Policy': 'strict-origin-when-cross-origin'
    });
    if (request.method === 'HEAD') response.end();
    else response.end(data);
  });
}

const server = http.createServer((request, response) => {
  const pathname = new URL(request.url || '/', 'http://localhost').pathname;

  if (pathname === '/health') {
    response.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store'
    });
    response.end(JSON.stringify({ status: 'ok', service: 'faveside-app' }));
    return;
  }

  const routeFile = routes.get(pathname);
  if (routeFile) {
    sendFile(request, response, path.join(root, routeFile));
    return;
  }

  // Only serve explicitly requested public assets; never expose dotfiles,
  // deployment configuration, package metadata, or source control data.
  const relativePath = decodeURIComponent(pathname).replace(/^\/+/, '');
  if (!relativePath || relativePath.split('/').some((part) => part.startsWith('.'))) {
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
    return;
  }

  const allowedExtensions = new Set(['.css', '.ico', '.jpg', '.jpeg', '.js', '.png', '.svg', '.webp']);
  const target = path.resolve(root, relativePath);
  if (!target.startsWith(`${root}${path.sep}`) || !allowedExtensions.has(path.extname(target).toLowerCase())) {
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
    return;
  }

  sendFile(request, response, target);
});

server.listen(port, host, () => {
  console.log(`Faveside is listening on http://${host}:${port}`);
});

function shutdown() {
  server.close(() => process.exit(0));
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
