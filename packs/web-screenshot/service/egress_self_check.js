'use strict';

const dns = require('node:dns').promises;
const fs = require('node:fs');
const net = require('node:net');

function fail(message) {
  throw new Error(message);
}

function dockerGateway() {
  const route = fs.readFileSync('/proc/net/route', 'utf8').split('\n').find((line) => {
    const fields = line.trim().split(/\s+/);
    return fields[1] === '00000000' && /^[0-9A-Fa-f]{8}$/.test(fields[2] || '');
  });
  if (!route) {
    fail('docker gateway unavailable');
  }
  const gateway = route.trim().split(/\s+/)[2];
  return gateway.match(/../g).reverse().map((octet) => String(parseInt(octet, 16))).join('.');
}

function blockedConnection(host) {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({ host, port: 80 });
    const timer = setTimeout(() => finish(), 2000);
    let done = false;

    function finish(error) {
      if (done) return;
      done = true;
      clearTimeout(timer);
      socket.destroy();
      if (error) reject(error);
      else resolve();
    }

    socket.once('connect', () => finish(new Error(`blocked destination connected: ${host}`)));
    socket.once('error', () => finish());
  });
}

async function rejected(operation, label) {
  let failed = false;
  try {
    await operation();
  } catch {
    failed = true;
  }
  if (!failed) {
    fail(`${label} unexpectedly succeeded`);
  }
}

async function fetchOk(url) {
  const response = await fetch(url);
  if (!response.ok) {
    fail(`public fetch failed: ${url}`);
  }
}

async function main() {
  const status = fs.readFileSync('/proc/self/status', 'utf8');
  const capEff = /^CapEff:\s*([0-9a-f]+)$/mi.exec(status);
  if (!capEff || (BigInt(`0x${capEff[1]}`) & (1n << 12n)) !== 0n) {
    fail('CAP_NET_ADMIN remains effective');
  }
  if (process.getuid() === 0) {
    fail('capture still runs as root');
  }

  const answers = await dns.lookup('example.com', { all: true });
  if (answers.length === 0) {
    fail('DNS returned no answers');
  }
  await fetchOk('http://example.com');
  await fetchOk('https://example.com');

  for (const host of ['10.0.0.1', 'fc00::1', 'fe80::1', '169.254.169.254', 'host.docker.internal', dockerGateway()]) {
    await blockedConnection(host);
  }
  await rejected(
    () => fetch('https://httpbingo.org/redirect-to?url=http%3A%2F%2F169.254.169.254', { signal: AbortSignal.timeout(10000) }),
    'redirect to metadata service'
  );

  console.log('egress_self_check: ok');
}

main();
