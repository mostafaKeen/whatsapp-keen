const http = require('http');

const options = {
    hostname: 'localhost',
    port: 3001,
    path: '/api/qr/test',
    method: 'GET'
};

const req = http.request(options, (res) => {
    console.log(`Status: ${res.statusCode}`);
    console.log(`Headers:`, res.headers);

    res.on('data', (chunk) => {
        console.log(`Body: ${chunk}`);
    });

    res.on('end', () => {
        console.log('Request completed');
        process.exit(0);
    });
});

req.on('error', (e) => {
    console.error(`Problem with request: ${e.message}`);
    process.exit(1);
});

req.end();