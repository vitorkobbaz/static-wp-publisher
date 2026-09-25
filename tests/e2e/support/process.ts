import { spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import path from "node:path";

const repositoryRoot = path.resolve(__dirname, "../../..");
const wpEnvBin = path.join(
    repositoryRoot,
    "node_modules",
    "@wordpress",
    "env",
    "bin",
    "wp-env",
);

export const wpEnvStartedMarker = path.join(
    repositoryRoot,
    ".wp-env",
    "swpp-e2e-started",
);

type CommandOptions = {
    timeout?: number;
    allowFailure?: boolean;
};

export function assertWpEnvInstalled(): void {
    if (!existsSync(wpEnvBin)) {
        throw new Error(
            "wp-env is not installed. Run `npm ci` (or `npm install`) before the E2E suite.",
        );
    }
}

export function runCommand(
    command: string,
    args: string[],
    options: CommandOptions = {},
): string {
    const result = spawnSync(command, args, {
        cwd: repositoryRoot,
        encoding: "utf8",
        env: { ...process.env, NO_COLOR: "1" },
        timeout: options.timeout ?? 120_000,
        windowsHide: true,
    });

    if (result.error) {
        if (options.allowFailure) {
            return "";
        }
        throw new Error(`Unable to run ${command}: ${result.error.message}`);
    }

    if (result.status !== 0 && !options.allowFailure) {
        throw new Error(
            `Command failed (${command} ${args.join(" ")}).\n` +
                `stdout:\n${result.stdout || "(empty)"}\n` +
                `stderr:\n${result.stderr || "(empty)"}`,
        );
    }

    return `${result.stdout ?? ""}${result.stderr ?? ""}`;
}

export function runWpEnv(args: string[], options: CommandOptions = {}): string {
    assertWpEnvInstalled();
    return runCommand(process.execPath, [wpEnvBin, ...args], options);
}

export function runWp(args: string[], options: CommandOptions = {}): string {
    return runWpEnv(["run", "tests-cli", "wp", ...args], options);
}

export function parseFixtureJson<T>(output: string): T {
    const line = output
        .split(/\r?\n/)
        .find((candidate) => candidate.startsWith("SWPP_E2E_JSON:"));

    if (!line) {
        throw new Error(`Fixture command returned no JSON payload.\n${output}`);
    }

    return JSON.parse(line.slice("SWPP_E2E_JSON:".length)) as T;
}
