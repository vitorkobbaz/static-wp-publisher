import { existsSync, unlinkSync } from "node:fs";
import { runWpEnv, wpEnvStartedMarker } from "./support/process";

export default function globalTeardown(): void {
    if (!existsSync(wpEnvStartedMarker)) {
        return;
    }
    try {
        runWpEnv(["stop"], { timeout: 120_000 });
    } catch (error) {
        console.warn(`Unable to stop wp-env cleanly: ${String(error)}`);
    } finally {
        unlinkSync(wpEnvStartedMarker);
    }
}
