import { expect, type Page } from "@playwright/test";

/** Logs into wp-admin with the wp-env default administrator. */
export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto("/wp-login.php");
    await page.locator("#user_login").fill("admin");
    await page.locator("#user_pass").fill("password");
    await page.locator("#wp-submit").click();
    await expect(page).toHaveURL(/\/wp-admin\//);
}
