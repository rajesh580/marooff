const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn, execSync } = require('child_process');
const net = require('net');

const FRONTEND_PORT = process.env.PORT || 3000;
let BACKEND_PORT = parseInt(process.env.API_PORT || '8080', 10);
const ROOT_DIR = __dirname;
const API_DIR = path.join(ROOT_DIR, 'api');

// Find PHP executable
function findPhp() {
  if (process.platform === 'win32') {
    const customPhp = path.join(process.env.USERPROFILE || 'C:\\Users\\rajes', 'php', 'php.exe');
    if (fs.existsSync(customPhp)) return customPhp;
    try {
      execSync('where php', { stdio: 'ignore' });
      return 'php';
    } catch {
      return 'php';
    }
  }
  // Linux / Unix candidates
  const candidates = [
    'php',
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/alt/php82/usr/bin/php',
    '/opt/alt/php81/usr/bin/php',
    '/usr/bin/php8.2',
    '/usr/bin/php8.1',
    '/usr/bin/php8.0',
    '/usr/bin/php82'
  ];
  for (const bin of candidates) {
    try {
      execSync(`${bin} -v`, { stdio: 'ignore' });
      return bin;
    } catch {}
  }
  return 'php';
}

const PHP_BIN = findPhp();

console.log('====================================================');
console.log('   Maroof Storefront — All-in-One Server Launcher');
console.log('====================================================\n');
console.log('[Database] Connected to Hostinger MySQL (srv537.hstgr.io)');

// 1. PHP Process Supervisor State
let phpProcess = null;
let isStarting = false;
let isShuttingDown = false;
let backendReadyPromise = null;
const backendLogs = [];

// Persistent HTTP Keep-Alive Agent for PHP backend requests
const backendAgent = new http.Agent({
  keepAlive: true,
  keepAliveMsecs: 30000,
  maxSockets: 64,
  timeout: 15000
});

// In-Memory Micro-Cache for Public Read-Only Storefront Endpoints
const apiCache = new Map(); // key -> { statusCode, headers, body: Buffer, expiresAt: number }
const inFlightRequests = new Map(); // key -> Promise<{ statusCode, headers, body: Buffer }>
const API_CACHE_TTL_MS = 60 * 1000; // 60s TTL guarantees instant category navigation

function isCacheableApiRequest(method, url, headers = {}) {
  if (method !== 'GET' && method !== 'HEAD') return false;
  if (headers['authorization']) return false;
  if (headers['x-no-cache'] || headers['cache-control']?.includes('no-cache')) return false;

  const urlPath = (url || '').split('?')[0];
  const cacheablePrefixes = [
    '/api/categories',
    '/api/products',
    '/api/combos',
    '/api/home',
    '/api/settings/public',
    '/api/banners'
  ];

  return cacheablePrefixes.some(p => urlPath === p || urlPath.startsWith(p + '/'));
}

function flushApiCache(reason = '') {
  const count = apiCache.size;
  apiCache.clear();
  inFlightRequests.clear();
  if (count > 0 && reason) {
    logBackend(`[CACHE] Flushed ${count} cached API responses (${reason})`);
  }
}

// Clean hop-by-hop headers and align content-length when proxying/caching buffered bodies
function cleanResponseHeaders(headers = {}, bodyLength = null) {
  const clean = { ...headers };
  delete clean['transfer-encoding'];
  delete clean['connection'];
  delete clean['keep-alive'];
  delete clean['content-encoding'];
  if (typeof bodyLength === 'number') {
    clean['content-length'] = String(bodyLength);
  } else {
    delete clean['content-length'];
  }
  return clean;
}

function logBackend(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  console.log(line);
  backendLogs.push(line);
  if (backendLogs.length > 60) backendLogs.shift();
}

