import { expect, test, type Page } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";
import { loginAsAdmin } from "../support/admin";

type BatchFixture = { pages: { id: number; url: string }[] };

const DASHBOARD = "/wp-admin/admin.php?page=static-wp-publisher";

function row(page: Page, url: string) {
    return page.locator(`tr[data-swpp-url="${url}"]`);
}

test.describe
    .serial("Administration dashboard", () => {
    test("generates every page with progress, shows per-page status and verifies delivery", async ({
        page,
    }, testInfo) => {
        testInfo.setTimeout(240_000);
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        const batch = parseFixtureJson<BatchFixture>(
            runWp(["swpp-e2e", "create-batch", "3"]),
        );
        const [first, second, secret] = batch.pages;
        parseFixtureJson(
            runWp(["swpp-e2e", "protect", String(secret.id), "swpp-secret"]),
        );

        await loginAsAdmin(page);
        await page.goto(DASHBOARD);
        await expect(page.locator(".swpp-banner__title")).toHaveText(
            "Static serving is OFF",
        );
        await expect(
            row(page, first.url).locator("[data-swpp-status]"),
        ).toHaveAttribute("data-swpp-status", /^(queued|missing)$/);

        await page
            .getByRole("button", { name: "Generate all pages now" })
            .click();
        await expect(page.locator("[data-swpp-progress]")).toBeVisible();
        const notice = page.locator("[data-swpp-notice]");
        await expect(notice).toContainText("Generation finished", {
            timeout: 180_000,
        });
        await expect(notice).toContainText("0 errors");

        for (const published of [first, second]) {
            await expect(
                row(page, published.url).locator("[data-swpp-status]"),
            ).toHaveAttribute("data-swpp-status", "static");
        }
        await expect(
            row(page, secret.url).locator("[data-swpp-status]"),
        ).toHaveAttribute("data-swpp-status", "dynamic");
        await expect(row(page, secret.url)).toContainText(
            "Page is password protected.",
        );

        await page
            .getByRole("button", { name: "Turn static serving on" })
            .click();
        await expect(page.locator(".swpp-banner__title")).toHaveText(
            "Static serving is ON",
        );

        await row(page, first.url).getByRole("button", { name: "Check" }).click();
        await expect(notice).toContainText("Confirmed:");
        await expect(notice).toContainText("delivered from the static copy");

        await row(page, secret.url)
            .getByRole("button", { name: "Check" })
            .click();
        await expect(notice).toContainText("delivered by WordPress");

        await row(page, second.url)
            .getByRole("button", { name: "Regenerate" })
            .click();
        await expect(notice).toContainText("Static copy of");
        await expect(
            row(page, second.url).locator("[data-swpp-status]"),
        ).toHaveAttribute("data-swpp-status", "static");

        await page
            .getByRole("button", { name: "Turn static serving off" })
            .click();
        await expect(page.locator(".swpp-banner__title")).toHaveText(
            "Static serving is OFF",
        );
    });
});
