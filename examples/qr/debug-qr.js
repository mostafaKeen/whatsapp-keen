const { Client, LocalAuth } = require('whatsapp-web.js');
const fs = require('fs');
const path = require('path');

// Replicate the browser finding logic from server.js
const possiblePaths = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
    path.join(process.env.LOCALAPPDATA || '', 'Microsoft/Edge/Application/msedge.exe')
];

let executablePath = null;
for (const p of possiblePaths) {
    if (fs.existsSync(p)) {
        executablePath = p;
        console.log(`Found browser at: ${p}`);
        break;
    }
}

if (!executablePath) {
    console.warn('No browser found in common paths. Puppeteer will try to use its own downloaded browser.');
}

async function start() {
    console.log('Initializing client...');
    const client = new Client({
        authStrategy: new LocalAuth({
            clientId: 'test-debug',
            dataPath: './sessions'
        }),
        puppeteer: {
            headless: true,
            executablePath: executablePath || undefined,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--single-process',
                '--disable-gpu'
            ]
        }
    });

    client.on('qr', (qr) => {
        console.log('QR RECEIVED', qr);
        process.exit(0);
    });

    client.on('ready', () => {
        console.log('READY');
        process.exit(0);
    });

    client.on('auth_failure', (msg) => {
        console.error('AUTHENTICATION FAILURE', msg);
        process.exit(1);
    });

    try {
        await client.initialize();
        console.log('Initialization call finished');
    } catch (err) {
        console.error('INITIALIZATION FAILED:', err);
        process.exit(1);
    }
}

start();