// Kill any orphaned PHP processes left over from prior deployments
// ONLY called on startup or explicit manual restart
function killOrphanedPhp() {
  if (process.platform !== 'win32') {
    try {
      execSync('pkill -9 -f "php -S 127.0.0.1" 2>/dev/null || true');
    } catch {}
    for (let p = 8080; p <= 8090; p++) {
      try {
        execSync(`fuser -k -9 ${p}/tcp 2>/dev/null || true`);
      } catch {}
    }
  }
}

// Test whether a specific port is free
function checkPortAvailable(port) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    server.once('listening', () => {
      server.close(() => resolve(true));
    });
    server.listen(port, '127.0.0.1');
  });
}

// Find an available port starting from startPort (does NOT kill processes)
async function findAvailableBackendPort(startPort = 8080) {
  for (let p = startPort; p < startPort + 20; p++) {
    const isFree = await checkPortAvailable(p);
    if (isFree) return p;
  }
  return startPort;
}

// Fast internal HTTP ping to check if PHP server is answering
function pingBackend(port, timeoutMs = 2500) {
  return new Promise((resolve) => {
    const req = http.get(`http://127.0.0.1:${port}/api/health`, { timeout: timeoutMs }, (res) => {
      res.resume(); // free memory
      resolve(res.statusCode >= 200 && res.statusCode < 500);
    });
    req.on('error', () => resolve(false));
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
  });
}

// Spawn the PHP built-in server process
async function spawnPhpProcess() {
  if (isShuttingDown) return;

  // Ensure writable directories exist with full permissions
  const writableDirs = [
    path.join(API_DIR, 'writable'),
    path.join(API_DIR, 'writable', 'cache'),
    path.join(API_DIR, 'writable', 'logs'),
    path.join(API_DIR, 'writable', 'session'),
    path.join(API_DIR, 'writable', 'uploads'),
    path.join(API_DIR, 'public', 'uploads')
  ];
  for (const dir of writableDirs) {
    try {
      if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
      fs.chmodSync(dir, 0o777);
    } catch {}
  }

  // Find a free port
  const freePort = await findAvailableBackendPort(BACKEND_PORT);
  BACKEND_PORT = freePort;

  const phpBin = findPhp();
  logBackend(`Starting CodeIgniter API using "${phpBin}" on http://127.0.0.1:${BACKEND_PORT}...`);

  const isWin = process.platform === 'win32';
  const docroot = path.resolve(API_DIR, 'public');
  const rewriteScript = path.resolve(API_DIR, 'vendor', 'codeigniter4', 'framework', 'system', 'rewrite.php');

  const args = fs.existsSync(rewriteScript)
    ? ['-S', `127.0.0.1:${BACKEND_PORT}`, '-t', docroot, rewriteScript]
    : ['spark', 'serve', '--host', '127.0.0.1', '--port', String(BACKEND_PORT)];

  let lastErrorOutput = '';

  const proc = spawn(phpBin, args, {
    cwd: API_DIR,
    stdio: ['ignore', 'pipe', 'pipe'],
    shell: isWin,
    env: {
      ...process.env,
      PHP_CLI_SERVER_WORKERS: '16'
    }
  });

  phpProcess = proc;

  if (proc.stdout) {
    proc.stdout.on('data', (d) => {
      const lines = d.toString().split(/\r?\n/).map(l => l.trim()).filter(Boolean);
      lines.forEach(line => logBackend(`[STDOUT] ${line}`));
    });
  }

  if (proc.stderr) {
    proc.stderr.on('data', (d) => {
      const text = d.toString();
      lastErrorOutput += text;
      const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
      lines.forEach(line => logBackend(`[STDERR] ${line}`));
    });
  }

  proc.on('error', (err) => {
    logBackend(`[ERROR] Failed to start PHP server: ${err.message}`);
  });

  proc.on('exit', (code, signal) => {
    logBackend(`[EXIT] PHP server exited with code ${code}, signal ${signal}`);
    if (phpProcess === proc) {
      phpProcess = null;
    }

    if (!isShuttingDown) {
      if (lastErrorOutput.includes('Address already in use')) {
        logBackend(`Port ${BACKEND_PORT} busy. Incrementing port...`);
        BACKEND_PORT++;
      }
      logBackend('[SUPERVISOR] PHP died. Auto-restarting in 400ms...');
      setTimeout(() => {
        ensureBackendReady().catch((e) => logBackend(`[RESTART ERROR] ${e.message}`));
      }, 400);
    }
  });
}

