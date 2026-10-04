import { chromium } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

// Private session/fixture manifests stay in .local; output never includes credentials.
const base = process.env.QA_URL || 'http://192.168.1.119:8082';
const origin = new URL(base);
assert.equal(origin.origin, 'http://192.168.1.119:8082');
assert.equal(origin.pathname, '/');
const local = fileURLToPath(
    new URL('../.local/client-files/', import.meta.url)
);
const session = JSON.parse(
    await fs.readFile(
        process.env.QA_SESSION_FILE || path.join(local, 'browser-session.json'),
        'utf8'
    )
);
const fixtures = JSON.parse(
    await fs.readFile(
        process.env.QA_FIXTURES_FILE ||
            path.join(local, 'preview-fixtures.json'),
        'utf8'
    )
);
assert.equal(fixtures.client_id, session.client_id);
assert.equal(fixtures.other_client_id, session.other_client_id);
const output = process.env.QA_OUTPUT_DIR || path.join(local, 'preview');
await fs.mkdir(output, { recursive: true });
const checks = [];
const errors = [];
const redact = (message) =>
    String(message)
        .replaceAll(session.token, '<redacted>')
        .replaceAll(session.portal_key, '<redacted>');
const check = (label) => {
    checks.push(label);
    console.log(`PASS ${label}`);
};
const sha256 = (bytes) =>
    crypto.createHash('sha256').update(bytes).digest('hex');
