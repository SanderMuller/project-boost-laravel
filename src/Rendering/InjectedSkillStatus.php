<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Rendering;

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\SkillShipmentIndex;
use SanderMuller\BoostCore\Sync\SkillShipmentStatus;
use SanderMuller\BoostCore\Sync\SyncResult;

/**
 * The status cell of `project-boost:where`, decided away from the command so
 * it can be tested against a constructed {@see SyncResult}.
 *
 * The decision it owns is not "did this skill ship" — boost-core's
 * {@see SkillShipmentIndex} answers that — but whether a REASON may be given
 * for a skill that did not. A source whose renderer throws is excluded from
 * the resolved set rather than reported, so it never reaches
 * `$result->writes`, and the command used to fall through to
 * `filtered (declare: …)`: a confident, specific, wrong diagnosis that sends
 * an operator to fix a `withTags()` which was never the cause.
 *
 * So a run carrying errors is DEGRADED, and a degraded run states the fact
 * and withholds the cause. `ship` and `shadowed by` survive it — those remain
 * true of the skills they describe.
 *
 * `SyncResult::hasErrors()` is the whole error surface, not just `$errors`:
 * an ERRORED emitter with an empty error list makes it true too. Reading one
 * channel and not the other is how a failing run reports as a clean one.
 *
 * @internal
 */
final readonly class InjectedSkillStatus
{
    private function __construct(
        private SkillShipmentIndex $shipment,
        private bool $degraded,
    ) {}

    public static function from(SyncResult $result): self
    {
        return new self(SkillShipmentIndex::from($result), $result->hasErrors());
    }

    /**
     * Whether the run behind this listing carried errors, so the caller can
     * say so before the table rather than leave the withheld reasons
     * unexplained.
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function isShipped(string $skillName): bool
    {
        return $this->shipment->isShipped($skillName);
    }

    /**
     * The rendered status cell. `$tagsLabel` is the skill's own tag list as
     * already formatted for the table, so the advice names the exact tags an
     * operator would declare.
     */
    public function cellFor(Skill $skill, string $tagsLabel): string
    {
        $status = $this->shipment->statusFor($skill->name, $skill->tags);

        if ($status === SkillShipmentStatus::SHIPPED) {
            return '<fg=green>ship</>';
        }

        if ($status === SkillShipmentStatus::SHADOWED) {
            // Every shadowing vendor, not the last one seen — a host copy can
            // shadow the same name across several allowlisted vendors, and
            // naming one reads as a complete answer.
            return sprintf('<fg=yellow>shadowed by %s</>', implode(', ', $this->shipment->shadowedVendorsFor($skill->name)));
        }

        if ($this->degraded) {
            return '<fg=yellow>not shipping (reason unknown — see errors)</>';
        }

        return $status === SkillShipmentStatus::EXCLUDED
            ? '<fg=yellow>excluded</>'
            : '<fg=yellow>filtered (declare: ' . $tagsLabel . ')</>';
    }
}
