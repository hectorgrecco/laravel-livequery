import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import net from 'node:net';

const base = 'http://127.0.0.1:8000';
const websocketProxy = await createSocksProxy();
const browser = await chromium.launch({
    headless: true,
    args: ['--host-resolver-rules=MAP reverb.test 127.0.0.1'],
});
const contextA = await browser.newContext();
const contextB = await browser.newContext({
    proxy: { server: websocketProxy.url, bypass: '127.0.0.1' },
});
const tenantBContext = await browser.newContext();
const pageA = await contextA.newPage();
const pageB = await contextB.newPage();
const tenantBPage = await tenantBContext.newPage();

async function createSocksProxy() {
    let offline = false;
    const clients = new Set();
    const upstreams = new Set();
    const server = net.createServer(client => {
        clients.add(client);
        client.on('error', handleProxySocketError);
        client.on('close', () => {
            clients.delete(client);
            upstream?.destroy();
        });

        let stage = 'greeting';
        let buffered = Buffer.alloc(0);
        let upstream;

        const reject = (code = 1) => {
            client.end(Buffer.from([5, code, 0, 1, 0, 0, 0, 0, 0, 0]));
        };

        const parse = chunk => {
            buffered = Buffer.concat([buffered, chunk]);

            if (stage === 'greeting') {
                if (buffered.length < 2) return;
                const methodCount = buffered[1];
                if (buffered.length < 2 + methodCount) return;
                if (!buffered.subarray(2, 2 + methodCount).includes(0)) return reject(2);
                client.write(Buffer.from([5, 0]));
                buffered = buffered.subarray(2 + methodCount);
                stage = 'request';
            }

            if (stage !== 'request' || buffered.length < 4) return;

            const addressType = buffered[3];
            let addressLength;
            let addressOffset = 4;

            if (addressType === 1) addressLength = 4;
            else if (addressType === 4) addressLength = 16;
            else if (addressType === 3) {
                if (buffered.length < 5) return;
                addressLength = buffered[4];
                addressOffset = 5;
            } else {
                return reject(8);
            }

            const requestLength = addressOffset + addressLength + 2;
            if (buffered.length < requestLength) return;
            if (offline) return reject(1);

            const portOffset = addressOffset + addressLength;
            const destinationPort = buffered.readUInt16BE(portOffset);
            const remainder = buffered.subarray(requestLength);
            buffered = Buffer.alloc(0);
            stage = 'connecting';
            upstream = net.connect(destinationPort, '127.0.0.1');
            upstreams.add(upstream);
            upstream.on('close', () => upstreams.delete(upstream));
            upstream.on('error', error => {
                handleProxySocketError(error);
                if (stage === 'connecting') reject(5);
            });

            upstream.once('connect', () => {
                client.write(Buffer.from([5, 0, 0, 1, 127, 0, 0, 1, destinationPort >> 8, destinationPort & 255]));
                if (remainder.length > 0) upstream.write(remainder);
                stage = 'relay';
                client.off('data', parse);
                client.pipe(upstream);
                upstream.pipe(client);
            });

        };

        client.on('data', parse);
    });

    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });

    return {
        url: `socks5://127.0.0.1:${server.address().port}`,
        goOffline() {
            offline = true;
            for (const client of clients) client.destroy();
            for (const upstream of upstreams) upstream.destroy();
        },
        goOnline() {
            offline = false;
        },
        close() {
            for (const client of clients) client.destroy();
            for (const upstream of upstreams) upstream.destroy();
            server.close();
        },
    };
}

function handleProxySocketError(error) {
    if (error.code === 'ECONNRESET' || error.code === 'EPIPE') return;

    process.exitCode = 1;
    console.error('unexpected local WebSocket proxy error', error);
}

