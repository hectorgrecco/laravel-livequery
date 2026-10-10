import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const base = 'http://127.0.0.1:8000';
const browser = await chromium.launch({ headless: true });
const contextA = await browser.newContext();
const contextB = await browser.newContext();
const pageA = await contextA.newPage();
const pageB = await contextB.newPage();

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
async function openAs(page, userId) {
    await page.goto(`${base}/demo/login/${userId}`);
    await page.waitForURL('**/orders');
    await page.getByTestId('orders').waitFor();
}
async function auth(page, channel) {
    return page.request.post(`${base}/broadcasting/auth`, { form: { socket_id: '123.456', channel_name: channel } });
}

try {
    for (const page of [pageA, pageB]) page.on('console', message => { if (message.type() === 'error') console.error('browser console:', message.text()); });
    await openAs(pageA, 1); // role-derived permission
    await openAs(pageB, 3); // direct permission, separate authenticated session
    await pageB.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
    await pageB.waitForFunction(() => Object.values(window.Echo.connector.channels).some(channel => channel.name.includes('livequery.orders.tenant-a')));

    if ((await auth(pageB, 'private-livequery.orders.tenant-a')).status() !== 200) throw new Error('authorized user could not authenticate');
    if ((await auth(pageB, 'private-livequery.orders.tenant-b')).status() !== 403) throw new Error('cross-tenant channel access was not denied');
    if ((await auth(pageB, 'private-livequery.orders.tenant-a.extra')).status() !== 403) throw new Error('malformed channel was not denied');
    const guest = await browser.newPage();
    if ((await auth(guest, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('guest channel access was not denied');
    const noPermissionContext = await browser.newContext();
    const noPermissionPage = await noPermissionContext.newPage();
    await noPermissionPage.goto(`${base}/demo/login/2`);
    await noPermissionPage.waitForURL('**/orders');
    if ((await auth(noPermissionPage, 'private-livequery.orders.tenant-b')).status() !== 403) throw new Error('user without permission was not denied');
    await noPermissionContext.close();
    await guest.close();
    console.log('HTTP private-channel auth: authorized 200; guest, malformed, cross-tenant, and missing permission 403');

    const raceContext = await browser.newContext();
    const racePage = await raceContext.newPage();
    let allowInitialSocket = false;
    await racePage.routeWebSocket(/^wss?:\/\/127\.0\.0\.1:8080\/app\//, websocket => {
        if (!allowInitialSocket) {
            websocket.close({ code: 1001, reason: 'simulate initial connection failure' });
            return;
        }
        websocket.connectToServer();
    });
    await openAs(racePage, 3);
    await racePage.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state !== 'connected');
    await racePage.getByTestId('empty').waitFor();
    await pageA.getByTestId('create-order').click();
    await pageB.getByText('New order — pending').first().waitFor();
    await queueDrained(pageA);
    if (await racePage.getByTestId('orders').getByText('New order — pending').count()) throw new Error('initial snapshot race unexpectedly received an unauthenticated event');
    allowInitialSocket = true;
    await racePage.evaluate(() => {
        window.Echo.connector.pusher.disconnect();
        window.Echo.connector.pusher.connect();
    });
    await racePage.getByTestId('orders').getByText('New order — pending').waitFor();
    console.log('initial query/subscription race: connection failed during initial loading; fresh authenticated reconnect recovered the missed event');

    await pageA.getByTestId('create-order').click();
    await pageB.getByText('New order — pending').nth(1).waitFor();
    await queueDrained(pageA);
    console.log('two browser sessions: created order appeared without reload');

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
        await pageB.evaluate(() => window.Echo.connector.pusher.disconnect());
        await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state === 'disconnected');
        await pageA.getByTestId('create-order').click();
        await queueDrained(pageA);
        if (await pageB.getByTestId('orders').locator('[data-order-id]').count() !== expectedPendingCount) throw new Error('client unexpectedly received updates while disconnected');
        await pageB.evaluate(() => window.Echo.connector.pusher.connect());
        await pageB.waitForFunction(() => window.Echo.connector.pusher.connection.state === 'connected' && window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed);
        expectedPendingCount++;
        try {
            await poll(async () => await pageB.getByTestId('orders').locator('[data-order-id]').count() === expectedPendingCount, 'query to refresh after reconnect');
        } catch (error) {
            console.error('reconnect diagnostic', { reconnect, expectedPendingCount, observed: await pageB.getByTestId('orders').locator('[data-order-id]').count(), state: await pageB.evaluate(() => window.Echo.connector.pusher.connection.state), subscribed: await pageB.evaluate(() => window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed) });
            throw error;
        }
    }
    console.log('repeated reconnection: each missed broadcast recovered by a fresh query');

    await pageB.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await pageA.getByTestId('create-order').click();
    await queueDrained(pageA);
    await pageB.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await poll(async () => await pageB.getByTestId('orders').locator('[data-order-id]').count() === expectedPendingCount + 1, 'query to refresh after tab visibility recovery');
    console.log('visibility recovery: visible tab re-queried after a missed invalidation');

    const channelsBeforeReload = await pageB.evaluate(() => Object.keys(window.Echo.connector.channels).filter(name => name.includes('livequery.orders.tenant-a')).length);
    await pageB.goto(`${base}/demo/away`);
    await pageB.goto(`${base}/demo/login/3`);
    await pageB.waitForURL('**/orders');
    await pageB.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
    const channelsAfterReload = await pageB.evaluate(() => Object.keys(window.Echo.connector.channels).filter(name => name.includes('livequery.orders.tenant-a')).length);
    if (channelsBeforeReload !== 1 || channelsAfterReload !== 1) throw new Error(`duplicate subscription detected (${channelsBeforeReload} -> ${channelsAfterReload})`);
    console.log('reload/navigation: exactly one channel subscription after reload');

    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::find(3)->revokePermissionTo('view orders');"], { cwd: process.cwd(), stdio: 'ignore' });
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
    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\User::find(1)->removeRole('order-reader');"], { cwd: process.cwd(), stdio: 'ignore' });
    if ((await auth(pageA, 'private-livequery.orders.tenant-a')).status() !== 403) throw new Error('revoked role-derived permission remained authorized');
    console.log('Spatie direct and role permission revocation: endpoint and subsequent query both denied');
} finally {
    await browser.close();
}