// Gate function: Guarantees PHP is running and responding before fulfilling requests
async function ensureBackendReady(maxWaitMs = 10000) {
  if (isShuttingDown) throw new Error('Server shutting down');

  // If already running and responsive, return immediately
  if (phpProcess && !phpProcess.killed) {
    const alive = await pingBackend(BACKEND_PORT, 800);
    if (alive) return BACKEND_PORT;
  }

  // If already in the middle of starting, wait for the pending promise
  if (backendReadyPromise) {
    return backendReadyPromise;
  }

  backendReadyPromise = (async () => {
    isStarting = true;
    try {
      if (!phpProcess || phpProcess.killed) {
        await spawnPhpProcess();
      }

      // Poll until PHP responds to HTTP ping
      const startTime = Date.now();
      while (Date.now() - startTime < maxWaitMs) {
        await new Promise(r => setTimeout(r, 200));
        const ok = await pingBackend(BACKEND_PORT, 600);
        if (ok) {
          logBackend(`[SUPERVISOR] Backend confirmed healthy and ready on port ${BACKEND_PORT}`);
          return BACKEND_PORT;
        }
      }

      logBackend(`[WARN] Backend readiness polling reached timeout; proceeding on port ${BACKEND_PORT}`);
      return BACKEND_PORT;
    } finally {
      isStarting = false;
      backendReadyPromise = null;
    }
  })();

  return backendReadyPromise;
}

// Background Heartbeat: pings backend every 15 seconds to prevent idle sleep / termination
setInterval(async () => {
  if (isShuttingDown || isStarting) return;
  try {
    const isAlive = await pingBackend(BACKEND_PORT, 2000);
    if (!isAlive) {
      logBackend('[HEARTBEAT] PHP backend unresponsive. Triggering revival...');
      await ensureBackendReady();
    }
  } catch (err) {
    logBackend(`[HEARTBEAT ERROR] ${err.message}`);
  }
}, 15000);

// 2. Static Frontend HTTP Server
const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.txt': 'text/plain; charset=utf-8'
};

