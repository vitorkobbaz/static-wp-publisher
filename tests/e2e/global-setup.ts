import { request } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import {
    assertWpEnvInstalled,
    runCommand,
    runWpEnv,
    wpEnvStartedMarker,
} from "./support/process";

export default async function globalSetup(): Promise<void> {
    assertWpEnvInstalled();

    try {
        runCommand("docker", ["info", "--format", "{{.ServerVersion}}"], {
            timeout: 30_000,
        });
    } catch (error) {
        throw new Error(
            "Docker is unavailable. Start Docker Desktop and verify `docker info` succeeds before running `npm run test:e2e`.\n" +
                String(error),
        );
    }

    try {
        runWpEnv(["start"], { timeout: 10 * 60_000 });
        mkdirSync(path.dirname(wpEnvStartedMarker), { recursive: true });
        writeFileSync(
            wpEnvStartedMarker,
            `${new Date().toISOString()}\n`,
            "utf8",
        );
    } catch (error) {
        throw new Error(
            "wp-env could not start the WordPress test environment. Run `npx wp-env logs --environment=tests` for diagnostics.\n" +
                String(error),
        );
    }

    const baseURL = process.env.WP_E2E_BASE_URL ?? "http://localhost:8889";
    const api = await request.newContext({ baseURL });
    try {
        const response = await api.get("/wp-login.php");
        if (!response.ok()) {
            throw new Error(`Health check returned HTTP ${response.status()}.`);
        }
    } catch (error) {
        throw new Error(
            `WordPress did not become reachable at ${baseURL}.\n${String(
                error,
            )}`,
        );
    } finally {
        await api.dispose();
    }
}
