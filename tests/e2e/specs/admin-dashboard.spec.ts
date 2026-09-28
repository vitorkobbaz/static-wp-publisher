import { expect, test, type Page } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";
import { loginAsAdmin } from "../support/admin";

type BatchFixture = { pages: { id: number; url: string }[] };
type TemplateFixture = { id: number; url: string };
type ArtifactRows = { artifacts: { url: string }[] };

const DASHBOARD = "/wp-admin/admin.php?page=static-wp-publisher";

function row(page: Page, url: string) {
    return page.locator(`tr[data-swpp-url="${url}"]`);
}

function status(page: Page, url: string) {
    return row(page, url).locator(".column-status [data-swpp-status]");
}

test.describe
    .serial("Administration dashboard", () => {
    test("generates pages, filters by state, and runs row and bulk actions in place", async ({
        page,
    }, testInfo) => {
        testInfo.setTimeout(300_000);
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const batch = parseFixtureJson<BatchFixture>(
            runWp(["swpp-e2e", "create-batch", "3"]),
        );
        const [first, second, secret] = batch.pages;
        parseFixtureJson(
            runWp(["swpp-e2e", "protect", String(secret.id), "swpp-secret"]),
        );
        const template = parseFixtureJson<TemplateFixture>(
            runWp(["swpp-e2e", "create-template"]),
        );

        await loginAsAdmin(page);
        await page.goto(DASHBOARD);
        await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
            "data-swpp-serving",
            "off",
        );
        await expect(status(page, first.url)).toHaveAttribute(
            "data-swpp-group",
            /^(pending|missing)$/,
        );
        // Page-builder templates are not pages: never listed.
        await expect(row(page, template.url)).toHaveCount(0);

        await test.step("generate everything with visible progress", async () => {
            await page
                .getByRole("button", { name: "Generate all pages", exact: true })
                .click();
            const notice = page.locator("[data-swpp-notice]");
            await expect(notice).toContainText("Finished", {
                timeout: 240_000,
            });
            await expect(notice).toContainText("0 errors");
        });

        await test.step("states, colours and counters", async () => {
            for (const published of [first, second]) {
                await expect(status(page, published.url)).toHaveAttribute(
                    "data-swpp-status",
                    "static",
                );
                await expect(status(page, published.url)).toHaveClass(
                    /swpp-state--good/,
                );
            }
            await expect(status(page, secret.url)).toHaveAttribute(
                "data-swpp-group",
                "dynamic",
            );
            await expect(row(page, secret.url)).toContainText(
                "Page is password protected.",
            );
            await expect(
                page.locator('[data-swpp-legend="attention"]'),
            ).toHaveCount(0);
            await expect(page.locator("[data-swpp-headline]")).toContainText(
                "have a static copy ready",
            );

            const artifacts = parseFixtureJson<ArtifactRows>(
                runWp(["swpp-e2e", "migration-state"]),
            ).artifacts.map((artifact) => artifact.url);
            expect(artifacts).toContain(first.url);
            expect(artifacts).not.toContain(template.url);
        });

        await test.step("tabs separate pages served by WordPress", async () => {
            await page.locator('[data-swpp-view="dynamic"]').click();
            await expect(row(page, secret.url)).toBeVisible();
            await expect(row(page, first.url)).toHaveCount(0);

            await page.locator('[data-swpp-view="static"]').click();
            await expect(row(page, first.url)).toBeVisible();
            await expect(row(page, secret.url)).toHaveCount(0);

            await page.locator('[data-swpp-view="attention"]').click();
            await expect(page.locator(".wp-list-table")).toContainText(
                "Nothing needs attention.",
            );
        });

        await test.step("search narrows the list", async () => {
            await page.goto(DASHBOARD);
            await page.locator("#swpp-search-search-input").fill("batch 2");
            await page.locator("#search-submit").click();
            await expect(row(page, second.url)).toBeVisible();
            await expect(row(page, first.url)).toHaveCount(0);
        });

        await test.step("row and bulk actions run without reloading", async () => {
            await page.goto(DASHBOARD);
            await page
                .getByRole("button", { name: "Turn on", exact: true })
                .click();
            await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
                "data-swpp-serving",
                "on",
            );
            await expect(page.locator("[data-swpp-headline]")).toContainText(
                "served as static HTML",
            );

            await page.getByRole("button", { name: "Measure speed" }).click();
            const speed = page.locator("[data-swpp-speed]");
            await expect(speed).toContainText("Static HTML", { timeout: 60_000 });
            await expect(speed).toContainText("ms");
            await expect(
                page.getByRole("button", { name: "Measure again" }),
            ).toBeEnabled();

            await row(page, first.url)
                .locator('[data-swpp-row-action="verify"]')
                .click();
            await expect(
                row(page, first.url).locator("[data-swpp-result]"),
            ).toContainText("Confirmed: delivered from the static copy");

            await row(page, first.url).locator('input[name="post_ids[]"]').check();
            await row(page, secret.url).locator('input[name="post_ids[]"]').check();
            await page
                .locator('select[name="action"]')
                .selectOption("swpp-verify");
            await page.locator("#doaction").click();
            await expect(
                page.locator("[data-swpp-progress-text]"),
            ).toContainText("Done: 1 OK, 1 with warnings, 0 failed", {
                timeout: 60_000,
            });
            await expect(
                row(page, secret.url).locator("[data-swpp-result]"),
            ).toContainText("Delivered by WordPress");

            await row(page, second.url)
                .locator('[data-swpp-row-action="regenerate"]')
                .click();
            await expect(
                row(page, second.url).locator("[data-swpp-result]"),
            ).toContainText("Static copy updated.");
            await expect(status(page, second.url)).toHaveAttribute(
                "data-swpp-status",
                "static",
            );

            await page.goto(DASHBOARD);
            await page
                .getByRole("button", { name: "Turn off", exact: true })
                .click();
            await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
                "data-swpp-serving",
                "off",
            );
        });
    });
});
