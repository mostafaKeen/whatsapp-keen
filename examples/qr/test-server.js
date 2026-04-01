const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const path = require('path');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

app.get('/api/test', (req, res) => {
    res.json({ message: 'Server is working' });
});

app.get('/api/qr/:clientId', (req, res) => {
    const { clientId } = req.params;
    console.log(`Test API called for client: ${clientId}`);
    res.json({ message: `QR initialization started for ${clientId}` });
});

const PORT = process.env.PORT || 3001;
server.listen(PORT, () => {
    console.log(`Test server is running on port ${PORT}`);
});