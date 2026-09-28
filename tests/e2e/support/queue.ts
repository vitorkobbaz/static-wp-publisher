import { expect, type APIRequestContext } from "@playwright/test";

/** Runs one worker pass through a real HTTP request to WordPress. */
export async function processQueue(request: APIRequestContext): Promise<void> {
    const response = await request.post(
        "/index.php?rest_route=/swpp-e2e/v1/process",
        {
            headers: { "X-SWPP-E2E": "static-wp-publisher-e2e" },
        },
    );
    expect(response.ok(), await response.text()).toBe(true);
}
