import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const base = 'http://127.0.0.1:8000';
const browserCount = 10;
const updateCount = 100;
const browser = await chromium.launch({ headless: true });
const pages = [];
let livewireRequests = 0;
let inFlightLivewireRequests = 0;

try {
    for (let index = 0; index < browserCount; index++) {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(120000);
        await page.goto(`${base}/demo/login/direct@example.test`);
        await page.waitForURL('**/orders');
        await page.getByTestId('orders').waitFor();
        await page.waitForFunction(() => window.Echo?.connector?.pusher?.connection?.state === 'connected');
        await page.waitForFunction(() => window.Echo.connector.pusher.channel('private-livequery.orders.tenant-a')?.subscribed);

        page.on('request', request => {
            if (request.url().includes('/livewire-') && request.url().endsWith('/update')) {
                livewireRequests++;
                inFlightLivewireRequests++;
            }
        });
        page.on('requestfinished', request => {
            if (request.url().includes('/livewire-') && request.url().endsWith('/update')) inFlightLivewireRequests--;
        });
        page.on('requestfailed', request => {
            if (request.url().includes('/livewire-') && request.url().endsWith('/update')) inFlightLivewireRequests--;
        });
        pages.push(page);
    }

    const startedAt = performance.now();
    const seed = `DB::transaction(function () { DB::table('orders')->where('name', 'like', 'Fanout %')->delete(); for ($i = 1; $i <= ${updateCount}; $i++) { App\\Models\\Order::create(['tenant_id' => 'tenant-a', 'name' => 'Fanout ' . $i, 'status' => 'pending']); } });`;
    execFileSync('php', ['artisan', 'tinker', '--execute', seed], { cwd: process.cwd(), stdio: 'ignore' });

    const deadline = Date.now() + 120000;
    let queueEmpty = false;
    while (Date.now() < deadline) {
        queueEmpty = (await (await pages[0].request.get(`${base}/demo/queue-empty`)).json()).empty;
        if (queueEmpty) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!queueEmpty) throw new Error('broadcast queue did not drain');

    await Promise.all(pages.map(page => page.waitForFunction(
        expected => document.querySelectorAll('[data-testid="orders"] [data-order-id]').length === expected,
        updateCount,
    )));
    const idleDeadline = Date.now() + 120000;
    while (inFlightLivewireRequests > 0 && Date.now() < idleDeadline) {
        await new Promise(resolve => setTimeout(resolve, 50));
    }
    if (inFlightLivewireRequests > 0) throw new Error('Livewire refresh requests did not finish');

    console.log(JSON.stringify({
        database_driver: 'pgsql',
        connected_browser_sessions: browserCount,
        model_changes_in_one_transaction: updateCount,
        observed_livewire_refresh_requests: livewireRequests,
        final_visible_orders_per_session: updateCount,
        elapsed_ms: Math.round(performance.now() - startedAt),
    }, null, 2));
} finally {
    await browser.close();
    execFileSync('php', ['artisan', 'tinker', '--execute', "DB::table('orders')->where('name', 'like', 'Fanout %')->delete();"], { cwd: process.cwd(), stdio: 'ignore' });
}
