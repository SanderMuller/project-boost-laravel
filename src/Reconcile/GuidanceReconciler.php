<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Reconcile;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * The engine behind `project-boost:reconcile` — a diff-first guided takeover
 * for projects where laravel/boost's `boost:install` seeded guidelines DIRECTLY
 * into the agent guidance files (`AGENTS.md` / `GEMINI.md` / a legacy `CLAUDE.md` / …)
 * inside `<laravel-boost-guidelines>` markers, and a subsequent markerless
 * boost-core sync would wholesale-overwrite them.
 *
 * Two responsibilities:
 *
 *  - {@see analyze()} — classify each configured agent's guidance file
 *    (resolved via the `@api` `Agent::target()->guidelinesFileRelative()`
 *    bridge), splitting laravel/boost's marker body from any hand-authored
 *    residual outside it.
 *  - {@see capture()} — make the at-risk content safe BEFORE syncing: back up
 *    every at-risk file verbatim, and write the deduplicated hand-authored
 *    residual into `.ai/guidelines/` so boost-core re-composes it into every
 *    agent file on the next `project-boost:sync`.
 *
 * **The legacy `CLAUDE.md`.** boost-core 1.12 writes Claude Code guidance to
 * `AGENTS.md`, and so does laravel/boost from v2.10. laravel/boost before v2.10
 * still seeds its marker block into `CLAUDE.md`. No sync touches that file any
 * more, but while it exists Claude Code reads it and skips `AGENTS.md`. So when
 * Claude Code is active, a marker-carrying `CLAUDE.md` is analyzed too.
 * {@see retireLegacyFiles()} replaces it with an `@AGENTS.md` import once the
 * backup exists and a sync wrote `AGENTS.md`.
 *
 * **Why the marker split, not a content diff.** A precise on-disk-vs-would-write
 * diff needs boost-core's assembled-guidance text, which is produced by
 * `@internal` engine internals (`GuidanceComposer`, `SyncManifest`) this package
 * deliberately does not couple to. laravel/boost's `<laravel-boost-guidelines>`
 * marker is its stable public install contract and the exact signal boost-core's
 * own `doctor` foreign-seed detector uses — so the marker split is both
 * false-positive-free and internals-free. When boost-core ships an `@api`
 * read-only guidance-preview primitive, the residual detection here can be
 * upgraded to a precise content diff.
 *
 * @internal
 */
final class GuidanceReconciler
{
    private const string MARKER_OPEN = '<laravel-boost-guidelines>';

    private const string MARKER_PATTERN = '/<laravel-boost-guidelines>(.*?)<\/laravel-boost-guidelines>/s';

    /**
     * The `.ai/guidelines/` filename the captured residual lands in. A single
     * deduplicated file (not per-agent) — guidelines are cross-agent by design,
     * and identical hand-edits across `AGENTS.md` / `GEMINI.md` should not
     * produce duplicate guidance.
     */
    private const string CAPTURE_FILENAME = 'reconciled.md';

    /**
     * Where laravel/boost before v2.10 seeded its Claude Code guidelines.
     */
    private const string LEGACY_CLAUDE_FILE = 'CLAUDE.md';

    private const string LEGACY_IMPORT_PATTERN = '/^[ \t]*@(?:\.\/)?AGENTS\.md[ \t]*\r?$/m';

    public function analyze(BoostConfig $config, string $projectRoot): ReconcilePlan
    {
        $root = rtrim($projectRoot, '/');

        /** @var array<string, list<Agent>> $byPath */
        $byPath = [];
        $claudeCodeActive = false;
        foreach ($config->agents as $agent) {
            $claudeCodeActive = $claudeCodeActive || $agent === Agent::CLAUDE_CODE;

            $relative = $agent->target()->guidelinesFileRelative();
            if ($relative === null) {
                continue;
            }

            $byPath[$relative][] = $agent;
        }

        $files = [];
        foreach ($byPath as $relative => $agents) {
            $files[] = $this->analyzeFile($relative, $root . '/' . $relative, $agents);
        }

        $legacyPath = $root . '/' . self::LEGACY_CLAUDE_FILE;

        // laravel/boost never seeds a symlink, and retireLegacyFiles() must not write through one.
        if ($claudeCodeActive && ! isset($byPath[self::LEGACY_CLAUDE_FILE]) && ! is_link($legacyPath)) {
            $legacy = $this->analyzeFile(self::LEGACY_CLAUDE_FILE, $legacyPath, [Agent::CLAUDE_CODE], legacy: true);

            // A hand-written CLAUDE.md is not reconcile's business; only a
            // laravel/boost-seeded one is.
            if ($legacy->isAtRisk()) {
                $files[] = $legacy;
            }
        }

        return new ReconcilePlan($files);
    }

