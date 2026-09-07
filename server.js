const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn, execSync } = require('child_process');

const FRONTEND_PORT = process.env.PORT || 3000;
const BACKEND_PORT = process.env.API_PORT || 8080;
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

// 1. Start PHP Backend API
let phpProcess = null;
const backendLogs = [];

function logBackend(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  console.log(line);
  backendLogs.push(line);
  if (backendLogs.length > 50) backendLogs.shift();
}

function startBackend() {
  if (phpProcess && !phpProcess.killed) return;
  const phpBin = findPhp();
  logBackend(`Starting CodeIgniter API using "${phpBin}" on http://127.0.0.1:${BACKEND_PORT}...`);
  try {
    const isWin = process.platform === 'win32';
    const docroot = path.resolve(API_DIR, 'public');
    const rewriteScript = path.resolve(API_DIR, 'vendor', 'codeigniter4', 'framework', 'system', 'rewrite.php');

    // Prefer direct PHP built-in server with CodeIgniter rewrite.php:
    // It avoids CLI/passthru issues and works reliably on all environments.
    const args = fs.existsSync(rewriteScript)
      ? ['-S', `127.0.0.1:${BACKEND_PORT}`, '-t', docroot, rewriteScript]
      : ['spark', 'serve', '--host', '127.0.0.1', '--port', String(BACKEND_PORT)];

    phpProcess = spawn(phpBin, args, {
      cwd: API_DIR,
      stdio: ['ignore', 'pipe', 'pipe'],
      shell: isWin
    });

    if (phpProcess.stdout) {
      phpProcess.stdout.on('data', (d) => {
        const lines = d.toString().split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        lines.forEach(line => logBackend(`[STDOUT] ${line}`));
      });
    }

    if (phpProcess.stderr) {
      phpProcess.stderr.on('data', (d) => {
        const lines = d.toString().split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        lines.forEach(line => logBackend(`[STDERR] ${line}`));
      });
    }

    phpProcess.on('error', (err) => {
      logBackend(`[ERROR] Failed to start PHP server: ${err.message}`);
    });

    phpProcess.on('exit', (code, signal) => {
      logBackend(`[EXIT] PHP server exited with code ${code}, signal ${signal}`);
      phpProcess = null;
    });
  } catch (err) {
    logBackend(`[EXCEPTION] ${err.message}`);
  }
}

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
    } else if (reqUrl === '/api/diagnostic' || reqUrl === '/api/diagnostic/') {
      let phpVersion = 'unknown';
      try {
        phpVersion = execSync(`${findPhp()} -v`, { timeout: 3000 }).toString().trim();
      } catch (e) {
        phpVersion = 'Error: ' + e.message;
      }
      let sparkStatus = 'unknown';
      try {
        sparkStatus = execSync(`${findPhp()} spark -V`, { cwd: API_DIR, timeout: 3000 }).toString().trim();
      } catch (e) {
        sparkStatus = 'Error: ' + e.message;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        status: phpProcess ? 'running' : 'offline',
        nodeVersion: process.version,
        platform: process.platform,
        pid: process.pid,
        cwd: ROOT_DIR,
        apiDirExists: fs.existsSync(API_DIR),
        sparkExists: fs.existsSync(path.join(API_DIR, 'spark')),
        detectedPhp: findPhp(),
        phpVersion,
        sparkStatus,
        backendPort: BACKEND_PORT,
        frontendPort: FRONTEND_PORT,
        backendLogs
      }, null, 2));
      return;
    } else if (req.url.startsWith('/api/') || req.url === '/api') {
      if (!phpProcess) {
        startBackend();
      }
      // Proxy /api requests to local CodeIgniter backend
      const proxyReq = http.request({
        hostname: '127.0.0.1',
        port: BACKEND_PORT,
        path: req.url,
        method: req.method,
        headers: { ...req.headers, host: `127.0.0.1:${BACKEND_PORT}` }
      }, (proxyRes) => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        proxyRes.pipe(res, { end: true });
      });

      proxyReq.on('error', (err) => {
        res.writeHead(502, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          success: false,
          error: {
            message: 'Backend API offline',
            detail: err.message,
            backendLogs: backendLogs.slice(-10)
          }
        }));
      });

      req.pipe(proxyReq, { end: true });
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
        'Access-Control-Allow-Origin': '*'
      });
      res.end(data);
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

    // Automatically open browser
    try {
      execSync(`start http://localhost:${FRONTEND_PORT}`);
    } catch {}
  });
}

// Clean exit handlers
function shutdown() {
  console.log('\nStopping servers...');
  if (phpProcess) {
    try {
      process.platform === 'win32'
        ? execSync(`taskkill /pid ${phpProcess.pid} /T /F`, { stdio: 'ignore' })
        : phpProcess.kill();
    } catch {}
  }
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

// Execute start
startBackend();
serveFrontend();
