import { expect, test } from "@playwright/test";
import AdmZip from "adm-zip";
import { createHash } from "node:crypto";
import { stat } from "node:fs/promises";
import { parseFixtureJson, runWp } from "../support/process";
import { processQueue } from "../support/queue";

type ActivationState = {
    core_active: boolean;
    export_active: boolean;
    schema: number;
    tables: Record<string, boolean>;
    active_index: boolean;
    cron: boolean;
    enabled: boolean;
    directories: Record<string, boolean>;
};

type PostFixture = { id: number; url: string; marker: string };
type ArtifactState = {
    exists: boolean;
    content: string;
    row: null | Record<string, unknown>;
};

test.describe
    .serial("Static WP Publisher on a real WordPress installation", () => {
    test("activates with its schema, storage and worker schedule", async () => {
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const state = parseFixtureJson<ActivationState>(
            runWp(["swpp-e2e", "state"]),
        );

        expect(state.core_active).toBe(true);
        expect(state.export_active).toBe(true);
        expect(state.schema).toBe(2);
        expect(state.tables).toEqual({
            jobs: true,
            builds: true,
            artifacts: true,
        });
        expect(state.active_index).toBe(true);
        expect(state.cron).toBe(true);
        expect(state.enabled).toBe(false);
        expect(state.directories).toEqual({
            published: true,
            versions: true,
            tmp: true,
            manifests: true,
        });
    });

    test("publishes, serves, refreshes and exports static HTML", async ({
        context,
        request,
        page,
    }, testInfo) => {
        testInfo.setTimeout(180_000);
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const post = parseFixtureJson<PostFixture>(
            runWp(["swpp-e2e", "create", "v1"]),
        );

        await processQueue(request);
        let artifact = parseFixtureJson<ArtifactState>(
            runWp(["swpp-e2e", "artifact", post.url]),
        );
        expect(artifact.exists).toBe(true);
        expect(artifact.row).not.toBeNull();
        expect(artifact.content).toContain('data-swpp-e2e="v1"');

        parseFixtureJson(runWp(["swpp-e2e", "enable"]));
        const anonymous = await request.get(post.url);
        expect(anonymous.ok()).toBe(true);
        expect(anonymous.headers()["x-static-wp-publisher"]).toBe("HIT");
        expect(await anonymous.text()).toContain('data-swpp-e2e="v1"');

        parseFixtureJson(runWp(["swpp-e2e", "update", String(post.id), "v2"]));
        await processQueue(request);
        artifact = parseFixtureJson<ArtifactState>(
            runWp(["swpp-e2e", "artifact", post.url]),
        );
        expect(artifact.content).toContain('data-swpp-e2e="v2"');

        const refreshed = await request.get(post.url);
        expect(refreshed.headers()["x-static-wp-publisher"]).toBe("HIT");
        expect(await refreshed.text()).toContain('data-swpp-e2e="v2"');

        await context.addCookies([
            {
                name: "comment_author_swpp_e2e",
                value: "integration-test",
                url: new URL(post.url).origin,
            },
        ]);
        const bypassed = await page.goto(post.url);
        expect(bypassed).not.toBeNull();
        expect(bypassed?.headers()["x-static-wp-publisher"]).toBeUndefined();
        expect(await page.content()).toContain('data-swpp-e2e="v2"');

        await page.goto("/wp-login.php");
        await page.locator("#user_login").fill("admin");
        await page.locator("#user_pass").fill("password");
        await page.locator("#wp-submit").click();
        await expect(page).toHaveURL(/\/wp-admin\//);

        await page.goto("/wp-admin/admin.php?page=static-wp-publisher-export");
        await expect(
            page.getByRole("heading", { name: "Portable Export" }),
        ).toBeVisible();
        await page
            .getByRole("button", { name: "Create portable export" })
            .click();
        const downloadLink = page.getByRole("link", { name: "Download ZIP" });
        await expect(downloadLink).toBeVisible();

        const downloadPromise = page.waitForEvent("download");
        await downloadLink.click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/\.zip$/i);
        expect(await download.failure()).toBeNull();
        const downloadPath = await download.path();
        expect(downloadPath).not.toBeNull();
        const archive = await stat(downloadPath as string);
        expect(archive.size).toBeGreaterThan(100);

        const zip = new AdmZip(downloadPath as string);
        const entries = zip.getEntries().map((entry) => entry.entryName);
        expect(entries).toContain("swpp-manifest.json");
        expect(entries).toContain("README-DEPLOYMENT.txt");
        const exportedPagePath = `${new URL(post.url).pathname.replace(
            /^\/+|\/+$/g,
            "",
        )}/index.html`;
        expect(entries).toContain(exportedPagePath);

        const exportedPage = zip.readFile(exportedPagePath);
        expect(exportedPage).not.toBeNull();
        expect(exportedPage?.toString("utf8")).toContain('data-swpp-e2e="v2"');
        const manifest = JSON.parse(zip.readAsText("swpp-manifest.json")) as {
            mode: string;
            files: Record<string, { sha256: string; bytes: number }>;
        };
        expect(manifest.mode).toBe("relocatable");
        expect(manifest.files[exportedPagePath]?.sha256).toBe(
            createHash("sha256")
                .update(exportedPage as Buffer)
                .digest("hex"),
        );
        expect(manifest.files[exportedPagePath]?.bytes).toBe(
            (exportedPage as Buffer).byteLength,
        );
    });
});
