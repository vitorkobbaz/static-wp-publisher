import { expect, test } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";
import { processQueue } from "../support/queue";

type BatchFixture = { pages: { id: number; url: string }[] };
type ArtifactRows = { artifacts: { url: string }[] };

const home = `${(process.env.WP_E2E_BASE_URL ?? "http://localhost").replace(/\/+$/, "")}/`;

test.describe
    .serial("Static serving boundaries and worker throughput", () => {
    test("one worker pass publishes more than a handful of URLs", async ({
        request,
    }, testInfo) => {
        testInfo.setTimeout(180_000);
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const batch = parseFixtureJson<BatchFixture>(
            runWp(["swpp-e2e", "create-batch", "8"]),
        );
        parseFixtureJson(runWp(["swpp-e2e", "enqueue", home]));

        await processQueue(request);

        const published = new Set(
            parseFixtureJson<ArtifactRows>(
                runWp(["swpp-e2e", "migration-state"]),
            ).artifacts.map((row) => row.url),
        );
        for (const page of batch.pages) {
            expect(published.has(page.url), page.url).toBe(true);
        }
        expect(published.has(home)).toBe(true);
    });

    test("serves static HTML only when the query string cannot change the page", async ({
        request,
    }) => {
        const batch = parseFixtureJson<BatchFixture>(
            runWp(["swpp-e2e", "create-batch", "1"]),
        );
        const page = batch.pages[0];
        await processQueue(request);
        parseFixtureJson(runWp(["swpp-e2e", "enable"]));

        const plain = await request.get(home);
        expect(plain.headers()["x-static-wp-publisher"]).toBe("HIT");

        const tracked = await request.get(
            `${home}?utm_source=e2e&utm_campaign=launch&fbclid=abc`,
        );
        expect(tracked.headers()["x-static-wp-publisher"]).toBe("HIT");

        const search = await request.get(`${home}?s=swppsearchterm`);
        expect(search.ok()).toBe(true);
        expect(search.headers()["x-static-wp-publisher"]).toBeUndefined();
        expect(await search.text()).toContain("swppsearchterm");

        const mixed = await request.get(`${home}?utm_source=e2e&s=swppmixed`);
        expect(mixed.headers()["x-static-wp-publisher"]).toBeUndefined();
        expect(await mixed.text()).toContain("swppmixed");

        const byId = await request.get(`${home}?p=${page.id}`, {
            maxRedirects: 0,
        });
        expect(byId.headers()["x-static-wp-publisher"]).toBeUndefined();

        const pageStatic = await request.get(page.url);
        expect(pageStatic.headers()["x-static-wp-publisher"]).toBe("HIT");
        const pageDynamic = await request.get(`${page.url}?preview=true`);
        expect(pageDynamic.headers()["x-static-wp-publisher"]).toBeUndefined();
    });
});
