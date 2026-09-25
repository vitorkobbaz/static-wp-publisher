import { expect, test } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";

type ActivationState = {
    core_active: boolean;
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

    test("publishes, serves and refreshes static HTML while bypassing personalized requests", async ({
        context,
        request,
        page,
    }) => {
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const post = parseFixtureJson<PostFixture>(
            runWp(["swpp-e2e", "create", "v1"]),
        );

        parseFixtureJson(runWp(["swpp-e2e", "process"]));
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
        parseFixtureJson(runWp(["swpp-e2e", "process"]));
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
    });
});
