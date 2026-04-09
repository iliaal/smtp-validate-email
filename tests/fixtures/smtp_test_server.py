#!/usr/bin/env python3
"""
Minimal SMTP test server for e2e tests.

Accepts RCPT TO for a configured set of addresses, rejects all others with 550.
Detects catch-all probes (any address at catchall.test) and accepts them.

Usage: python3 smtp_test_server.py [port]
"""

import asyncio
import signal
import sys

from aiosmtpd.controller import Controller
from aiosmtpd.smtp import SMTP, Session, Envelope


VALID_RECIPIENTS = {
    'valid@localtest.test',
    'alice@localtest.test',
    'bob@localtest.test',
    'postmaster@localtest.test',
}

CATCHALL_DOMAINS = {
    'catchall.test',
}


class TestHandler:
    async def handle_RCPT(self, server, session, envelope, address, rcpt_options):
        addr = address.lower()
        domain = addr.rsplit('@', 1)[-1] if '@' in addr else ''

        if domain in CATCHALL_DOMAINS:
            envelope.rcpt_tos.append(address)
            return '250 OK'

        if addr in VALID_RECIPIENTS:
            envelope.rcpt_tos.append(address)
            return '250 OK'

        return '550 No such user'

    async def handle_DATA(self, server, session, envelope):
        return '250 OK'


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    controller = Controller(TestHandler(), hostname='127.0.0.1', port=port)
    controller.start()
    print(f'SMTP test server listening on 127.0.0.1:{port}', flush=True)

    # Wait for SIGTERM/SIGINT
    loop = asyncio.new_event_loop()
    stop = loop.create_future()

    def shutdown(sig, frame):
        if not stop.done():
            loop.call_soon_threadsafe(stop.set_result, None)

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    try:
        loop.run_until_complete(stop)
    except KeyboardInterrupt:
        pass
    finally:
        controller.stop()
        print('SMTP test server stopped', flush=True)


if __name__ == '__main__':
    main()
