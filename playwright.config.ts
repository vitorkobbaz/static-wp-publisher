import { defineConfig } from "@playwright/test";

export default defineConfig({
    testDir: "./tests/e2e/specs",
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [["line"], ["html", { open: "never" }]] : "list",
    globalSetup: "./tests/e2e/global-setup.ts",
    globalTeardown: "./tests/e2e/global-teardown.ts",
    use: {
        baseURL: process.env.WP_E2E_BASE_URL ?? "http://localhost:8889",
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
        video: "retain-on-failure",
    },
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },
});
