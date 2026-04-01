const { Client, LocalAuth } = require('whatsapp-web.js');
const path = require('path');

console.log('Testing WhatsApp client initialization...');

try {
  const client = new Client({
    authStrategy: new LocalAuth({
      clientId: 'test',
      dataPath: path.join(__dirname, 'sessions')
    }),
    puppeteer: {
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox']
    }
  });

  console.log('Client created successfully');

  client.on('qr', (qr) => {
    console.log('QR received:', qr.substring(0, 50) + '...');
  });

  client.on('ready', () => {
    console.log('Client ready');
    process.exit(0);
  });

  client.on('auth_failure', (msg) => {
    console.log('Auth failure:', msg);
    process.exit(1);
  });

  console.log('Initializing client...');
  client.initialize().catch(err => {
    console.error('Initialize error:', err);
    process.exit(1);
  });

  // Timeout after 30 seconds
  setTimeout(() => {
    console.log('Timeout - no QR or ready event');
    process.exit(1);
  }, 30000);

} catch (error) {
  console.error('Error creating client:', error);
  process.exit(1);
}