async function poll(check, message, timeout = 15000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (await check()) return;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Timed out: ${message}`);
}
async function queueDrained(page) {
    await poll(async () => (await page.request.get(`${base}/demo/queue-empty`)).json().then(value => value.empty), 'queue worker to process broadcasts');
}
async function openAs(page, email) {
    await page.goto(`${base}/demo/login/${email}`);
    await page.waitForURL('**/orders');
    await page.getByTestId('orders').waitFor();
}
async function auth(page, channel) {
    return page.request.post(`${base}/broadcasting/auth`, { form: { socket_id: '123.456', channel_name: channel } });
}
try {
    for (const page of [pageA, pageB]) page.on('console', message => { if (message.type() === 'error') console.error('browser console:', message.text()); });
    await openAs(pageA, 'a@example.test'); // role-derived permission
    await openAs(pageB, 'direct@example.test'); // direct permission, separate authenticated session
    await openAs(tenantBPage, 'tenant-b-reader@example.test'); // authorized user in a different tenant
    await pageB.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
    await pageB.waitForFunction(() => Object.values(window.Echo.connector.channels).some(channel => channel.name.includes('livequery.orders.tenant-a') && channel.subscribed));
    await tenantBPage.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
    await tenantBPage.waitForFunction(() => Object.values(window.Echo.connector.channels).some(channel => channel.name.includes('livequery.orders.tenant-b') && channel.subscribed));

    if ((await auth(pageB, 'private-livequery.orders.tenant-a')).status() !== 200) throw new Error('authorized user could not authenticate');
    if ((await auth(pageB, 'private-livequery.orders.tenant-b')).status() !== 403) throw new Error('cross-tenant channel access was not denied');
    if ((await auth(tenantBPage, 'private-livequery.orders.tenant-b')).status() !== 200) throw new Error('tenant B user could not authenticate to their own channel');
    if ((await auth(tenantBPage, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('tenant B user could authenticate to tenant A channel');
    if ((await auth(pageB, 'private-livequery.orders.tenant-a.extra')).status() !== 403) throw new Error('malformed channel was not denied');
    const guest = await browser.newPage();
    if ((await auth(guest, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('guest channel access was not denied');
    const noPermissionContext = await browser.newContext();
    const noPermissionPage = await noPermissionContext.newPage();
    await noPermissionPage.goto(`${base}/demo/login/b@example.test`);
    await noPermissionPage.waitForURL('**/orders', { waitUntil: 'commit' });
    if ((await auth(noPermissionPage, 'private-livequery.orders.tenant-b')).status() !== 403) throw new Error('user without permission was not denied');
    await noPermissionContext.close();
    await guest.close();
    console.log('HTTP private-channel auth: authorized tenants 200; guest, malformed, cross-tenant, and missing permission 403');

    const raceContext = await browser.newContext();
    const racePage = await raceContext.newPage();
    let allowInitialSocket = false;
    let firstSocketAttempt = true;
    await racePage.routeWebSocket(/^wss?:\/\/reverb\.test:8080\/app\//, websocket => {
        if (firstSocketAttempt) {
            firstSocketAttempt = false;
            websocket.close({ code: 1001, reason: 'simulate initial connection failure' });
            return;
        }
        if (!allowInitialSocket) {
            websocket.close({ code: 1001, reason: 'hold reconnect until after the missed update' });
            return;
        }
        websocket.connectToServer();
    });
    await openAs(racePage, 'direct@example.test');
    await racePage.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state !== 'connected');
    await racePage.getByTestId('orders').waitFor();
    if (await racePage.getByTestId('orders').getByText('New order — pending').count()) throw new Error('race test expected an empty initial snapshot');
    await pageA.getByTestId('create-order').click();
    await pageB.getByText('New order — pending').first().waitFor();
    await queueDrained(pageA);
    if (await racePage.getByTestId('orders').getByText('New order — pending').count()) throw new Error('initial snapshot race unexpectedly received an update while disconnected');
    if (await tenantBPage.getByTestId('orders').getByText('New order — pending').count()) throw new Error('tenant B rendered an order from tenant A');
    allowInitialSocket = true;
    await racePage.getByTestId('orders').getByText('New order — pending').waitFor();
    console.log('initial query/subscription race: first WebSocket attempt failed; automatic reconnect recovered the missed event');

    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\Order::create(['tenant_id' => 'tenant-b', 'name' => 'Tenant B order', 'status' => 'pending']);"], { cwd: process.cwd(), stdio: 'ignore' });
    await tenantBPage.getByText('Tenant B order — pending').waitFor();
    await queueDrained(pageA);
    if (await pageA.getByText('Tenant B order — pending').count() || await pageB.getByText('Tenant B order — pending').count()) throw new Error('tenant A rendered an order from tenant B');
    console.log('tenant isolation: each authorized browser rendered only its own tenant records');

    await pageA.getByTestId('create-order').click();
    await pageB.getByText('New order — pending').nth(1).waitFor();
    await queueDrained(pageA);
    console.log('two browser sessions: created order appeared without reload');

    const orderIdsBeforeMove = await pageB.getByTestId('orders').locator('[data-order-id]').evaluateAll(rows => rows.map(row => row.getAttribute('data-order-id')));
    await pageA.getByTestId('orders').getByRole('button', { name: 'Move to top' }).nth(1).click();
    await queueDrained(pageA);
    await poll(async () => {
        const orderIdsAfterMove = await pageB.getByTestId('orders').locator('[data-order-id]').evaluateAll(rows => rows.map(row => row.getAttribute('data-order-id')));
        return orderIdsAfterMove[0] === orderIdsBeforeMove[1] && orderIdsAfterMove[1] === orderIdsBeforeMove[0];
    }, 'updated order to move within the rendered query');
    console.log('query ordering: changing priority moved an order to the top without reload');

    await pageA.getByRole('button', { name: 'Complete' }).first().click();
    await pageB.getByText('New order — completed').first().waitFor();
    if (await pageB.getByTestId('orders').locator('[data-order-id]').count() !== 1) throw new Error('completed order remained in pending query');
    await queueDrained(pageA);
    console.log('filtered query: status update removed order from pending and showed it as completed');

    await pageA.getByRole('button', { name: 'Reopen' }).first().click();
    await pageB.getByText('New order — pending').first().waitFor();
    await queueDrained(pageA);
    await pageA.getByRole('button', { name: 'Delete' }).first().click();
    await pageB.getByTestId('deleted-orders').getByText('New order').first().waitFor();
    if (await pageB.getByTestId('orders').locator('[data-order-id]').count() !== 1) throw new Error('soft-deleted order remained in query');
    await queueDrained(pageA);
    await pageA.getByRole('button', { name: 'Restore' }).first().click();
    await poll(async () => await pageB.getByTestId('orders').locator('[data-order-id]').count() === 2, 'restored order to re-enter the pending query');
    await queueDrained(pageA);
    console.log('status re-entry, soft delete, and restore all refreshed the rendered Eloquent query');

    let expectedPendingCount = await pageB.getByTestId('orders').locator('[data-order-id]').count();
    for (let reconnect = 0; reconnect < 2; reconnect++) {
        websocketProxy.goOffline();
        await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state !== 'connected');
        await pageA.getByTestId('create-order').click();
        await queueDrained(pageA);
        if (await pageB.getByTestId('orders').locator('[data-order-id]').count() !== expectedPendingCount) throw new Error('client unexpectedly received updates while disconnected');
        websocketProxy.goOnline();
        await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state === 'connected' && window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed, undefined, { timeout: 30000 });
        expectedPendingCount++;
        try {
            await poll(async () => await pageB.getByTestId('orders').locator('[data-order-id]').count() === expectedPendingCount, 'query to refresh after reconnect');
        } catch (error) {
            console.error('reconnect diagnostic', { reconnect, expectedPendingCount, observed: await pageB.getByTestId('orders').locator('[data-order-id]').count(), state: await pageB.evaluate(() => window.Echo.connector.pusher.connection.state), subscribed: await pageB.evaluate(() => window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed) });
            throw error;
        }
    }
    console.log('repeated TCP network loss/recovery cycles: each missed broadcast recovered by a fresh authorized query');

    const browserLifecycle = await contextB.newCDPSession(pageB);
    websocketProxy.goOffline();
    await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state !== 'connected');
    await browserLifecycle.send('Page.setWebLifecycleState', { state: 'frozen' });
    await pageA.getByTestId('create-order').click();
    await queueDrained(pageA);
    websocketProxy.goOnline();
    await browserLifecycle.send('Page.setWebLifecycleState', { state: 'active' });
    await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state === 'connected' && window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed, undefined, { timeout: 60000 });
    await poll(async () => await pageB.getByTestId('orders').locator('[data-order-id]').count() === expectedPendingCount + 1, 'query to refresh after browser sleep and wake');
    expectedPendingCount++;
    await browserLifecycle.detach();
    console.log('browser sleep/wake: frozen page restored its authorized query after network recovery');

    const channelsBeforeReload = await pageB.evaluate(() => Object.keys(window.Echo.connector.channels).filter(name => name.includes('livequery.orders.tenant-a')).length);
    await pageB.getByTestId('leave-orders').click();
    await pageB.waitForURL('**/demo/away');
    await pageB.getByTestId('return-to-orders').click();
    await pageB.waitForURL('**/orders');
    await pageB.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
    await pageB.waitForFunction(() => window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed);
    const channelsAfterReload = await pageB.evaluate(() => Object.keys(window.Echo.connector.channels).filter(name => name.includes('livequery.orders.tenant-a')).length);
    if (channelsBeforeReload !== 1 || channelsAfterReload !== 1) throw new Error(`duplicate subscription detected (${channelsBeforeReload} -> ${channelsAfterReload})`);
    console.log('Livewire navigation: destroyed and remounted component with exactly one channel subscription');

    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::where('email', 'direct@example.test')->firstOrFail()->update(['tenant_id' => 'tenant-b']);"], { cwd: process.cwd(), stdio: 'ignore' });
    if ((await auth(pageB, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('user removed from tenant A remained authorized there');
    if ((await auth(pageB, 'private-livequery.orders.tenant-b')).status() !== 200) throw new Error('user moved to tenant B could not authorize there');
    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::where('email', 'direct@example.test')->firstOrFail()->update(['tenant_id' => 'tenant-a']);"], { cwd: process.cwd(), stdio: 'ignore' });
    console.log('tenant membership change: old tenant denied and new tenant authorized');

    await contextB.clearCookies();
    if ((await auth(pageB, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('expired browser session remained authorized');
    await openAs(pageB, 'direct@example.test');
    await pageB.waitForFunction(() => window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed);
    console.log('expired session: broadcast endpoint denied after clearing authenticated cookies');

    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::where('email', 'direct@example.test')->firstOrFail()->revokePermissionTo('view orders');"], { cwd: process.cwd(), stdio: 'ignore' });
    if ((await auth(pageB, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('revoked Spatie permission remained authorized');
    const visibleBeforeDeniedRefresh = await pageB.getByTestId('orders').getByText('New order — pending').count();
    const deniedRefresh = pageB.waitForResponse(response => response.url().includes('/livewire-') && response.url().endsWith('/update') && response.status() === 403);
    await pageA.getByTestId('create-order').click();
    await queueDrained(pageA);
    await deniedRefresh;
    const visibleAfterDeniedRefresh = await pageB.getByTestId('orders').getByText('New order — pending').count();
    if (visibleAfterDeniedRefresh !== visibleBeforeDeniedRefresh) throw new Error('protected query data was rendered after permission revocation');
    const deniedReload = await pageB.reload();
    if (deniedReload.status() !== 403) throw new Error('protected query remained readable after permission revocation');
    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::where('email', 'a@example.test')->firstOrFail()->removeRole('order-reader');"], { cwd: process.cwd(), stdio: 'ignore' });
    if ((await auth(pageA, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('revoked role-derived permission remained authorized');
    console.log('Spatie direct and role permission revocation: endpoint and subsequent query both denied');
} finally {
    websocketProxy.close();
    await browser.close();
}
