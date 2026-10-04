import { chromium, request } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const base = process.env.QA_URL || 'https://portal.integratecore.net';
assert.equal(new URL(base).origin, 'https://portal.integratecore.net');
const local = fileURLToPath(
    new URL('../.local/client-files/', import.meta.url)
);
const session = JSON.parse(
    await fs.readFile(
        process.env.QA_SESSION_FILE ||
            path.join(local, 'production-session.json'),
        'utf8'
    )
);
assert.match(session.run, /^[a-f0-9]{16}$/);
assert.deepEqual(
    session.clients.map((client) => client.numeric_id),
    [7, 6, 10]
);
assert.equal(session.contacts.length, 3);
const output = process.env.QA_OUTPUT_DIR || path.join(local, 'production');
await fs.mkdir(output, { recursive: true });
const secrets = [
    session.token.value,
    ...session.contacts.map((contact) => contact.portal_key),
];
const redact = (value) =>
    secrets.reduce(
        (text, secret) => text.replaceAll(secret, '<redacted>'),
        String(value)
    );
const sha256 = (bytes) =>
    crypto.createHash('sha256').update(bytes).digest('hex');
const checks = [];
const check = (label) => {
    checks.push(label);
    console.log(`PASS ${label}`);
};
const pageErrors = [];
const blockedWrites = [];
const readOnly = async (route) => {
    const candidate = route.request();
    const url = new URL(candidate.url());
    const allowedReadPost =
        candidate.method() === 'POST' &&
        (/^\/api\/v1\/clients\/[^/]+\/documents$/.test(url.pathname) ||
            url.pathname === '/client/documents/download_multiple');
    if (
        url.origin === base &&
        !['GET', 'HEAD', 'OPTIONS'].includes(candidate.method()) &&
        !allowedReadPost
    ) {
        blockedWrites.push({ method: candidate.method(), path: url.pathname });
        await route.abort();
        return;
    }
    await route.continue();
};
const api = await request.newContext({
    baseURL: base,
    extraHTTPHeaders: {
        'X-API-TOKEN': session.token.value,
        Accept: 'application/json',
    },
});
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH,
});
const admin = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    acceptDownloads: true,
});
await admin.route('**/*', readOnly);
await admin.addInitScript(
    ({ token }) => {
        if (
            window === window.top &&
            location.origin === 'https://portal.integratecore.net'
        )
            localStorage?.setItem('X-NINJA-TOKEN', token);
    },
    { token: session.token.value }
);
const page = await admin.newPage();
page.on('pageerror', (error) => pageErrors.push(redact(error.message)));
const portals = [];
const sameIds = (actual, expected) =>
    assert.deepEqual([...actual].sort(), [...expected].sort());
const getData = async (url) => {
    const response = await api.get(url);
    assert.equal(response.status(), 200, url);
    return (await response.json()).data;
};
const deny = (response, label) =>
    assert.ok(
        [403, 404, 422].includes(response.status()),
        `${label}: ${response.status()}`
    );
const csrf = async (portalPage) => {
    const token = await portalPage
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    assert.ok(token);
    return token;
};
const portalLinkIds = async (portalPage) =>
    portalPage
        .locator('.ic-document-library a[href]')
        .evaluateAll((links) => [
            ...new Set(
                links
                    .map(
                        (link) =>
                            new URL(link.href).pathname.match(
                                /^\/client\/documents\/([^/]+)\/(?:preview|download)$/
                            )?.[1]
                    )
                    .filter(Boolean)
            ),
        ]);