    /**
     * Back up every at-risk file verbatim under `$backupDir`, then write the
     * deduplicated hand-authored residual into `.ai/guidelines/` so the next
     * sync re-composes it. Idempotent: when nothing is at risk (or a prior
     * reconcile already moved residual into `.ai/guidelines/`, leaving the files
     * markerless), no residual is captured and the existing capture file is left
     * untouched.
     */
    public function capture(ReconcilePlan $plan, BoostConfig $config, string $backupDir): CaptureResult
    {
        $backups = [];

        /** @var array<string, true> $uniqueResiduals */
        $uniqueResiduals = [];

        foreach ($plan->atRiskFiles() as $file) {
            $backupPath = rtrim($backupDir, '/') . '/' . $file->relativePath;
            $this->ensureDirectory(dirname($backupPath));
            if (copy($file->absolutePath, $backupPath)) {
                $backups[] = $backupPath;
            }

            if ($file->residual !== null && $file->residual !== '') {
                $uniqueResiduals[$file->residual] = true;
            }
        }

        $capturedPath = null;
        if ($uniqueResiduals !== []) {
            $capturedPath = rtrim($config->guidelinesPath, '/') . '/' . self::CAPTURE_FILENAME;
            $this->ensureDirectory(dirname($capturedPath));

            // Append-with-dedup rather than overwrite: a repeat reconcile (after
            // another accidental `boost:install`) must not clobber a capture file
            // the operator has since edited. Only genuinely-new residual is added;
            // if every residual is already present, the file is left untouched.
            $existing = is_file($capturedPath) ? (string) file_get_contents($capturedPath) : null;
            $merged = $this->mergeCapture($existing, array_keys($uniqueResiduals));
            if ($merged !== null) {
                file_put_contents($capturedPath, $merged);
            }
        }

        return new CaptureResult($backups, $capturedPath);
    }

    /**
     * Replace each legacy `CLAUDE.md` with an import of Claude Code's current
     * guidance file, so Claude Code reads the synced guidance. Call it after a
     * successful sync. A file without a backup from {@see capture()}, whose
     * import target does not exist yet, or that cannot be written, stays as it is.
     *
     * @return list<string> the relative paths that were replaced
     */
    public function retireLegacyFiles(ReconcilePlan $plan, CaptureResult $capture, string $backupDir, string $projectRoot): array
    {
        $target = Agent::CLAUDE_CODE->target()->guidelinesFileRelative();
        if ($target === null || ! is_file(rtrim($projectRoot, '/') . '/' . $target)) {
            return [];
        }

        $retired = [];
        foreach ($plan->atRiskFiles() as $file) {
            if (! $file->legacy || ! in_array(rtrim($backupDir, '/') . '/' . $file->relativePath, $capture->backups, true)) {
                continue;
            }

            if (@file_put_contents($file->absolutePath, '@' . $target . "\n") !== false) {
                $retired[] = $file->relativePath;
            }
        }

        return $retired;
    }

    /**
     * @param  list<Agent>  $agents
     */
    private function analyzeFile(string $relative, string $absolute, array $agents, bool $legacy = false): GuidanceFileAnalysis
    {
        if (! is_file($absolute)) {
            return new GuidanceFileAnalysis($relative, $absolute, ReconcileStatus::ABSENT, null, null, $agents, $legacy);
        }

        $content = (string) file_get_contents($absolute);

        if (! str_contains($content, self::MARKER_OPEN)) {
            return new GuidanceFileAnalysis($relative, $absolute, ReconcileStatus::CLEAN, null, null, $agents, $legacy);
        }

        $markerBody = null;
        if (preg_match(self::MARKER_PATTERN, $content, $matches) === 1) {
            $markerBody = trim($matches[1]);
        }

        // Residual = everything outside a well-formed marker block. An
        // unterminated marker (open without close) won't match the pattern, so
        // preg_replace is a no-op and the whole file is treated as residual —
        // safely over-capturing rather than dropping content.
        $residual = (string) preg_replace(self::MARKER_PATTERN, '', $content);

        // An existing `@AGENTS.md` line in the legacy file is not guidance.
        // Captured, it would make AGENTS.md import itself.
        if ($legacy) {
            $residual = (string) preg_replace(self::LEGACY_IMPORT_PATTERN, '', $residual);
        }

        $residual = trim($residual);

        $status = $residual === ''
            ? ReconcileStatus::FOREIGN_SEEDED
            : ReconcileStatus::FOREIGN_SEEDED_WITH_RESIDUAL;

        return new GuidanceFileAnalysis(
            $relative,
            $absolute,
            $status,
            $markerBody,
            $residual === '' ? null : $residual,
            $agents,
            $legacy,
        );
    }

    /**
     * Merge newly-captured residual into any existing capture file without
     * clobbering it. A fresh file gets the header + all residual; an existing
     * file gets ONLY the residual it doesn't already contain appended below it
     * (preserving operator edits). Returns null when nothing new would be added,
     * signalling the caller to leave the file untouched.
     *
     * @param  list<string>  $residuals
     */
    private function mergeCapture(?string $existing, array $residuals): ?string
    {
        if ($existing === null || trim($existing) === '') {
            return $this->buildCapturedGuideline($residuals);
        }

        $new = array_values(array_filter(
            $residuals,
            static fn (string $residual): bool => ! str_contains($existing, $residual),
        ));

        if ($new === []) {
            return null;
        }

        return rtrim($existing, "\n") . "\n\n" . implode("\n\n", $new) . "\n";
    }

    /**
     * @param  list<string>  $residuals
     */
    private function buildCapturedGuideline(array $residuals): string
    {
        // The header deliberately does NOT embed the literal laravel/boost
        // marker tag — this file is itself composed into the guidance output, and
        // a stray marker string there would be confusing (and trip marker-based
        // tooling). Describe the marker, don't reproduce it.
        $header = <<<'MD'
            <!-- Captured by `php artisan project-boost:reconcile`. -->
            <!-- This is hand-authored content found outside laravel/boost's -->
            <!-- guidelines marker in your agent guidance files, preserved here -->
            <!-- so boost-core re-composes it into every agent file on each -->
            <!-- `project-boost:sync`. Edit, split, or remove freely. -->
            MD;

        return $header . "\n\n" . implode("\n\n", $residuals) . "\n";
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }
    }
}
