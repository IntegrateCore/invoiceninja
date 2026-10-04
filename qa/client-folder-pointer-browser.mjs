import { chromium, request } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const base = process.env.QA_URL || 'http://192.168.1.119:8082';
assert.equal(new URL(base).origin, 'http://192.168.1.119:8082');
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
        process.env.QA_FOLDER_FIXTURES ||
            path.join(local, 'folder-pointer-fixtures.json'),
        'utf8'
    )
);
assert.match(fixtures.run, /^[a-f0-9]{16}$/);
assert.equal(fixtures.clients.length, 2);
assert.equal(fixtures.folders.length, 2);
const helperSource = await fs.readFile(
    fileURLToPath(
        new URL('./dev-folder-pointer-fixtures.php', import.meta.url)
    ),
    'utf8'
);
const output = process.env.QA_OUTPUT_DIR || path.join(local, 'folder-pointer');
await fs.mkdir(output, { recursive: true });
const [first, second] = fixtures.clients;
const [folderA, folderB] = fixtures.folders;
const publicA = fixtures.files.find((file) => file.name === 'public-a.txt');
const privateA = fixtures.files.find((file) => file.name === 'private-a.txt');
const projectA = fixtures.files.find((file) => file.name === 'project-a.txt');
const publicB = fixtures.files.find((file) => file.name === 'public-b.txt');
const secrets = [
    session.token,
    session.portal_key,
    fixtures.token.value,
    ...fixtures.contacts.map((contact) => contact.portal_key),
];
const redact = (value) =>
    secrets.reduce(
        (text, secret) => text.replaceAll(secret, '<redacted>'),
        String(value)
    );
const checks = [];
const check = (label) => {
    checks.push(label);
    console.log(`PASS ${label}`);
};
const sha256 = (bytes) =>
    crypto.createHash('sha256').update(bytes).digest('hex');
const adminApi = await request.newContext({
    baseURL: base,
    extraHTTPHeaders: {
        'X-API-TOKEN': session.token,
        Accept: 'application/json',
    },
});
const editorApi = await request.newContext({
    baseURL: base,
    extraHTTPHeaders: {
        'X-API-TOKEN': fixtures.token.value,
        Accept: 'application/json',
    },
});
const catalog = async () => {
    const response = await adminApi.get('/api/v1/client-file-folders');
    assert.equal(response.status(), 200);
    const body = await response.json();
    assert.equal(body.enabled, true);
    return body.data;
};
const listing = async (client) => {
    const response = await adminApi.get(
        `/api/v1/clients/${client.id}/file-library/browse`
    );
    assert.equal(response.status(), 200);
    return (await response.json()).data;
};
const originalAttachment = async () => {
    const response = await adminApi.get(
        `/api/v1/documents/${projectA.id}/download`
    );
    assert.equal(response.status(), 200);
    assert.equal(sha256(await response.body()), projectA.sha256);
};
const inspect = (label) => {
    const result = execFileSync(
        'ssh',
        [
            'brates-server',
            `docker exec -i -u www-data invoiceninja-dev-invoiceninja-1 php /dev/stdin inspect ${fixtures.run}`,
        ],
        {
            input: helperSource,
            encoding: 'utf8',
            timeout: 60000,
            maxBuffer: 1024 * 1024,
        }
    );
    const snapshot = JSON.parse(result);
    assert.equal(snapshot.business_mappings_unchanged, true);
    assert.equal(Object.keys(snapshot.checksums).length, fixtures.files.length);
    for (const file of fixtures.files)
        assert.equal(snapshot.checksums[file.path], file.sha256);
    const attachment = snapshot.documents.find(
        (document) => document.numeric_id === projectA.numeric_id
    );
    assert.equal(attachment.documentable_type, projectA.documentable_type);
    assert.equal(attachment.documentable_id, projectA.documentable_id);
    assert.equal(attachment.path, projectA.path);
    assert.equal(attachment.hash, projectA.hash);
    assert.equal(attachment.is_public, projectA.is_public);
    assert.equal(attachment.deleted, false);
    check(
        `${label}: original bytes, project attachment, and business mappings stay unchanged`
    );
    return snapshot;
};
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH,
});
const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    acceptDownloads: true,
});
await context.addInitScript(({ token }) => {
    if (
        window === window.top &&
        location.origin === 'http://192.168.1.119:8082'
    )
        localStorage?.setItem('X-NINJA-TOKEN', token);
}, session);
const page = await context.newPage();
const errors = [];
const catalogResponses = [];
page.on('pageerror', (error) => errors.push(redact(error.message)));
page.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/client-file-folders') {
        catalogResponses.push({
            method: response.request().method(),
            status: response.status(),
        });
    }
});
const firstPortal = await browser.newContext({ acceptDownloads: true });
const secondPortal = await browser.newContext({ acceptDownloads: true });
let stage = 'opening folder manager';
const openManager = async () => {
    await page.goto(`${base}/clients`);
    await page
        .getByRole('button', { name: 'Client folders', exact: true })
        .waitFor({ timeout: 60000 });
    await page
        .getByRole('button', { name: 'Client folders', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'Client folders',
        exact: true,
    });
    await dialog.waitFor();
    await dialog.getByText(folderA, { exact: true }).waitFor();
    return dialog;
};
const folderRow = (dialog, folder) =>
    dialog.getByText(folder, { exact: true }).locator('xpath=ancestor::tr[1]');
