import { chromium } from '@playwright/test';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const base = process.env.QA_URL || 'http://192.168.1.119:8082';
const url = new URL(base);
assert.equal(url.hostname, '192.168.1.119');
assert.equal(url.port, '8082');
const sessionFile =
    process.env.QA_SESSION_FILE ||
    fileURLToPath(
        new URL('../.local/client-files/browser-session.json', import.meta.url)
    );
const outputDirectory =
    process.env.QA_OUTPUT_DIR ||
    fileURLToPath(new URL('../.local/client-files/', import.meta.url));
await fs.mkdir(outputDirectory, { recursive: true });
const artifactPath = (name) => path.join(outputDirectory, name);
const session = JSON.parse(await fs.readFile(sessionFile, 'utf8'));
const navigation = [];
const safeUrl = (value) => {
    const target = new URL(value);
    target.search = '';
    target.hash = '';
    target.pathname = target.pathname.replace(
        /(\/(?:key_login|magic_link)\/)[^/]+/,
        '$1<redacted>'
    );
    return target.toString();
};
const observeNavigation = (page) => {
    page.on('response', (response) => {
        if (!response.request().isNavigationRequest()) return;
        const location = response.headers().location;
        navigation.push({
            status: response.status(),
            url: safeUrl(response.url()),
            redirect: location
                ? safeUrl(new URL(location, response.url()).href)
                : null,
        });
    });
};
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH,
});
const report = [];
const pageErrors = [];
const admin = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    acceptDownloads: true,
});
await admin.addInitScript(
    ({ token }) => localStorage.setItem('X-NINJA-TOKEN', token),
    session
);
const page = await admin.newPage();
observeNavigation(page);
page.on('pageerror', (e) => pageErrors.push(e.message));
try {
    await page.goto(`${base}/clients/${session.client_id}/documents_overview`);
    await page
        .getByRole('button', { name: 'Download folder as ZIP', exact: true })
        .waitFor({ timeout: 60000 });
    await page
        .getByRole('button', { name: 'Project Docs', exact: true })
        .waitFor();
    assert.equal(await page.getByText('.DS_Store', { exact: true }).count(), 0);
    report.push('Admin library groups nested documents into folders');
    await page.screenshot({
        path: artifactPath('admin-library.png'),
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Project Docs', exact: true })
        .click();
    await page
        .locator('nav[aria-label="Documents"]')
        .getByRole('button', { name: 'Project Docs', exact: true })
        .waitFor();
    report.push('Admin folder navigation works');
    await page
        .locator('nav[aria-label="Documents"]')
        .getByRole('button', { name: 'Documents', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Project Docs', exact: true })
        .waitFor();
    report.push('Admin breadcrumb returns to library root');
    const zipEvent = page.waitForEvent('download');
    await page
        .getByRole('button', { name: 'Download folder as ZIP', exact: true })
        .click();
    const zip = await zipEvent;
    await zip.saveAs(artifactPath('admin-library.zip'));
    assert.match(zip.suggestedFilename(), /\.zip$/);
    report.push('Admin folder ZIP download completes');
    await page.goto(`${base}/clients`);
    await page
        .getByRole('columnheader', { name: 'Time left (hours)', exact: true })
        .waitFor({ timeout: 30000 });
    report.push('Clients table shows Time left column');
    const portal = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        acceptDownloads: true,
    });
    const client = await portal.newPage();
    observeNavigation(client);
    client.on('pageerror', (e) => pageErrors.push(e.message));
    await client.goto(`${base}/client/key_login/${session.portal_key}`);
    await client.goto(`${base}/client/documents`);
    await client
        .getByRole('link', { name: 'Project Docs', exact: true })
        .waitFor({ timeout: 30000 });
    assert.equal(
        await client.getByText('.DS_Store', { exact: true }).count(),
        0
    );
    const broken = await client
        .locator('a[href]')
        .evaluateAll((links) =>
            links
                .map((a) => a.href)
                .filter(
                    (h) =>
                        h.startsWith('http://192.168.1.119/') ||
                        h.startsWith('http://100.69.78.58:8082')
                )
        );
    assert.deepEqual(broken, []);
    report.push('Portal document links retain dev port');
    await client.screenshot({
        path: artifactPath('portal-library.png'),
        fullPage: true,
    });
    await client
        .getByRole('link', { name: 'Project Docs', exact: true })
        .click();
    await client
        .locator('nav[aria-label="Documents"]')
        .getByRole('link', { name: 'Project Docs', exact: true })
        .waitFor();
    report.push('Portal folder navigation works');
    await client
        .locator('nav[aria-label="Documents"]')
        .getByRole('link', { name: 'Documents', exact: true })
        .click();
    await client
        .getByRole('link', { name: 'Project Docs', exact: true })
        .waitFor();
    report.push('Portal breadcrumb returns to root');
    const clientZipEvent = client.waitForEvent('download');
    await client
        .getByRole('link', { name: 'Download folder as ZIP', exact: true })
        .click();
    const clientZip = await clientZipEvent;
    await clientZip.saveAs(artifactPath('portal-library.zip'));
    report.push('Portal folder ZIP download completes');
    await client.setViewportSize({ width: 390, height: 844 });
    await client.goto(`${base}/client/documents`);
    await client
        .getByRole('link', { name: 'Project Docs', exact: true })
        .waitFor();
    assert.ok(
        await client.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth
        )
    );
    await client.screenshot({
        path: artifactPath('portal-library-mobile.png'),
        fullPage: true,
    });
    report.push('Mobile portal library has no horizontal page overflow');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/clients/${session.client_id}/documents_overview`);
    await page
        .getByRole('button', { name: 'Download folder as ZIP', exact: true })
        .waitFor();
    await page.screenshot({
        path: artifactPath('admin-library-mobile.png'),
        fullPage: true,
    });
    report.push('Mobile React admin renders the document library');
    assert.deepEqual(pageErrors, []);
    report.push('No browser page errors');
    console.log(
        JSON.stringify({ passed: report.length, checks: report }, null, 2)
    );
    await portal.close();
} catch (error) {
    console.error(
        JSON.stringify(
            { passed: report.length, checks: report, navigation },
            null,
            2
        )
    );
    const message = error.message
        .replaceAll(session.portal_key, '<redacted>')
        .replaceAll(session.token, '<redacted>');
    throw new Error(message);
} finally {
    await browser.close();
}
