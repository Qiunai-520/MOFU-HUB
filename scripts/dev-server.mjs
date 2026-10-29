#!/usr/bin/env node

/**
 * Local preview proxy for PHP's single-threaded built-in server.
 *
 * PHP_CLI_SERVER_WORKERS is not available on Windows. This script starts a
 * small pool of ordinary php -S processes and routes each request to the least
 * busy worker, so an SSE connection no longer blocks every following request.
 */

import { spawn } from 'node:child_process';
import { createReadStream } from 'node:fs';
import { readFile, stat } from 'node:fs/promises';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { gzipSync } from 'node:zlib';

function argument(name, fallback = '') {
  const index = process.argv.indexOf(`--${name}`);

  return index >= 0 && process.argv[index + 1] !== undefined ? process.argv[index + 1] : fallback;
}

const root = path.resolve(argument('root', process.cwd()));
const php = argument('php');
const ini = argument('ini');
const port = Number(argument('port', '8000'));
const workers = Math.max(1, Math.min(16, Number(argument('workers', '4'))));
const host = argument('host', '127.0.0.1');
const publicRoot = path.join(root, 'public');

const MIME_TYPES = {
  '.css': 'text/css; charset=utf-8',
  '.gif': 'image/gif',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.jpeg': 'image/jpeg',
  '.jpg': 'image/jpeg',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ttf': 'font/ttf',
  '.webmanifest': 'application/manifest+json',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
};

const COMPRESSIBLE = new Set(['.css', '.html', '.js', '.json', '.svg', '.webmanifest']);

if (!php || !ini || !Number.isInteger(port) || port < 1 || port > 65535) {
  console.error('用法: node scripts/dev-server.mjs --php <php.exe> --ini <php.ini> [--port 8000] [--workers 4]');
  process.exit(1);
}

function reservePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.unref();
    server.once('error', reject);
    server.listen(0, host, () => {
      const address = server.address();
      const selected = typeof address === 'object' && address !== null ? address.port : 0;
      server.close(() => resolve(selected));
    });
  });
}

function waitForPort(targetPort, timeoutMs = 8000) {
  const started = Date.now();

  return new Promise((resolve, reject) => {
    const probe = () => {
      const socket = net.createConnection({ host, port: targetPort });
      socket.once('connect', () => {
        socket.destroy();
        resolve();
      });
      socket.once('error', () => {
        socket.destroy();

        if (Date.now() - started >= timeoutMs) {
          reject(new Error(`PHP worker did not start on ${host}:${targetPort}`));
          return;
        }

        setTimeout(probe, 100);
      });
    };

    probe();
  });
}

const children = new Set();
const pool = [];
let cursor = 0;
let shuttingDown = false;

async function startWorker(index) {
  const workerPort = await reservePort();
  const child = spawn(
    php,
    ['-c', ini, '-S', `${host}:${workerPort}`, '-t', 'public', 'public/router.php'],
    { cwd: root, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] },
  );

  children.add(child);
  child.stdout.on('data', (chunk) => process.stdout.write(`[php ${index + 1}] ${chunk}`));
  child.stderr.on('data', (chunk) => process.stderr.write(`[php ${index + 1}] ${chunk}`));
  child.once('exit', (code) => {
    children.delete(child);

    if (!shuttingDown) {
      console.error(`[preview] PHP worker ${index + 1} exited with code ${code ?? 'unknown'}`);
      shutdown(1);
    }
  });

  await waitForPort(workerPort);

  return { child, port: workerPort, active: 0, index };
}

function selectWorker() {
  let selected = null;

  for (let offset = 0; offset < pool.length; offset += 1) {
    const worker = pool[(cursor + offset) % pool.length];

    if (selected === null || worker.active < selected.active) {
      selected = worker;
    }
  }

  cursor = (cursor + 1) % pool.length;
  selected.active += 1;

  return selected;
}

function releaseWorker(worker) {
  worker.active = Math.max(0, worker.active - 1);
}

async function serveStatic(request, response) {
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    return false;
  }

  let pathname;

  try {
    pathname = decodeURIComponent(new URL(request.url ?? '/', `http://${host}`).pathname);
  } catch {
    response.writeHead(400).end('Bad request');
    return true;
  }

  const candidate = path.resolve(publicRoot, `.${pathname}`);

  if (candidate !== publicRoot && !candidate.startsWith(`${publicRoot}${path.sep}`)) {
    response.writeHead(403).end('Forbidden');
    return true;
  }

  let fileStat;

  try {
    fileStat = await stat(candidate);
  } catch {
    return false;
  }

  if (!fileStat.isFile()) {
    return false;
  }

  const extension = path.extname(candidate).toLowerCase();
  const contentType = MIME_TYPES[extension] ?? 'application/octet-stream';
  const canCompress = COMPRESSIBLE.has(extension)
    && /\bgzip\b/i.test(request.headers['accept-encoding'] ?? '')
    && fileStat.size < 2 * 1024 * 1024;

  response.setHeader('Content-Type', contentType);
  response.setHeader('Cache-Control', pathname.startsWith('/assets/')
    ? 'public, max-age=31536000, immutable'
    : 'no-cache');
  response.setHeader('X-Content-Type-Options', 'nosniff');

  if (canCompress) {
    const compressed = gzipSync(await readFile(candidate));
    response.setHeader('Content-Encoding', 'gzip');
    response.setHeader('Vary', 'Accept-Encoding');
    response.setHeader('Content-Length', compressed.byteLength);
    response.writeHead(200);
    response.end(request.method === 'HEAD' ? undefined : compressed);
    return true;
  }

  response.setHeader('Content-Length', fileStat.size);
  response.writeHead(200);

  if (request.method === 'HEAD') {
    response.end();
  } else {
    createReadStream(candidate).pipe(response);
  }

  return true;
}

const proxy = http.createServer(async (request, response) => {
  if (await serveStatic(request, response)) {
    return;
  }

  const worker = selectWorker();
  let released = false;
  const headers = { ...request.headers, connection: 'close' };

  const release = () => {
    if (!released) {
      released = true;
      releaseWorker(worker);
    }
  };

  const upstream = http.request({
    host,
    port: worker.port,
    method: request.method,
    path: request.url,
    headers,
    // PHP's built-in server serializes idle keep-alive connections. Closing
    // the upstream connection lets each worker accept another request.
    agent: false,
  }, (upstreamResponse) => {
    response.writeHead(
      upstreamResponse.statusCode ?? 502,
      upstreamResponse.statusMessage,
      upstreamResponse.headers,
    );
    upstreamResponse.pipe(response);
    upstreamResponse.once('end', release);
    upstreamResponse.once('error', release);
  });

  upstream.once('error', (error) => {
    release();

    if (!response.headersSent) {
      response.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
      response.end(`Preview worker failed: ${error.message}`);
    } else {
      response.destroy(error);
    }
  });

  response.once('close', release);
  request.pipe(upstream);
});

function shutdown(exitCode = 0) {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  proxy.close();

  for (const child of children) {
    child.kill();
  }

  setTimeout(() => process.exit(exitCode), 250).unref();
}

process.once('SIGINT', () => shutdown(0));
process.once('SIGTERM', () => shutdown(0));

try {
  for (let index = 0; index < workers; index += 1) {
    pool.push(await startWorker(index));
  }

  proxy.listen(port, host, () => {
    console.log(`[preview] ${host}:${port} -> ${workers} PHP workers`);
  });
} catch (error) {
  console.error(`[preview] ${error instanceof Error ? error.message : String(error)}`);
  shutdown(1);
}
