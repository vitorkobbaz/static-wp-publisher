import { expect, test } from "@playwright/test";
import { createHash } from "node:crypto";
import { parseFixtureJson, runWp } from "../support/process";
import { processQueue } from "../support/queue";

type Row = Record<string, string | null>;
type MigrationState = {
    schema_option: string | number | null;
    migrated_by: string | null;
    columns: string[];
    active_index: { column: string; unique: boolean }[];
    jobs: Row[];
    builds: Row[];
    artifacts: Row[];
    settings: Record<string, unknown>;
};
type LegacySeed = {
    pages: { a: string; b: string; c: string };
    jobs: {
        pending: number;
        duplicate_pending: number;
        expired_running: number;
        succeeded: number;
        failed: number;
    };
    state: MigrationState;
};

const sha256 = (value: string): string =>
    createHash("sha256").update(value).digest("hex");

function migrationState(): MigrationState {
    return parseFixtureJson<MigrationState>(
        runWp(["swpp-e2e", "migration-state"]),
    );
}

function job(state: MigrationState, id: number): Row {
    const row = state.jobs.find((candidate) => Number(candidate.id) === id);
    expect(row, `job ${id} must survive the migration`).toBeDefined();
    return row as Row;
}

test.describe.serial("Schema upgrade from v1 to v2", () => {
    test("upgrades a live v1 install in place and preserves its data", async ({
        request,
    }, testInfo) => {
        testInfo.setTimeout(180_000);
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const seed = parseFixtureJson<LegacySeed>(
            runWp(["swpp-e2e", "seed-v1"]),
        );
        const { pages, jobs } = seed;

        await test.step("the seeded database is a genuine v1 schema", () => {
            expect(seed.state.schema_option).toBeNull();
            expect(seed.state.columns).not.toContain("active_hash");
            expect(seed.state.columns).not.toContain("locked_by");
            expect(seed.state.active_index).toEqual([]);
            expect(seed.state.jobs).toHaveLength(5);
            expect(seed.state.builds).toHaveLength(1);
            expect(seed.state.artifacts).toHaveLength(1);
        });

        await test.step("the first web request upgrades and keeps serving v1 artifacts", async () => {
            const response = await request.get(pages.a);
            expect(response.ok()).toBe(true);
            expect(response.headers()["x-static-wp-publisher"]).toBe("HIT");
            expect(await response.text()).toContain('data-swpp-e2e="legacy"');
        });

        const upgraded = migrationState();

        await test.step("schema v2 is in place and was applied by the web request", () => {
            expect(Number(upgraded.schema_option)).toBe(2);
            expect(upgraded.migrated_by).not.toBeNull();
            expect(upgraded.migrated_by).not.toBe("cli");
            expect(upgraded.columns).toEqual(
                expect.arrayContaining(["active_hash", "locked_by"]),
            );
            expect(upgraded.active_index).toEqual([
                { column: "active_hash", unique: true },
            ]);
        });

        await test.step("existing jobs, builds, artifacts and settings are preserved", () => {
            expect(upgraded.jobs.map((row) => Number(row.id))).toEqual(
                seed.state.jobs.map((row) => Number(row.id)),
            );

            const pending = job(upgraded, jobs.pending);
            expect(pending.status).toBe("pending");
            expect(pending.active_hash).toBe(sha256(pages.a));
            expect(pending.url).toBe(pages.a);

            const duplicate = job(upgraded, jobs.duplicate_pending);
            expect(duplicate.status).toBe("failed");
            expect(duplicate.active_hash).toBeNull();
            expect(duplicate.last_error).toBe(
                "Duplicate active job removed during schema migration.",
            );

            const running = job(upgraded, jobs.expired_running);
            expect(running.status).toBe("running");
            expect(running.active_hash).toBe(sha256(pages.b));
            expect(running.locked_by).toBeNull();

            const succeeded = job(upgraded, jobs.succeeded);
            expect(succeeded.status).toBe("succeeded");
            expect(succeeded.active_hash).toBeNull();
            expect(Number(succeeded.attempts)).toBe(1);

            const failed = job(upgraded, jobs.failed);
            expect(failed.status).toBe("failed");
            expect(failed.active_hash).toBeNull();
            expect(Number(failed.attempts)).toBe(5);
            expect(failed.last_error).toBe("Legacy v1 failure.");

            for (const row of upgraded.jobs) {
                const before = seed.state.jobs.find(
                    (candidate) => candidate.id === row.id,
                ) as Row;
                expect(row.url_hash).toBe(before.url_hash);
                expect(row.reason).toBe(before.reason);
                expect(row.created_at).toBe(before.created_at);
            }

            expect(upgraded.builds).toEqual(seed.state.builds);
            expect(upgraded.artifacts).toEqual(seed.state.artifacts);
            expect(upgraded.settings).toMatchObject({
                enabled: true,
                retain_versions: 7,
            });
        });

        await test.step("re-running the installer is idempotent", () => {
            parseFixtureJson(runWp(["swpp-e2e", "reinstall"]));
            const again = migrationState();
            expect(again.jobs).toEqual(upgraded.jobs);
            expect(again.builds).toEqual(upgraded.builds);
            expect(again.artifacts).toEqual(upgraded.artifacts);
            expect(again.active_index).toEqual(upgraded.active_index);
        });

        await test.step("the new unique index deduplicates migrated active jobs", () => {
            const result = parseFixtureJson<{ enqueued: boolean }>(
                runWp(["swpp-e2e", "enqueue", pages.a]),
            );
            expect(result.enqueued).toBe(false);
        });

        await test.step("the worker drains migrated jobs, recovering the expired lease", async () => {
            let state = migrationState();
            for (let pass = 0; pass < 10; pass++) {
                const pendingA = job(state, jobs.pending).status;
                const runningB = job(state, jobs.expired_running).status;
                if (pendingA === "succeeded" && runningB === "succeeded") {
                    break;
                }
                await processQueue(request);
                state = migrationState();
            }

            const published = job(state, jobs.pending);
            expect(published.status).toBe("succeeded");
            expect(published.active_hash).toBeNull();

            const recovered = job(state, jobs.expired_running);
            expect(recovered.status).toBe("succeeded");
            expect(Number(recovered.attempts)).toBe(1);
            expect(recovered.active_hash).toBeNull();

            const artifact = state.artifacts.find((row) => row.url === pages.a);
            expect(artifact?.content_hash).not.toBe(
                seed.state.artifacts[0]?.content_hash,
            );
            expect(state.artifacts.some((row) => row.url === pages.b)).toBe(
                true,
            );
        });

        await test.step("the refreshed artifact replaces the v1 copy", async () => {
            const response = await request.get(pages.a);
            expect(response.headers()["x-static-wp-publisher"]).toBe("HIT");
            const body = await response.text();
            expect(body).toContain('data-swpp-e2e="upgrade-a"');
            expect(body).not.toContain('data-swpp-e2e="legacy"');
        });
    });
});