const saveAssignment = async (
    dialog,
    folder,
    client,
    verifyRemoteSearch = false
) => {
    const row = folderRow(dialog, folder);
    const combo = row.getByRole('combobox');
    if (client) {
        const searchResponse = verifyRemoteSearch
            ? page.waitForResponse((response) => {
                  const url = new URL(response.url());
                  return (
                      url.pathname === '/api/v1/clients' &&
                      url.searchParams.get('filter') === client.name
                  );
              })
            : null;
        await combo.click();
        await combo.fill(client.name);
        const option = row.getByRole('option', {
            name: client.name,
            exact: true,
        });
        if (searchResponse) {
            const refreshed = await searchResponse;
            assert.equal(refreshed.status(), 200);
            await refreshed.json();
            await option.waitFor();
            assert.equal(await combo.getAttribute('aria-expanded'), 'true');
            assert.ok(
                await combo.evaluate(
                    (input) => document.activeElement === input
                )
            );
            check(
                'Remote owner search stays open and focused after refreshing client results'
            );
        }
        await option.click();
    } else {
        // XMark does not forward the shared selector's data-testid prop.
        await combo.locator('xpath=following-sibling::button[1]').click();
    }
    const [response] = await Promise.all([
        page.waitForResponse(
            (candidate) =>
                new URL(candidate.url()).pathname ===
                    '/api/v1/client-file-folders' &&
                candidate.request().method() === 'PUT'
        ),
        row.getByRole('button', { name: 'Save', exact: true }).click(),
    ]);
    assert.equal(response.status(), 200);
    assert.deepEqual(response.request().postDataJSON(), {
        folder,
        client_id: client?.id ?? null,
    });
    const body = await response.json();
    assert.equal(body.enabled, true);
    const assignment = body.data.find((entry) => entry.folder === folder);
    assert.equal(assignment.client_id, client?.id ?? null);
    await page.screenshot({
        path: path.join(output, 'after-assignment.png'),
        fullPage: true,
    });
    await row
        .getByRole('button', { name: 'Save', exact: true })
        .waitFor({ timeout: 60000 });
    assert.ok(
        await row
            .getByRole('button', { name: 'Save', exact: true })
            .isDisabled()
    );
    return assignment;
};
const closeManager = async (dialog) => {
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
};
const portalLogin = async (portal, index) => {
    const response = await portal.request.get(
        `${base}/client/key_login/${fixtures.contacts[index].portal_key}`
    );
    assert.equal(response.status(), 200);
};
const assertMobileFolderNames = async (dialog) => {
    const viewport = page.viewportSize();
    const manager = await dialog.boundingBox();
    assert.ok(manager);
    for (const assignment of await catalog()) {
        const name = await dialog
            .getByText(assignment.folder, { exact: true })
            .boundingBox();
        assert.ok(name, `Folder name is missing: ${assignment.folder}`);
        assert.ok(name.x >= Math.max(0, manager.x) - 1);
        assert.ok(
            name.x + name.width <=
                Math.min(viewport.width, manager.x + manager.width) + 1
        );
    }
};
try {
    for (const assignment of await catalog()) {
        if (
            !fixtures.folders.includes(assignment.folder) ||
            !assignment.assigned
        )
            continue;
        assert.ok(
            fixtures.clients.some(
                (client) => client.id === assignment.client_id
            ),
            'A QA folder was assigned outside this fixture run'
        );
        const cleared = await adminApi.put('/api/v1/client-file-folders', {
            data: { folder: assignment.folder, client_id: null },
        });
        assert.equal(cleared.status(), 200);
    }
    const initial = await catalog();
    for (const folder of fixtures.folders)
        assert.equal(
            initial.find((entry) => entry.folder === folder).assigned,
            false
        );
    let dialog = await openManager();
    assert.equal(await dialog.locator('tbody tr').count(), initial.length);
    for (const assignment of initial) {
        const row = folderRow(dialog, assignment.folder);
        await row.waitFor();
        if (assignment.client_name)
            assert.equal(
                await row
                    .getByText(assignment.client_name, { exact: true })
                    .count(),
                1
            );
    }
    check('Admin Client folders manager lists all folders and their owners');
    await saveAssignment(dialog, folderA, first);
    await closeManager(dialog);
    const initialListing = await listing(first);
    assert.equal(initialListing.folder, folderA);
    assert.deepEqual(initialListing.entries.map((entry) => entry.name).sort(), [
        'private-a.txt',
        'project-a.txt',
        'public-a.txt',
    ]);
    assert.equal(
        initialListing.entries.find((entry) => entry.name === privateA.name)
            .is_public,
        false
    );
    check(
        'Assigning folder A makes the new client view A files with private visibility preserved'
    );
    inspect('Initial assignment');
    await portalLogin(firstPortal, 0);
    await portalLogin(secondPortal, 1);
    const originalPublicDownload = await firstPortal.request.get(
        `${base}/client/documents/${publicA.id}/download`
    );
    assert.equal(originalPublicDownload.status(), 200);
    assert.equal(sha256(await originalPublicDownload.body()), publicA.sha256);
    assert.equal(
        (
            await firstPortal.request.get(
                `${base}/client/documents/${privateA.id}/download`
            )
        ).status(),
        403
    );
    check(
        'First portal contact can read its public file while its private file stays denied'
    );
    await page.goto(`${base}/clients/${first.id}/documents_overview`);
    await page
        .getByRole('button', { name: publicA.name, exact: true })
        .waitFor();
    await page.getByRole('button', { name: publicA.name, exact: true }).click();
    const preview = page.getByRole('dialog', {
        name: publicA.name,
        exact: true,
    });
    await preview.locator('pre code').waitFor();
    assert.equal(
        await preview.locator('pre code').textContent(),
        `Folder QA ${fixtures.run} public A\n`
    );
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        preview.getByRole('button', { name: 'Download', exact: true }).click(),
    ]);
    const downloaded = path.join(output, 'public-a.txt');
    await download.saveAs(downloaded);
    assert.equal(sha256(await fs.readFile(downloaded)), publicA.sha256);
    await preview.getByRole('button', { name: 'Close', exact: true }).click();
    const portalPreview = await firstPortal.request.get(
        `${base}/client/documents/${publicA.id}/preview`
    );
    assert.equal(portalPreview.status(), 200);
    assert.ok((await portalPreview.text()).includes('ic-document-preview'));
    assert.equal(
        (
            await secondPortal.request.get(
                `${base}/client/documents/${publicA.id}/preview`
            )
        ).status(),
        403
    );
    check(
        'Assigned files retain protected admin/portal previews and explicit original-byte downloads'
    );

    stage = 'switching view from A to B';
    dialog = await openManager();
    await saveAssignment(dialog, folderB, first);
    await closeManager(dialog);
    const switched = await listing(first);
    assert.equal(switched.folder, folderB);
    assert.deepEqual(
        switched.entries.map((entry) => entry.name),
        ['public-b.txt']
    );
    const bIndex = switched.entries[0];
    assert.equal(
        (await catalog()).find((entry) => entry.folder === folderA).assigned,
        false
    );
    await originalAttachment();
    const preservedPortalAttachment = await firstPortal.request.get(
        `${base}/client/documents/${projectA.id}/download`
    );
    assert.equal(preservedPortalAttachment.status(), 200);
    assert.equal(
        sha256(await preservedPortalAttachment.body()),
        projectA.sha256
    );
    assert.ok(
        [403, 404].includes(
            (
                await firstPortal.request.get(
                    `${base}/client/documents/${publicA.id}/download`
                )
            ).status()
        )
    );
    const afterSwitch = inspect('Pointer A to B');
    assert.equal(
        afterSwitch.references.find(
            (reference) => reference.document_id === projectA.numeric_id
        ).client_id,
        first.numeric_id
    );
    check(
        'Switching to B changes listed filenames and retains the original project attachment download'
    );

    stage = 'transferring B to the second client';
    dialog = await openManager();
    await saveAssignment(dialog, folderB, second, true);
    await closeManager(dialog);
    assert.equal(
        (await catalog()).find((entry) => entry.folder === folderB).client_id,
        second.id
    );
    const secondB = await listing(second);
    assert.equal(secondB.entries[0].id, bIndex.id);
    assert.equal(secondB.entries[0].hash, bIndex.hash);
    assert.equal(
        (
            await firstPortal.request.get(
                `${base}/client/documents/${bIndex.id}/download`
            )
        ).status(),
        403
    );
    const allowedSecondB = await secondPortal.request.get(
        `${base}/client/documents/${bIndex.id}/download`
    );
    assert.equal(allowedSecondB.status(), 200);
    assert.equal(sha256(await allowedSecondB.body()), publicB.sha256);
    inspect('Transfer B');
    check(
        'Transferring B preserves its index/hash and immediately revokes the old portal contact'
    );

    stage = 'transferring A and private flags';
    dialog = await openManager();
    await saveAssignment(dialog, folderA, second);
    await closeManager(dialog);
    const secondA = await listing(second);
    assert.equal(secondA.folder, folderA);
    assert.deepEqual(secondA.entries.map((entry) => entry.name).sort(), [
        'private-a.txt',
        'project-a.txt',
        'public-a.txt',
    ]);
    const movedPublic = secondA.entries.find(
        (entry) => entry.name === publicA.name
    );
    const movedPrivate = secondA.entries.find(
        (entry) => entry.name === privateA.name
    );
    const movedProject = secondA.entries.find(
        (entry) => entry.name === projectA.name
    );
    assert.equal(movedPublic.id, publicA.id);
    assert.equal(movedPublic.hash, publicA.hash);
    assert.equal(movedPrivate.id, privateA.id);
    assert.equal(movedPrivate.is_public, false);
    assert.notEqual(movedProject.id, projectA.id);
    assert.equal(
        (
            await firstPortal.request.get(
                `${base}/client/documents/${movedPublic.id}/download`
            )
        ).status(),
        403
    );
    assert.equal(
        (
            await secondPortal.request.get(
                `${base}/client/documents/${movedPrivate.id}/download`
            )
        ).status(),
        403
    );
    await originalAttachment();
    assert.equal(
        (
            await firstPortal.request.get(
                `${base}/client/documents/${projectA.id}/download`
            )
        ).status(),
        200
    );
    const afterTransfer = inspect('Transfer A');
    assert.equal(
        afterTransfer.documents.find(
            (document) => document.numeric_id === privateA.numeric_id
        ).is_public,
        false
    );
    check(
        'Transfer A reuses direct indexes, preserves private flags, and keeps entity relationships downloadable'
    );

    stage = 'non-admin authorization';
    assert.equal(
        (await editorApi.get(`/api/v1/clients/${first.id}`)).status(),
        200
    );
    assert.equal(
        (
            await editorApi.put(`/api/v1/clients/${first.id}`, {
                data: { name: first.name },
            })
        ).status(),
        200
    );
    const forbidden = [
        ['GET', '/api/v1/client-file-folders', undefined],
        ['GET', `/api/v1/clients/${first.id}/file-library/folders`, undefined],
        [
            'PUT',
            `/api/v1/clients/${first.id}/file-library`,
            { folder: folderA },
        ],
        [
            'PUT',
            '/api/v1/client-file-folders',
            { folder: folderA, client_id: first.id },
        ],
        [
            'PUT',
            '/api/v1/client-file-folders',
            { folder: folderA, client_id: null },
        ],
    ];
    for (const [method, endpoint, data] of forbidden) {
        const response = await editorApi.fetch(endpoint, { method, data });
        assert.equal(response.status(), 403);
    }
    const editorContext = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
    });
    await editorContext.addInitScript(
        ({ token }) => {
            if (
                window === window.top &&
                location.origin === 'http://192.168.1.119:8082'
            )
                localStorage?.setItem('X-NINJA-TOKEN', token);
        },
        { token: fixtures.token.value }
    );
    const editorPage = await editorContext.newPage();
    editorPage.on('pageerror', (error) => errors.push(redact(error.message)));
    await editorPage.goto(`${base}/clients`);
    await editorPage
        .getByRole('columnheader', { name: 'Name', exact: true })
        .waitFor({ timeout: 30000 });
    assert.equal(
        await editorPage
            .getByRole('button', { name: 'Client folders', exact: true })
            .count(),
        0
    );
    await editorContext.close();
    check(
        'Non-admin edit-client user can edit its client but cannot see picker/catalog or assign/unassign folders'
    );

    stage = 'mobile assignment and clearing';
    await page.setViewportSize({ width: 390, height: 844 });
    dialog = await openManager();
    assert.ok(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth
        )
    );
    await folderRow(dialog, folderA).getByRole('combobox').click();
    await assertMobileFolderNames(dialog);
    await saveAssignment(dialog, folderA, null);
    const unassigned = await catalog();
    assert.equal(
        unassigned.find((entry) => entry.folder === folderA).assigned,
        false
    );
    const afterClear = inspect('Mobile clear');
    assert.equal(
        afterClear.tenants.find((tenant) => tenant.folder === folderA)
            .company_id,
        fixtures.company_id
    );
    await saveAssignment(dialog, folderA, second);
    await assertMobileFolderNames(dialog);
    await page.screenshot({
        path: path.join(output, 'mobile-client-folder-manager.png'),
        fullPage: true,
    });
    await closeManager(dialog);
    check(
        'Mobile folder manager supports clear/save/reassign and keeps folder names inside the dialog after focus'
    );

    stage = 'Time left and Details card';
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`${base}/clients`);
    await page
        .getByRole('columnheader', { name: 'Time left (hours)', exact: true })
        .waitFor();
    assert.equal(
        await page
            .getByRole('columnheader', {
                name: 'Time left (hours)',
                exact: true,
            })
            .count(),
        1
    );
    await page.goto(`${base}/clients/${session.client_id}`);
    const detailsTitle = page.getByText('Details', { exact: true });
    await detailsTitle.waitFor();
    const card = detailsTitle.locator(
        'xpath=ancestor::div[contains(@class,"shadow") and contains(@class,"rounded")][1]'
    );
    assert.equal(await card.getByText('Time left', { exact: true }).count(), 1);
    assert.equal(
        await card.getByText('Time left (hours)', { exact: true }).count(),
        0
    );
    for (const width of [1440, 390]) {
        await page.setViewportSize({
            width,
            height: width === 390 ? 844 : 1000,
        });
        const scroll = await card.evaluate((element) => ({
            overflow: getComputedStyle(element).overflowY,
            innerScrolls: [...element.querySelectorAll('*')].filter(
                (child) =>
                    /^(auto|scroll)$/.test(getComputedStyle(child).overflowY) &&
                    child.scrollHeight > child.clientHeight + 2
            ).length,
        }));
        assert.equal(scroll.overflow, 'visible');
        assert.equal(scroll.innerScrolls, 0);
    }
    await page.screenshot({
        path: path.join(output, 'mobile-client-details.png'),
        fullPage: true,
    });
    check(
        'Clients table and Details show Time left once; Details has no nested scroll on desktop/mobile'
    );
    inspect('Final QA');
    assert.deepEqual(errors, []);
    check('Folder pointer flows produce no browser page errors');
    await fs.writeFile(
        path.join(output, 'report.json'),
        JSON.stringify(
            {
                run: fixtures.run,
                passed: checks.length,
                checks,
            },
            null,
            2
        )
    );
    console.log(JSON.stringify({ passed: checks.length, checks }, null, 2));
} catch (error) {
    await page
        .screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
        .catch(() => {});
    console.error(
        JSON.stringify({
            catalogResponses,
            visible_options: await page
                .getByRole('option')
                .allTextContents()
                .catch(() => []),
            buttons: await page
                .getByRole('dialog')
                .getByRole('button')
                .allTextContents()
                .catch(() => []),
        })
    );
    console.error(
        JSON.stringify(
            {
                failed_stage: stage,
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
    await Promise.all([
        adminApi.dispose(),
        editorApi.dispose(),
        browser.close(),
    ]);
}