const walkPortal = async (portalPage, current = '', visited = new Set()) => {
    assert.ok(!visited.has(current));
    visited.add(current);
    assert.ok(visited.size <= 100, 'Limit production folder traversal');
    await portalPage.goto(
        `${base}/client/documents${current ? `?path=${encodeURIComponent(current)}` : ''}`
    );
    await portalPage.locator('.ic-document-library').waitFor();
    const ids = await portalLinkIds(portalPage);
    const folders = await portalPage
        .locator('.ic-document-library tbody a[href]')
        .evaluateAll((links) => [
            ...new Set(
                links
                    .map((link) => new URL(link.href))
                    .filter(
                        (url) =>
                            url.pathname === '/client/documents' &&
                            url.searchParams.has('path')
                    )
                    .map((url) => url.searchParams.get('path'))
            ),
        ]);
    for (const folder of folders)
        ids.push(...(await walkPortal(portalPage, folder, visited)));
    return [...new Set(ids)];
};
const walkAdmin = async (client, current = '', visited = new Set()) => {
    assert.ok(!visited.has(current));
    visited.add(current);
    assert.ok(visited.size <= 100);
    const library = await getData(
        `/api/v1/clients/${client.id}/file-library/browse${current ? `?path=${encodeURIComponent(current)}` : ''}`
    );
    assert.equal(library.folder, client.folder);
    const ids = library.entries
        .filter((entry) => !entry.is_dir)
        .map((entry) => entry.id);
    for (const entry of library.entries.filter((entry) => entry.is_dir))
        ids.push(...(await walkAdmin(client, entry.path, visited)));
    return ids;
};
let stage = 'native API and catalog';
try {
    const catalogResponse = await api.get('/api/v1/client-file-folders');
    assert.equal(catalogResponse.status(), 200);
    const catalog = await catalogResponse.json();
    assert.equal(catalog.enabled, true);
    assert.equal(catalog.data.length, 2);
    for (const client of session.clients.filter((client) => client.folder)) {
        const entry = catalog.data.find(
            (folder) => folder.folder === client.folder
        );
        assert.equal(entry.client_id, client.id);
        assert.equal(entry.assigned, true);
        assert.equal(entry.assigned_to_other_company, false);
    }
    check(
        'Production catalog has both original client folders and correct owners'
    );
    const allClients = await getData(
        '/api/v1/clients?per_page=100&include=documents'
    );
    for (const client of session.clients) {
        const individual = await getData(
            `/api/v1/clients/${client.id}?include=documents`
        );
        for (const entity of [
            individual,
            allClients.find((entity) => entity.id === client.id),
        ]) {
            assert.ok(entity);
            assert.equal(entity.consulting_hours_balance, client.balance);
            assert.equal(entity.custom_value1, String(client.balance));
            sameIds(
                entity.documents.map((document) => document.id),
                client.native_document_ids
            );
        }
        const nativeResponse = await api.post(
            `/api/v1/clients/${client.id}/documents`,
            { data: {} }
        );
        assert.equal(nativeResponse.status(), 200);
        sameIds(
            (await nativeResponse.json()).data.map((document) => document.id),
            client.native_document_ids
        );
        if (client.folder)
            sameIds(await walkAdmin(client), client.native_document_ids);
        else
            deny(
                await api.get(
                    `/api/v1/clients/${client.id}/file-library/browse`
                ),
                'Unmapped admin library'
            );
    }
    check(
        'Native list/detail/document APIs expose unchanged balances, custom1, and only the selected folder IDs'
    );

    stage = 'administrator browser';
    await page.goto(`${base}/clients`);
    await page
        .getByRole('columnheader', { name: 'Time left (hours)', exact: true })
        .waitFor({ timeout: 60000 });
    assert.equal(
        await page
            .getByRole('columnheader', {
                name: 'Time left (hours)',
                exact: true,
            })
            .count(),
        1
    );
    await page
        .getByRole('button', { name: 'Client folders', exact: true })
        .click();
    const manager = page.getByRole('dialog', {
        name: 'Client folders',
        exact: true,
    });
    await manager.waitFor();
    assert.equal(await manager.locator('tbody tr').count(), 2);
    for (const assignment of catalog.data) {
        const row = manager
            .getByText(assignment.folder, { exact: true })
            .locator('xpath=ancestor::tr[1]');
        await row.waitFor();
        assert.equal(
            await row
                .getByText(assignment.client_name, { exact: true })
                .count(),
            1
        );
        assert.ok(
            await row
                .getByRole('button', { name: 'Save', exact: true })
                .isDisabled()
        );
    }
    await page.setViewportSize({ width: 390, height: 844 });
    for (const assignment of catalog.data) {
        const name = await manager
            .getByText(assignment.folder, { exact: true })
            .boundingBox();
        assert.ok(name && name.x >= 0 && name.x + name.width <= 391);
    }
    await page.screenshot({
        path: path.join(output, 'mobile-client-folders.png'),
        fullPage: true,
    });
    await manager.getByRole('button', { name: 'Close', exact: true }).click();
    await page.goto(`${base}/clients/${session.clients[0].id}`);
    const details = page.getByText('Details', { exact: true });
    await details.waitFor();
    const card = details.locator(
        'xpath=ancestor::div[contains(@class,"shadow") and contains(@class,"rounded")][1]'
    );
    assert.equal(await card.getByText('Time left', { exact: true }).count(), 1);
    assert.equal(
        await card.getByText('Time left (hours)', { exact: true }).count(),
        0
    );
    assert.equal(
        await card.evaluate(
            (element) =>
                [...element.querySelectorAll('*')].filter(
                    (child) =>
                        /^(auto|scroll)$/.test(
                            getComputedStyle(child).overflowY
                        ) && child.scrollHeight > child.clientHeight + 2
                ).length
        ),
        0
    );
    await page.screenshot({
        path: path.join(output, 'mobile-client-details.png'),
        fullPage: true,
    });
    check(
        'Production admin shows one Time left column/field, correct folder owners, and usable mobile layout'
    );

    stage = 'portal isolation';
    for (const client of session.clients) {
        const context = await browser.newContext({
            viewport: { width: 1280, height: 900 },
            acceptDownloads: true,
        });
        await context.route('**/*', readOnly);
        const contact = session.contacts.find(
            (contact) => contact.client_id === client.numeric_id
        );
        const portalPage = await context.newPage();
        portalPage.on('pageerror', (error) =>
            pageErrors.push(redact(error.message))
        );
        const login = await portalPage.goto(
            `${base}/client/key_login/${contact.portal_key}`
        );
        assert.equal(login.status(), 200);
        sameIds(await walkPortal(portalPage), client.public_document_ids);
        await portalPage.goto(`${base}/client/documents`);
        const token = await csrf(portalPage);
        portals.push({ client, context, page: portalPage, csrf: token });
        if (!client.folder) {
            assert.equal(
                await portalPage.locator('.ic-library-empty').count(),
                1
            );
            assert.equal(
                await portalPage
                    .locator('.ic-document-library tbody a')
                    .count(),
                0
            );
        }
    }
    check(
        'Each portal recursively lists only its shared files; unmapped Dev Account library stays empty'
    );
    for (const portal of portals) {
        for (const owner of session.clients) {
            const document = owner.documents.find(
                (document) =>
                    owner.public_document_ids.includes(document.id) &&
                    document.sha256
            );
            if (!document) continue;
            const own = portal.client.numeric_id === owner.numeric_id;
            const download = await portal.context.request.get(
                `${base}/client/documents/${document.id}/download`
            );
            const preview = await portal.context.request.get(
                `${base}/client/documents/${document.id}/preview`
            );
            const media = await portal.context.request.get(
                `${base}/client/documents/${document.id}/preview/content`
            );
            const zipped = await portal.context.request.post(
                `${base}/client/documents/download_multiple`,
                { form: { _token: portal.csrf, 'file_hash[]': document.id } }
            );
            if (own) {
                assert.equal(download.status(), 200);
                assert.equal(sha256(await download.body()), document.sha256);
                assert.equal(preview.status(), 200);
                assert.ok([200, 415].includes(media.status()));
                assert.equal(zipped.status(), 200);
                assert.ok(
                    (await zipped.body())
                        .subarray(0, 2)
                        .equals(Buffer.from('PK'))
                );
            } else {
                assert.equal(download.status(), 403);
                assert.equal(preview.status(), 403);
                assert.equal(media.status(), 403);
                assert.equal(zipped.status(), 403);
            }
        }
        for (const document of portal.client.documents.filter(
            (document) =>
                !portal.client.public_document_ids.includes(document.id)
        )) {
            assert.equal(
                (
                    await portal.context.request.get(
                        `${base}/client/documents/${document.id}/download`
                    )
                ).status(),
                403
            );
            assert.equal(
                (
                    await portal.context.request.get(
                        `${base}/client/documents/${document.id}/preview`
                    )
                ).status(),
                403
            );
        }
    }
    check(
        'Public downloads/previews/ZIPs preserve bytes; cross-client, unmapped, and private requests are denied'
    );

    stage = 'original entity attachments';
    let entityAttachments = 0;
    for (const client of session.clients)
        for (const document of client.documents.filter(
            (document) =>
                document.documentable_type !== 'App\\Models\\Client' &&
                document.sha256
        )) {
            const download = await api.get(
                `/api/v1/documents/${document.id}/download`
            );
            assert.equal(download.status(), 200);
            assert.equal(sha256(await download.body()), document.sha256);
            if (client.public_document_ids.includes(document.id)) {
                const portal = portals.find(
                    (portal) => portal.client.numeric_id === client.numeric_id
                );
                const own = await portal.context.request.get(
                    `${base}/client/documents/${document.id}/download`
                );
                assert.equal(own.status(), 200);
                assert.equal(sha256(await own.body()), document.sha256);
            }
            entityAttachments++;
        }
    check(
        `Original invoice/project/entity attachment downloads retain relationships and bytes (${entityAttachments} checked)`
    );

    stage = 'traversal and unauthenticated denial';
    const traversal = [
        '../',
        '..\\',
        '/etc/passwd',
        '../Ronnie Pollack - Golf Net',
        '.DS_Store',
    ];
    for (const candidate of traversal) {
        for (const portal of portals) {
            deny(
                await portal.context.request.get(
                    `${base}/client/documents?path=${encodeURIComponent(candidate)}`
                ),
                'Portal path traversal'
            );
            deny(
                await portal.context.request.get(
                    `${base}/client/document-library/archive?path=${encodeURIComponent(candidate)}`
                ),
                'Portal ZIP traversal'
            );
        }
        deny(
            await api.get(
                `/api/v1/clients/${session.clients[0].id}/file-library/browse?path=${encodeURIComponent(candidate)}`
            ),
            'Admin path traversal'
        );
        deny(
            await api.get(
                `/api/v1/clients/${session.clients[0].id}/file-library/archive?path=${encodeURIComponent(candidate)}`
            ),
            'Admin ZIP traversal'
        );
    }
    const guest = await request.newContext({ baseURL: base });
    const selected = session.clients[0].documents.find((document) =>
        session.clients[0].public_document_ids.includes(document.id)
    );
    assert.ok(selected);
    for (const ending of ['download', 'preview', 'preview/content']) {
        const response = await guest.get(
            `/client/documents/${selected.id}/${ending}`,
            { maxRedirects: 0 }
        );
        assert.ok([302, 401, 403].includes(response.status()));
    }
    await guest.dispose();
    check(
        'Traversal and unauthenticated preview/download requests cannot expose production files'
    );
    for (const portal of portals.filter((portal) => portal.client.folder)) {
        await portal.page.setViewportSize({ width: 390, height: 844 });
        await portal.page.goto(`${base}/client/documents`);
        assert.ok(
            await portal.page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth
            )
        );
        await portal.page.screenshot({
            path: path.join(
                output,
                `portal-${portal.client.numeric_id}-mobile.png`
            ),
            fullPage: true,
        });
    }
    assert.deepEqual(pageErrors, []);
    assert.deepEqual(blockedWrites, []);
    check(
        'Production browser flows have no page errors or attempted business mutations'
    );
    await fs.writeFile(
        path.join(output, 'report.json'),
        JSON.stringify(
            {
                run: session.run,
                passed: checks.length,
                checks,
                entityAttachments,
            },
            null,
            2
        )
    );
    console.log(
        JSON.stringify(
            { passed: checks.length, checks, entityAttachments },
            null,
            2
        )
    );
} catch (error) {
    await page
        .screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
        .catch(() => {});
    console.error(
        JSON.stringify(
            {
                failed_stage: stage,
                passed: checks.length,
                checks,
                error: redact(error.message),
                blockedWrites,
                pageErrors,
            },
            null,
            2
        )
    );
    throw new Error(redact(error.message));
} finally {
    await Promise.all([api.dispose(), browser.close()]);
}
