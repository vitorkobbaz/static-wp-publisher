import { expect, test, type Page } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";
import { loginAsAdmin } from "../support/admin";

type BatchFixture = { pages: { id: number; url: string }[] };
type TemplateFixture = { id: number; url: string };
type ArtifactRows = { artifacts: { url: string }[] };

const DASHBOARD = "/wp-admin/admin.php?page=static-wp-publisher";
const home = `${(process.env.WP_E2E_BASE_URL ?? "http://localhost").replace(/\/+$/, "")}/`;

function row(page: Page, url: string) {
    return page.locator(`tr[data-swpp-url="${url}"]`);
}

function status(page: Page, url: string) {
    return row(page, url).locator(".column-status [data-swpp-status]");
}

test.describe
    .serial("Administration dashboard", () => {
    test("guides a fresh site to its first static copies", async ({ page }) => {
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        await loginAsAdmin(page);
        await page.goto(DASHBOARD);

        await expect(page.locator("[data-swpp-headline]")).toHaveText(
            "Make your site faster for visitors",
        );
        await expect(page.locator(".wp-list-table")).toHaveCount(0);
        await expect(
            page.getByRole("button", { name: "Create static copies" }),
        ).toHaveCount(2);
        await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
            "data-swpp-serving",
            "off",
        );
        await expect(page.locator(".swpp-paused")).toContainText(
            "Static delivery is paused",
        );
    });

    test("creates copies, filters by state, tests pages, and runs row and bulk actions", async ({
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
        // Page-builder templates are not pages: never listed.
        await expect(row(page, template.url)).toHaveCount(0);

        await test.step("create every static copy with progress", async () => {
            await page
                .locator('[data-swpp-generate="build"] button')
                .first()
                .click();
            const notice = page.locator("[data-swpp-notice]");
            await expect(notice).toContainText("static copies ready", {
                timeout: 240_000,
            });
            await expect(notice).toContainText("0 errors");
        });

        await test.step("the summary adds up and states read in plain words", async () => {
            for (const published of [first, second]) {
                await expect(status(page, published.url)).toHaveAttribute(
                    "data-swpp-status",
                    "static",
                );
                await expect(status(page, published.url)).toHaveClass(
                    /swpp-state--good/,
                );
            }
            await expect(status(page, secret.url)).toHaveText("WordPress only");
            await expect(row(page, secret.url)).toContainText(
                "Page is password protected.",
            );
            await expect(page.locator("[data-swpp-headline]")).toContainText(
                "have a static copy ready",
            );
            await expect(
                page.locator('[data-swpp-legend="dynamic"]'),
            ).toContainText("always use");
            await expect(
                page.locator('[data-swpp-legend="attention"]'),
            ).toHaveCount(0);

            const artifacts = parseFixtureJson<ArtifactRows>(
                runWp(["swpp-e2e", "migration-state"]),
            ).artifacts.map((artifact) => artifact.url);
            expect(artifacts).toContain(first.url);
            expect(artifacts).not.toContain(template.url);
        });

        await test.step("tabs filter, and empty tabs stay out of the way", async () => {
            await expect(
                page.locator('[data-swpp-view="attention"]'),
            ).toHaveCount(0);

            await page.locator('[data-swpp-view="dynamic"]').click();
            await expect(row(page, secret.url)).toBeVisible();
            await expect(row(page, first.url)).toHaveCount(0);

            await page.locator('[data-swpp-view="static"]').click();
            await expect(row(page, first.url)).toBeVisible();
            await expect(row(page, secret.url)).toHaveCount(0);

            await page.goto(`${DASHBOARD}&swpp_view=attention`);
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

        await test.step("resume delivery and test a chosen page", async () => {
            await page.goto(DASHBOARD);
            await page
                .getByRole("button", { name: "Resume static delivery" })
                .click();
            await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
                "data-swpp-serving",
                "on",
            );
            await expect(page.locator("[data-swpp-headline]")).toContainText(
                "load as fast static HTML",
            );

            await page
                .locator("#swpp-speed-page")
                .selectOption(String(first.id));
            await page.getByRole("button", { name: "Test this page" }).click();
            const speed = page.locator("[data-swpp-speed]");
            await expect(speed).toContainText("Page tested:", {
                timeout: 60_000,
            });
            await expect(speed).toContainText("SWPP batch 1");
            await expect(speed).toContainText("Static copy");
            await expect(page.locator(".swpp-method summary")).toHaveText(
                "How is this measured?",
            );
        });

        await test.step("row and bulk actions run in place", async () => {
            await row(page, first.url)
                .locator('[data-swpp-row-action="verify"]')
                .click();
            await expect(
                row(page, first.url).locator("[data-swpp-result]"),
            ).toContainText("Visitors get the static copy", { timeout: 60_000 });

            await row(page, first.url).locator('input[name="post_ids[]"]').check();
            await row(page, secret.url).locator('input[name="post_ids[]"]').check();
            await page
                .locator('select[name="action"]')
                .selectOption("swpp-verify");
            await page.locator("#doaction").click();
            await expect(
                page.locator("[data-swpp-progress-text]"),
            ).toContainText("Done: 1 OK, 1 with warnings, 0 failed", {
                timeout: 90_000,
            });
            await expect(
                row(page, secret.url).locator("[data-swpp-result]"),
            ).toContainText("Visitors get live WordPress");

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
        });

        await test.step("a static front page is listed at the home address", async () => {
            parseFixtureJson(
                runWp(["swpp-e2e", "front-page", String(first.id)]),
            );
            await page.goto(DASHBOARD);
            await expect(row(page, home)).toContainText("SWPP batch 1");
            await expect(status(page, home)).toHaveAttribute(
                "data-swpp-status",
                "static",
            );
            await expect(row(page, first.url)).toHaveCount(0);
            parseFixtureJson(runWp(["swpp-e2e", "front-page", "0"]));
        });

        await test.step("pausing asks first", async () => {
            await page.goto(DASHBOARD);
            page.once("dialog", (dialog) => dialog.dismiss());
            await page.getByRole("button", { name: "Pause" }).click();
            await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
                "data-swpp-serving",
                "on",
            );

            page.once("dialog", (dialog) => dialog.accept());
            await page.getByRole("button", { name: "Pause" }).click();
            await expect(page.locator("[data-swpp-serving]")).toHaveAttribute(
                "data-swpp-serving",
                "off",
            );
            await expect(page.locator(".swpp-paused")).toBeVisible();
        });
    });
});
