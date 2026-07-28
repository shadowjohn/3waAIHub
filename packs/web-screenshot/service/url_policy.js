'use strict';

const dns = require('node:dns').promises;
const net = require('node:net');

const blockedIpv6 = new net.BlockList();
for (const [address, prefix] of [
  ['::', 96], ['::ffff:0:0', 96], ['64:ff9b::', 96], ['64:ff9b:1::', 48],
  ['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20],
]) {
  blockedIpv6.addSubnet(address, prefix, 'ipv6');
}

function policyError() {
  const error = new Error('url_not_allowed');
  error.code = 'url_not_allowed';
  return error;
}

function ipv4Number(address) {
  return address.split('.').reduce((value, part) => (value * 256) + Number(part), 0);
}

function ipv4InRange(value, base, bits) {
  const mask = bits === 0 ? 0 : ((0xffffffff << (32 - bits)) >>> 0);
  return ((value >>> 0) & mask) === ((base >>> 0) & mask);
}

function isPublicIpv4(address) {
  const value = ipv4Number(address);
  return ![
    [0x00000000, 8], [0x0a000000, 8], [0x64400000, 10], [0x7f000000, 8],
    [0xa9fe0000, 16], [0xac100000, 12], [0xc0000000, 24], [0xc0000200, 24],
    [0xc0a80000, 16], [0xc6336400, 24], [0xc6120000, 15], [0xcb007100, 24],
    [0xe0000000, 4], [0xf0000000, 4],
  ].some(([base, bits]) => ipv4InRange(value, base, bits));
}

function isPublicIpv6(address) {
  const value = address.toLowerCase();
  return /^[23]/.test(value) && !blockedIpv6.check(value, 'ipv6');
}

function isPublicIp(address) {
  const family = net.isIP(address);
  return family === 4 ? isPublicIpv4(address) : family === 6 ? isPublicIpv6(address) : false;
}

function publicDnsAnswers(addresses) {
  if (!Array.isArray(addresses) || addresses.length === 0) {
    throw policyError();
  }
  for (const answer of addresses) {
    const address = typeof answer === 'string' ? answer : answer && answer.address;
    if (typeof address !== 'string' || !isPublicIp(address)) {
      throw policyError();
    }
  }
}

function resolvePublicHost(hostname) {
  return dns.lookup(hostname, { all: true, verbatim: true });
}

function validatePublicHttpUrl(value, resolve = resolvePublicHost) {
  let url;
  try {
    url = new URL(value);
  } catch {
    throw policyError();
  }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password
    || (url.port !== '' && !['80', '443'].includes(url.port))) {
    throw policyError();
  }

  const hostname = url.hostname.toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');
  if (hostname === '' || hostname === 'localhost' || hostname.endsWith('.localhost')) {
    throw policyError();
  }
  if (net.isIP(hostname)) {
    if (!isPublicIp(hostname)) {
      throw policyError();
    }
    return url.href;
  }

  let answers;
  try {
    answers = resolve(hostname);
  } catch {
    throw policyError();
  }
  const accept = (resolved) => {
    publicDnsAnswers(resolved);
    return url.href;
  };
  return answers && typeof answers.then === 'function'
    ? answers.then(accept, () => { throw policyError(); })
    : accept(answers);
}

module.exports = { isPublicIp, policyError, resolvePublicHost, validatePublicHttpUrl };