function serveFrontend() {
  const server = http.createServer((req, res) => {
    const parsedUrl = req.url.split('?')[0];
    let reqUrl = decodeURI(parsedUrl);
    let safePath = path.normalize(reqUrl).replace(/^(\.\.[\/\\])+/, '');
    let filePath = path.join(ROOT_DIR, safePath);

    // Serve uploads directly from api/public/uploads if requested at /uploads/ or /api/uploads/
    if (reqUrl.startsWith('/uploads/')) {
      filePath = path.join(API_DIR, 'public', safePath);
    } else if (reqUrl.startsWith('/api/uploads/')) {
      filePath = path.join(API_DIR, 'public', safePath.replace(/^[\\\/]api[\\\/]/, '/'));
    } else if (reqUrl === '/api/restart-backend') {
      logBackend('[MANUAL] Remote restart requested via /api/restart-backend');
      if (phpProcess) {
        try { phpProcess.kill('SIGKILL'); } catch {}
        phpProcess = null;
      }
      killOrphanedPhp();
      ensureBackendReady().then(() => {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          success: true,
          message: 'Backend restarted and verified ready',
          backendPort: BACKEND_PORT,
          logs: backendLogs.slice(-20)
        }, null, 2));
      }).catch((e) => {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: e.message }));
      });
      return;
    } else if (reqUrl === '/api/diagnostic' || reqUrl === '/api/diagnostic/') {
      let phpVersion = 'unknown';
      try {
        phpVersion = execSync(`${findPhp()} -v`, { timeout: 3000 }).toString().trim();
      } catch (e) {
        phpVersion = 'Error: ' + e.message;
      }

      let ciLogs = [];
      try {
        const logsDir = path.join(API_DIR, 'writable', 'logs');
        if (fs.existsSync(logsDir)) {
          const files = fs.readdirSync(logsDir).filter(f => f.endsWith('.log')).sort().reverse();
          if (files.length > 0) {
            const content = fs.readFileSync(path.join(logsDir, files[0]), 'utf8');
            ciLogs = content.split('\n').filter(Boolean).slice(-30);
          }
        }
      } catch (logErr) {
        ciLogs = ['Log error: ' + logErr.message];
      }

      pingBackend(BACKEND_PORT, 2000).then((isHealthy) => {
        const testReq = http.get(`http://127.0.0.1:${BACKEND_PORT}/api/settings/public`, { timeout: 4000 }, (testRes) => {
          let rawData = '';
          testRes.on('data', chunk => rawData += chunk);
          testRes.on('end', () => {
            let parsedData = null;
            try { parsedData = JSON.parse(rawData); } catch {}
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
              status: isHealthy ? 'running' : 'offline',
              nodeVersion: process.version,
              platform: process.platform,
              pid: process.pid,
              backendPort: BACKEND_PORT,
              frontendPort: FRONTEND_PORT,
              phpVersion,
              backendHealthy: isHealthy,
              selfTest: {
                statusCode: testRes.statusCode,
                success: parsedData ? parsedData.success : false,
                storeName: (parsedData && parsedData.data && parsedData.data.store_name) || null
              },
              ciLogs,
              backendLogs
            }, null, 2));
          });
        });

        testReq.on('error', (e) => {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: isHealthy ? 'running' : 'offline',
            nodeVersion: process.version,
            platform: process.platform,
            pid: process.pid,
            backendPort: BACKEND_PORT,
            frontendPort: FRONTEND_PORT,
            phpVersion,
            backendHealthy: isHealthy,
            selfTest: {
              error: e.message
            },
            ciLogs,
            backendLogs
          }, null, 2));
        });
      });
      return;
    } else if (reqUrl === '/api/cache/flush' || reqUrl === '/api/clear-cache') {
      const count = apiCache.size;
      flushApiCache('manual endpoint');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, message: `Flushed ${count} cached API entries`, cacheSize: 0 }, null, 2));
      return;
    } else if (req.url.startsWith('/api/') || req.url === '/api') {
      // Invalidate storefront micro-cache immediately on any admin or data mutation
      if (req.method !== 'GET' && req.method !== 'HEAD') {
        flushApiCache(`mutation: ${req.method} ${req.url}`);
      }

      let clientClosed = false;
      req.on('close', () => {
        clientClosed = true;
      });

      const cacheKey = req.url;
      const canCache = isCacheableApiRequest(req.method, req.url, req.headers);

      // 1. Instant Cache Hit Check (<0.5ms response time)
      if (canCache) {
        const cached = apiCache.get(cacheKey);
        if (cached && Date.now() < cached.expiresAt) {
          const headers = cleanResponseHeaders(cached.headers, cached.body.length);
          res.writeHead(cached.statusCode, {
            ...headers,
            'x-cache': 'HIT',
            'cache-control': 'public, max-age=60'
          });
          res.end(cached.body);
          return;
        }

        // 2. In-Flight Request Deduplication (Promise Coalescing)
        if (inFlightRequests.has(cacheKey)) {
          inFlightRequests.get(cacheKey).then((coalesced) => {
            if (!clientClosed && !res.writableEnded) {
              const headers = cleanResponseHeaders(coalesced.headers, coalesced.body.length);
              res.writeHead(coalesced.statusCode, {
                ...headers,
                'x-cache': 'HIT-COALESCED',
                'cache-control': 'public, max-age=60'
              });
              res.end(coalesced.body);
            }
          }).catch(() => {
            executeProxy(0);
          });
          return;
        }
      }

      // 3. Robust Proxy Execution with Keep-Alive Agent & Auto-Retry
      async function executeProxy(retryCount = 0) {
        if (clientClosed && !canCache) return;

        let inFlightResolver = null;
        let inFlightRejecter = null;
        if (canCache && retryCount === 0 && !inFlightRequests.has(cacheKey)) {
          const p = new Promise((resolve, reject) => {
            inFlightResolver = resolve;
            inFlightRejecter = reject;
          });
          inFlightRequests.set(cacheKey, p);
        }

        try {
          await ensureBackendReady();

          const proxyReq = http.request({
            hostname: '127.0.0.1',
            port: BACKEND_PORT,
            path: req.url,
            method: req.method,
            agent: backendAgent,
            headers: {
              ...req.headers,
              host: `127.0.0.1:${BACKEND_PORT}`,
              'x-forwarded-host': req.headers['host'] || 'marooffc.com',
              'x-forwarded-proto': req.headers['x-forwarded-proto'] || 'https',
              'x-forwarded-for': req.headers['x-forwarded-for'] || (req.socket && req.socket.remoteAddress) || '127.0.0.1'
            }
          }, (proxyRes) => {
            const chunks = [];
            proxyRes.on('data', c => chunks.push(c));
            proxyRes.on('end', () => {
              const body = Buffer.concat(chunks);
              const result = {
                statusCode: proxyRes.statusCode,
                headers: proxyRes.headers,
                body
              };

              // Cache 200 OK responses
              if (canCache && proxyRes.statusCode === 200) {
                apiCache.set(cacheKey, {
                  statusCode: proxyRes.statusCode,
                  headers: {
                    ...proxyRes.headers,
                    'cache-control': 'public, max-age=60'
                  },
                  body,
                  expiresAt: Date.now() + API_CACHE_TTL_MS
                });
              }

              if (inFlightResolver) inFlightResolver(result);
              inFlightRequests.delete(cacheKey);

              if (!clientClosed && !res.writableEnded) {
                const headers = cleanResponseHeaders(proxyRes.headers, body.length);
                res.writeHead(proxyRes.statusCode, {
                  ...headers,
                  'x-cache': canCache ? 'MISS' : 'BYPASS'
                });
                res.end(body);
              }
            });
          });

          proxyReq.on('error', async (err) => {
            if (inFlightRejecter) inFlightRejecter(err);
            inFlightRequests.delete(cacheKey);

            if (retryCount < 2 && (req.method === 'GET' || req.method === 'HEAD') && (err.code === 'ECONNREFUSED' || err.code === 'ECONNRESET' || err.code === 'EPIPE')) {
              logBackend(`[PROXY RETRY] ${req.url} failed with ${err.code}. Retrying (${retryCount + 1}/2)...`);
              await new Promise(r => setTimeout(r, 300 * (retryCount + 1)));
              return executeProxy(retryCount + 1);
            }

            if (!clientClosed && !res.writableEnded) {
              res.writeHead(502, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({
                success: false,
                error: {
                  message: 'Backend API offline',
                  detail: err.message,
                  backendPort: BACKEND_PORT,
                  backendLogs: backendLogs.slice(-10)
                }
              }));
            }
          });

          if (req.method === 'GET' || req.method === 'HEAD') {
            proxyReq.end();
          } else {
            req.pipe(proxyReq, { end: true });
          }
        } catch (err) {
          if (inFlightRejecter) inFlightRejecter(err);
          inFlightRequests.delete(cacheKey);

          if (!clientClosed && !res.writableEnded) {
            res.writeHead(502, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
              success: false,
              error: {
                message: 'Failed to connect to backend',
                detail: err.message
              }
            }));
          }
        }
      }

      executeProxy();
      return;
    }

    // If directory and missing trailing slash, redirect with slash
    if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory() && !reqUrl.endsWith('/')) {
      const query = req.url.includes('?') ? '?' + req.url.split('?')[1] : '';
      res.writeHead(301, { Location: reqUrl + '/' + query });
      res.end();
      return;
    }

    // If directory, check for index.html
    if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
      filePath = path.join(filePath, 'index.html');
    }

    // If file does not exist directly, apply intelligent routing
    if (!fs.existsSync(filePath)) {
      if (fs.existsSync(filePath + '.html')) {
        filePath = filePath + '.html';
      } else if (fs.existsSync(path.join(filePath, 'index.html'))) {
        filePath = path.join(filePath, 'index.html');
      } else if (reqUrl.startsWith('/assets/')) {
        // Fallback for root assets to admin or sales assets
        const adminAsset = path.join(ROOT_DIR, 'admin', safePath);
        const salesAsset = path.join(ROOT_DIR, 'sales', safePath);
        if (fs.existsSync(adminAsset)) filePath = adminAsset;
        else if (fs.existsSync(salesAsset)) filePath = salesAsset;
      } else if (reqUrl.startsWith('/admin')) {
        // SPA Fallback for Admin Panel
        filePath = path.join(ROOT_DIR, 'admin', 'index.html');
      } else if (reqUrl.startsWith('/sales')) {
        // SPA Fallback for Sales Panel
        filePath = path.join(ROOT_DIR, 'sales', 'index.html');
      } else if (reqUrl.startsWith('/product/')) {
        filePath = path.join(ROOT_DIR, 'product', '_', 'index.html');
      } else if (reqUrl.startsWith('/category/')) {
        filePath = path.join(ROOT_DIR, 'category', '_', 'index.html');
      } else if (reqUrl.startsWith('/combos/')) {
        filePath = path.join(ROOT_DIR, 'combos', '_', 'index.html');
      } else {
        filePath = path.join(ROOT_DIR, '404.html');
      }
    }

    // Read and serve file
    fs.readFile(filePath, (err, data) => {
      if (err) {
        res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<h1>404 Not Found</h1>');
        return;
      }

      const ext = path.extname(filePath).toLowerCase();
      const contentType = MIME_TYPES[ext] || 'application/octet-stream';
      res.writeHead(200, {
        'Content-Type': contentType,
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      });
      if (ext === '.html') {
        let htmlStr = data.toString('utf8');
        const inject = "<script>if('scrollRestoration' in history)history.scrollRestoration='manual';window.scrollTo(0,0);</script>";
        if (!htmlStr.includes("history.scrollRestoration='manual'")) {
          htmlStr = htmlStr.replace('<head>', '<head>' + inject);
        }
        res.end(htmlStr);
      } else {
        res.end(data);
      }
    });
  });

  server.listen(FRONTEND_PORT, () => {
    console.log(`[Frontend] Serving storefront on http://localhost:${FRONTEND_PORT}\n`);
    console.log('----------------------------------------------------');
    console.log(`🚀 Storefront:  http://localhost:${FRONTEND_PORT}`);
    console.log(`🛠️  Admin Panel: http://localhost:${FRONTEND_PORT}/admin/`);
    console.log(`⚡ Backend API: http://localhost:${BACKEND_PORT}/api/health`);
    console.log(`🗄️  Database:    Hostinger MySQL (srv537.hstgr.io)`);
    console.log('----------------------------------------------------');
    console.log('Press Ctrl + C to stop all servers.\n');

    // Automatically open browser on Windows local dev
    if (process.platform === 'win32') {
      try {
        execSync(`start http://localhost:${FRONTEND_PORT}`);
      } catch {}
    }
  });
}

// Clean exit handlers
function shutdown() {
  isShuttingDown = true;
  console.log('\nStopping servers...');
  if (phpProcess) {
    try {
      process.platform === 'win32'
        ? execSync(`taskkill /pid ${phpProcess.pid} /T /F`, { stdio: 'ignore' })
        : phpProcess.kill('SIGKILL');
    } catch {}
  }
  killOrphanedPhp();
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

// Initial start
killOrphanedPhp();
ensureBackendReady().catch((e) => logBackend(`[STARTUP ERROR] ${e.message}`));
serveFrontend();
