import { expect, test, type Page } from "@playwright/test";
import { parseFixtureJson, runWp } from "../support/process";
import { processQueue } from "../support/queue";

type OptimizeFixture = {
    id: number;
    url: string;
    images: string[];
    background: string;
    width: number;
    height: number;
};
type ArtifactState = { exists: boolean; content: string };

function escape(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/** The whole <img> tag whose src is the given URL. */
function imageTag(html: string, src: string): string {
    const match = html.match(
        new RegExp(`<img\\b[^>]*\\bsrc="${escape(src)}"[^>]*>`),
    );
    expect(match, src).not.toBeNull();
    return match?.[0] ?? "";
}

function artifact(url: string): string {
    const state = parseFixtureJson<ArtifactState>(
        runWp(["swpp-e2e", "artifact", url]),
    );
    expect(state.exists, `static copy of ${url}`).toBe(true);
    return state.content;
}

async function republish(
    request: Parameters<typeof processQueue>[0],
    url: string,
): Promise<void> {
    parseFixtureJson(runWp(["swpp-e2e", "enqueue", url]));
    await processQueue(request);
}

/** Computed styles of every element, as a comparable fingerprint of how the page looks. */
async function styleFingerprint(page: Page, url: string): Promise<string[]> {
    const response = await page.goto(url);
    expect(response?.headers()["x-static-wp-publisher"]).toBe("HIT");
    await page.waitForLoadState("load");
    return page.evaluate(async () => {
        await document.fonts.ready;
        const properties = [
            "display",
            "position",
            "color",
            "background-color",
            "background-image",
            "background-size",
            "font-family",
            "font-size",
            "font-weight",
            "line-height",
            "margin-top",
            "margin-bottom",
            "padding-top",
            "padding-left",
            "width",
            "min-height",
        ];
        return Array.from(document.body.querySelectorAll("*"))
            .slice(0, 600)
            .map((element) => {
                const style = window.getComputedStyle(element);
                return `${element.tagName}.${element.className}|${properties
                    .map((property) => style.getPropertyValue(property))
                    .join("|")}`;
            });
    });
}

test.describe
    .serial("Speed optimizations in static copies", () => {
    let fixture: OptimizeFixture;

    test("preloads the hero, sizes and lazy-loads images, and swaps fonts", async ({
        request,
    }) => {
        parseFixtureJson(runWp(["swpp-e2e", "reset"]));
        fixture = parseFixtureJson<OptimizeFixture>(
            runWp(["swpp-e2e", "optimize-fixture"]),
        );
        parseFixtureJson(runWp(["swpp-e2e", "enable"]));
        await republish(request, fixture.url);

        const html = artifact(fixture.url);
        expect(html).toContain(
            `<link rel="preload" as="image" href="${fixture.background}" fetchpriority="high" data-swpp-opt="preload" />`,
        );
        expect(html.indexOf('data-swpp-opt="preload"')).toBeLessThan(
            html.indexOf("swpp-e2e/one.css"),
        );

        fixture.images.forEach((image, index) => {
            const attributes = imageTag(html, image);
            expect(attributes).toContain(
                `width="${fixture.width}" height="${fixture.height}"`,
            );
            expect(attributes).toContain('decoding="async"');
            if (index < 2) {
                expect(attributes).not.toContain('loading="lazy"');
            } else {
                expect(attributes).toContain('loading="lazy"');
            }
        });

        expect(html).toContain(
            "@font-face{font-family:'SwppE2E';font-display: swap;",
        );
        // CSS combining is experimental and off by default.
        expect(html).toContain("swpp-e2e/one.css");
        expect(html).not.toContain('data-swpp-opt="css"');
    });

    test("combining CSS serves one file and keeps the page looking the same", async ({
        page,
        request,
    }) => {
        const before = await styleFingerprint(page, fixture.url);

        parseFixtureJson(runWp(["swpp-e2e", "set-option", "combine_css", "1"]));
        await republish(request, fixture.url);
        const html = artifact(fixture.url);

        for (const file of ["swpp-e2e-bg.css", "one.css", "two.css", "three.css"]) {
            expect(html).not.toContain(`swpp-e2e/${file}`);
        }
        const bundle = html.match(
            /<link rel="stylesheet" href="([^"]+\/assets\/css\/[a-f0-9]{16}\.css)" media="all" data-swpp-opt="css" \/>/,
        );
        expect(bundle, "combined stylesheet link").not.toBeNull();

        const css = await request.get(bundle?.[1] ?? "");
        expect(css.ok()).toBe(true);
        expect(css.headers()["content-type"]).toContain("text/css");
        const body = await css.text();
        expect(body.indexOf(".swpp-e2e-one")).toBeLessThan(
            body.indexOf(".swpp-e2e-three"),
        );
        expect(body).toContain(`url(${fixture.images[0]})`);

        const after = await styleFingerprint(page, fixture.url);
        expect(after).toEqual(before);
    });

    test("with optimizations off the copy is stored exactly as WordPress renders it", async ({
        request,
    }) => {
        parseFixtureJson(runWp(["swpp-e2e", "set-option", "combine_css", "0"]));
        parseFixtureJson(runWp(["swpp-e2e", "set-option", "optimize", "0"]));
        await republish(request, fixture.url);

        const html = artifact(fixture.url);
        expect(html).not.toContain("data-swpp-opt");
        expect(html).toContain("font-display:auto");
        const last = imageTag(html, fixture.images[4]);
        expect(last).not.toContain('loading="lazy"');
        expect(last).not.toContain("width=");
    });
});
