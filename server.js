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
  const customPhp = path.join(process.env.USERPROFILE || 'C:\\Users\\rajes', 'php', 'php.exe');
  if (fs.existsSync(customPhp)) return customPhp;
  try {
    execSync('where php', { stdio: 'ignore' });
    return 'php';
  } catch {
    return 'php';
  }
}

const PHP_BIN = findPhp();

console.log('====================================================');
console.log('   Maroof Storefront — All-in-One Server Launcher');
console.log('====================================================\n');
console.log('[Database] Connected to Hostinger MySQL (srv537.hstgr.io)');

// 1. Start PHP Backend API
let phpProcess = null;
function startBackend() {
  console.log(`[Backend API] Starting CodeIgniter API on http://localhost:${BACKEND_PORT}...`);
  phpProcess = spawn(PHP_BIN, ['spark', 'serve', '--port', String(BACKEND_PORT)], {
    cwd: API_DIR,
    stdio: 'inherit',
    shell: true
  });

  phpProcess.on('error', (err) => {
    console.error('[Backend API] Failed to start PHP server:', err.message);
  });
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
    // Proxy /api requests to local CodeIgniter backend
    if (req.url.startsWith('/api/') || req.url === '/api') {
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

      proxyReq.on('error', () => {
        res.writeHead(502, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: { message: 'Backend API offline' } }));
      });

      req.pipe(proxyReq, { end: true });
      return;
    }

    const parsedUrl = req.url.split('?')[0];
    let reqUrl = decodeURI(parsedUrl);
    let safePath = path.normalize(reqUrl).replace(/^(\.\.[\/\\])+/, '');
    let filePath = path.join(ROOT_DIR, safePath);

    // Serve uploads directly from api/public/uploads if requested at root
    if (reqUrl.startsWith('/uploads/')) {
      filePath = path.join(API_DIR, 'public', safePath);
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