const downloadMatches = async (download, fixture, label) => {
    assert.equal(download.suggestedFilename(), fixture.name);
    const destination = path.join(output, `${label}-${fixture.name}`);
    await download.saveAs(destination);
    assert.equal(sha256(await fs.readFile(destination)), fixture.sha256);
};
const assertSafeInline = async (response, fixture) => {
    assert.equal(response.status(), 200);
    const headers = response.headers();
    assert.match(
        headers['content-type'],
        new RegExp(`^${fixture.mime.replace('/', '\\/')}(?:;|$)`, 'i')
    );
    assert.match(headers['content-disposition'], /^inline(?:;|$)/i);
    assert.match(headers['cache-control'], /private/i);
    assert.match(headers['cache-control'], /no-store/i);
    assert.ok(
        headers['x-content-type-options']
            ?.split(',')
            .every((token) => token.trim().toLowerCase() === 'nosniff')
    );
    assert.equal(sha256(await response.body()), fixture.sha256);
};
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH,
});
const admin = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    acceptDownloads: true,
});
await admin.addInitScript(({ token }) => {
    if (
        window === window.top &&
        location.origin === 'http://192.168.1.119:8082'
    ) {
        localStorage?.setItem('X-NINJA-TOKEN', token);
    }
    window.__icPreviewExecuted = false;
    window.__previewExecuted = false;
}, session);
const page = await admin.newPage();
page.on('pageerror', (error) => errors.push(redact(error.message)));
const portal = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    acceptDownloads: true,
});
await portal.addInitScript(() => {
    window.__icPreviewExecuted = false;
    window.__previewExecuted = false;
});
const client = await portal.newPage();
client.on('pageerror', (error) => errors.push(redact(error.message)));
let activeStage = 'opening admin library';
try {
    await page.goto(`${base}/clients/${session.client_id}/documents_overview`);
    await page
        .getByRole('button', { name: 'Download folder as ZIP', exact: true })
        .waitFor({ timeout: 60000 });
    for (const fixture of fixtures.fixtures.filter((file) =>
        [
            'image',
            'pdf',
            'python',
            'deluge',
            'html',
            'forged_image',
            'forged_pdf',
        ].includes(file.kind)
    )) {
        activeStage = `admin ${fixture.kind} preview`;
        const [response] = await Promise.all([
            page.waitForResponse(
                (candidate) =>
                    new URL(candidate.url()).pathname ===
                    `/api/v1/documents/${fixture.id}/download`
            ),
            page
                .getByRole('button', { name: fixture.name, exact: true })
                .click(),
        ]);
        assert.equal(response.status(), 200);
        assert.ok(
            (await response.request().headerValue('X-API-TOKEN')) ===
                session.token,
            'Admin content request must carry its private API token'
        );
        assert.equal(new URL(response.url()).origin, base);
        assert.equal(sha256(await response.body()), fixture.sha256);
        const dialog = page.getByRole('dialog', {
            name: fixture.name,
            exact: true,
        });
        await dialog.waitFor();
        if (fixture.kind === 'image') {
            const image = dialog.getByRole('img', {
                name: fixture.name,
                exact: true,
            });
            await image.waitFor();
            await image.evaluate((element) =>
                element.complete
                    ? null
                    : new Promise((resolve, reject) => {
                          element.addEventListener('load', resolve, {
                              once: true,
                          });
                          element.addEventListener('error', reject, {
                              once: true,
                          });
                      })
            );
            assert.equal(
                await image.evaluate((element) => element.naturalWidth),
                1
            );
            assert.match(await image.getAttribute('src'), /^blob:/);
        } else if (fixture.kind === 'pdf') {
            const frame = dialog.locator('iframe');
            await frame.waitFor();
            assert.equal(await frame.getAttribute('title'), fixture.name);
            assert.match(await frame.getAttribute('src'), /^blob:/);
            assert.match(
                response.headers()['content-type'],
                /^application\/pdf/i
            );
        } else if (fixture.kind.startsWith('forged_')) {
            await dialog.getByRole('alert').waitFor();
            assert.equal(
                await dialog.locator('img, iframe, pre code').count(),
                0
            );
            assert.equal(
                await page.evaluate(() => window.__previewExecuted),
                false
            );
        } else {
            const code = dialog.locator('pre code');
            await code.waitFor();
            assert.equal(await code.textContent(), fixture.text);
            assert.equal(await dialog.locator('script').count(), 0);
            assert.equal(
                await page.evaluate(
                    () => window.__icPreviewExecuted || window.__previewExecuted
                ),
                false
            );
        }
        check(
            fixture.kind.startsWith('forged_')
                ? `Admin ${fixture.kind} rejects active content disguised as media`
                : `Admin ${fixture.kind} opens authenticated inline preview`
        );
        if (fixture.kind === 'python')
            await page.screenshot({
                path: path.join(output, 'admin-code-preview.png'),
                fullPage: true,
            });
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            dialog
                .getByRole('button', { name: 'Download', exact: true })
                .click(),
        ]);
        await downloadMatches(download, fixture, 'admin');
        check(
            `Admin ${fixture.kind} explicit download preserves original bytes`
        );
        await dialog
            .getByRole('button', { name: 'Close', exact: true })
            .click();
        await dialog.waitFor({ state: 'hidden' });
    }
    const unsupported = fixtures.fixtures.find(
        (file) => file.kind === 'unsupported'
    );
    activeStage = 'admin unsupported download fallback';
    const [fallback] = await Promise.all([
        page.waitForEvent('download'),
        page
            .getByRole('button', { name: unsupported.name, exact: true })
            .click(),
    ]);
    await downloadMatches(fallback, unsupported, 'admin');
    assert.equal(await page.getByRole('dialog').count(), 0);
    check('Admin unsupported filename safely falls back to download');

    activeStage = 'portal authentication';
    await client.goto(`${base}/client/key_login/${session.portal_key}`);
    await client.goto(`${base}/client/documents`);
    const imageFixture = fixtures.fixtures.find(
        (file) => file.kind === 'image'
    );
    await client
        .getByRole('link', { name: imageFixture.name, exact: true })
        .waitFor({ timeout: 30000 });
    for (const fixture of fixtures.fixtures.filter((file) =>
        ['private', 'cross_client'].includes(file.kind)
    )) {
        assert.equal(
            await client.getByText(fixture.name, { exact: true }).count(),
            0
        );
    }
    check('Portal library hides private and other-client fixtures');
    for (const fixture of fixtures.fixtures.filter((file) =>
        [
            'image',
            'pdf',
            'python',
            'deluge',
            'html',
            'forged_image',
            'forged_pdf',
            'unsupported',
        ].includes(file.kind)
    )) {
        activeStage = `portal ${fixture.kind} preview`;
        await client.goto(`${base}/client/documents`);
        await client
            .getByRole('link', { name: fixture.name, exact: true })
            .click();
        await client
            .getByRole('heading', { name: fixture.name, exact: true })
            .waitFor();
        assert.equal(
            new URL(client.url()).pathname,
            `/client/documents/${fixture.id}/preview`
        );
        const preview = client.locator('.ic-preview-content');
        await preview.waitFor();
        if (fixture.kind === 'image') {
            const image = preview.getByRole('img', {
                name: fixture.name,
                exact: true,
            });
            await image.waitFor();
            await image.evaluate((element) =>
                element.complete
                    ? null
                    : new Promise((resolve, reject) => {
                          element.addEventListener('load', resolve, {
                              once: true,
                          });
                          element.addEventListener('error', reject, {
                              once: true,
                          });
                      })
            );
            assert.equal(
                await image.evaluate((element) => element.naturalWidth),
                1
            );
            assert.equal(
                new URL(await image.getAttribute('src'), base).origin,
                base
            );
            await assertSafeInline(
                await portal.request.get(
                    `${base}/client/documents/${fixture.id}/preview/content`
                ),
                fixture
            );
        } else if (fixture.kind === 'pdf') {
            const frame = preview.locator('iframe');
            assert.equal(await frame.getAttribute('title'), fixture.name);
            assert.equal(
                new URL(await frame.getAttribute('src'), base).origin,
                base
            );
            await assertSafeInline(
                await portal.request.get(
                    `${base}/client/documents/${fixture.id}/preview/content`
                ),
                fixture
            );
            const openTab = preview.locator('a[target="_blank"]');
            assert.match(await openTab.getAttribute('rel'), /noopener/);
            assert.equal(
                new URL(await openTab.getAttribute('href'), base).pathname,
                `/client/documents/${fixture.id}/preview/content`
            );
            const [newTab] = await Promise.all([
                client.waitForEvent('popup'),
                openTab.click(),
            ]);
            await newTab.waitForLoadState('domcontentloaded');
            assert.equal(
                new URL(newTab.url()).pathname,
                `/client/documents/${fixture.id}/preview/content`
            );
            await newTab.close();
            check(
                'Portal PDF opens its protected inline content in a separate tab'
            );
        } else if (
            fixture.kind === 'unsupported' ||
            fixture.kind.startsWith('forged_')
        ) {
            assert.match(
                await preview.textContent(),
                /preview.*(?:not available|unavailable|unsupported)|cannot.*preview/i
            );
            assert.equal(
                (
                    await portal.request.get(
                        `${base}/client/documents/${fixture.id}/preview/content`
                    )
                ).status(),
                415
            );
            assert.equal(
                await preview.locator('img, iframe, script').count(),
                0
            );
            assert.equal(
                await client.evaluate(() => window.__previewExecuted),
                false
            );
        } else {
            const code = preview.locator('pre code');
            assert.equal(await code.textContent(), fixture.text);
            assert.equal(await preview.locator('script').count(), 0);
            assert.equal(
                await client.evaluate(
                    () => window.__icPreviewExecuted || window.__previewExecuted
                ),
                false
            );
            const inline = await portal.request.get(
                `${base}/client/documents/${fixture.id}/preview/content`
            );
            assert.equal(inline.status(), 415);
        }
        check(
            `Portal ${fixture.kind} view uses safe rendering and content headers`
        );
        if (fixture.kind === 'deluge')
            await client.screenshot({
                path: path.join(output, 'portal-code-preview.png'),
                fullPage: true,
            });
        const [download] = await Promise.all([
            client.waitForEvent('download'),
            client.getByRole('link', { name: 'Download', exact: true }).click(),
        ]);
        await downloadMatches(download, fixture, 'portal');
        check(
            `Portal ${fixture.kind} explicit download preserves original bytes`
        );
    }
    for (const fixture of fixtures.fixtures.filter((file) =>
        ['private', 'cross_client'].includes(file.kind)
    )) {
        activeStage = `portal ${fixture.kind} authorization`;
        for (const suffix of ['preview', 'preview/content', 'download']) {
            const denied = await portal.request.get(
                `${base}/client/documents/${fixture.id}/${suffix}`,
                { maxRedirects: 0 }
            );
            assert.equal(denied.status(), 403);
            assert.ok(
                !Buffer.from(await denied.body()).includes(
                    Buffer.from(
                        fixture.kind === 'private'
                            ? 'Preview QA private file'
                            : 'Preview QA other client file'
                    )
                )
            );
        }
        check(
            `Portal ${fixture.kind} preview, inline content, and download are denied`
        );
    }
    activeStage = 'unauthenticated access';
    const anonymous = await browser.newContext();
    const unauthAdmin = await anonymous.request.get(
        `${base}/api/v1/documents/${imageFixture.id}/download`,
        { maxRedirects: 0, headers: { Accept: 'application/json' } }
    );
    assert.ok([401, 403].includes(unauthAdmin.status()));
    const unauthPortal = await anonymous.request.get(
        `${base}/client/documents/${imageFixture.id}/preview/content`,
        { maxRedirects: 0 }
    );
    assert.ok([302, 401, 403].includes(unauthPortal.status()));
    await anonymous.close();
    check('Unauthenticated admin and portal content cannot expose fixtures');
    assert.deepEqual(errors, []);
    check(
        'Preview pages have no browser errors and script markers never execute'
    );
    await fs.writeFile(
        path.join(output, 'report.json'),
        JSON.stringify(
            { run: fixtures.run, passed: checks.length, checks },
            null,
            2
        )
    );
    console.log(JSON.stringify({ passed: checks.length, checks }, null, 2));
} catch (error) {
    console.error(
        JSON.stringify(
            {
                failed_stage: activeStage,
                passed: checks.length,
                checks,
                error: redact(error.message),
            },
            null,
            2
        )
    );
    throw new Error(redact(error.message));
} finally {
    await browser.close();
}
