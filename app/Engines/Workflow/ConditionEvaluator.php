<?php

declare(strict_types=1);

namespace App\Engines\Workflow;

use App\Engines\Configuration\SettingsRepository;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Decides whether a stage applies to this particular record (§20).
 *
 * "Anything over 50,000 also goes to the MD." "Safety documents also go to HSE." Those are the
 * sentences a company says when describing its approval process, and every one of them has to be
 * expressible as configuration or the workflow builder is decoration.
 *
 * THE SECURITY BOUNDARY IS readValue(). A condition is DATA — a field, an operator, a value — and
 * is never evaluated as an expression. There is no eval, no Blade compilation, no dynamic method
 * call. A field is readable only if the subject model listed it in workflowConditionFields(), and a
 * dotted path may descend exactly one relation. Everything else returns null and the condition
 * fails closed.
 *
 * Failing CLOSED matters: a condition that cannot be evaluated makes the stage NOT apply, so the
 * stage is skipped with a recorded reason rather than silently treated as satisfied. The
 * alternative — an unreadable condition defaulting to true — would mean a typo in a field name
 * quietly adds an approval stage to every record in the company.
 */
class ConditionEvaluator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Does this stage apply to this record?
     *
     * A stage with no conditions always applies — the overwhelmingly common case, and the one that
     * must not require anybody to configure anything.
     */
    public function stageApplies(WorkflowStage $stage, Model $subject): bool
    {
        $conditions = $stage->relationLoaded('conditions')
            ? $stage->conditions
            : $stage->conditions()->get();

        if ($conditions->isEmpty()) {
            return true;
        }

        return $this->evaluateAll($conditions, $subject);
    }

    /**
     * Combine the rules left to right, honouring each row's `logic`.
     *
     * Left-to-right rather than precedence-aware, and that is a deliberate simplification: the
     * builder presents the rules as a numbered list and this is how a reader of that list will
     * assume it behaves. Supporting AND/OR precedence would make the screen lie unless it also grew
     * brackets, and a workflow condition nobody can read confidently is worse than one that cannot
     * express every boolean shape.
     *
     * @param  Collection<int,WorkflowStageCondition>  $conditions
     */
    public function evaluateAll(Collection $conditions, Model $subject): bool
    {
        $result = null;

        foreach ($conditions->sortBy('sort') as $condition) {
            $outcome = $this->evaluate($condition, $subject);

            $result = match (true) {
                $result === null => $outcome,
                $condition->logic === 'or' => $result || $outcome,
                default => $result && $outcome,
            };
        }

        return $result ?? true;
    }

    /** One rule. */
    public function evaluate(WorkflowStageCondition $condition, Model $subject): bool
    {
        $actual = $this->readValue($subject, (string) $condition->field);
        $expected = $this->resolveToken((string) ($condition->value ?? ''));

        return match ($condition->operator) {
            '=' => $this->looselyEquals($actual, $expected),
            '!=' => ! $this->looselyEquals($actual, $expected),
            '>' => $this->compare($actual, $expected) === 1,
            '>=' => in_array($this->compare($actual, $expected), [0, 1], true),
            '<' => $this->compare($actual, $expected) === -1,
            '<=' => in_array($this->compare($actual, $expected), [0, -1], true),
            'in' => $this->isIn($actual, $expected),
            'not_in' => $actual !== null && ! $this->isIn($actual, $expected),
            'contains' => $actual !== null && str_contains(
                mb_strtolower($this->stringify($actual)),
                mb_strtolower($expected),
            ),
            'between' => $this->isBetween($actual, $expected),
            'is_null' => $actual === null || $actual === '',
            // `changed` is meaningful only mid-save, where the engine is not. Treated as "the field
            // has a value" rather than pretending to know a history this call cannot see — and
            // documented as such on the builder so nobody configures it expecting more.
            'changed' => $actual !== null && $actual !== '',
            default => false,
        };
    }

    /**
     * Read one whitelisted field off the record.
     *
     * The whole reason this class is safe. Three rules, none negotiable:
     *
     *   1. The field must appear in the subject's own workflowConditionFields().
     *   2. A dotted path descends EXACTLY ONE relation, and only if the whole dotted path was
     *      whitelisted — `project.project_type_id`, never `owner.employee.salary`.
     *   3. Anything else is null, which fails the condition closed.
     *
     * Note it reads ATTRIBUTES, never calls methods. A field name can therefore never become a
     * method call, which is the mechanism by which "configuration" turns into code execution.
     */
    public function readValue(Model $subject, string $field): mixed
    {
        $allowed = method_exists($subject, 'workflowConditionFields')
            ? $subject->workflowConditionFields()
            : [];

        if (! in_array($field, $allowed, true)) {
            return null;
        }

        if (! str_contains($field, '.')) {
            return $this->normalise($subject->getAttribute($field));
        }

        [$relationName, $attribute] = explode('.', $field, 2);

        // One level only. A path with two dots was already rejected by the whitelist check above
        // unless somebody whitelisted it deliberately, and even then this refuses to walk it.
        if (str_contains($attribute, '.') || ! method_exists($subject, $relationName)) {
            return null;
        }

        $related = $subject->getRelationValue($relationName);

        return $related instanceof Model ? $this->normalise($related->getAttribute($attribute)) : null;
    }

    /**
     * Resolve `{{setting:finance.approval_threshold}}` in a condition's value.
     *
     * This is why a company can move its approval threshold without editing the workflow: the
     * number lives in settings where finance owns it, and the route simply says "over the
     * threshold". Only `setting:` is resolvable — the token syntax is not a general expression
     * language and must not become one.
     */
    public function resolveToken(string $value): string
    {
        if (! preg_match('/^\{\{\s*setting:([a-z0-9_.]+)\s*\}\}$/i', trim($value), $matches)) {
            return $value;
        }

        return (string) $this->settings->get($matches[1], '');
    }

    // ------------------------------------------------------------------ comparisons

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Equality that behaves the way a person filling in the builder expects.
     *
     * "5" from a text input equals 5 from an integer column, and PHP 8's stricter loose comparison
     * would say otherwise for some pairs. Numeric strings are compared as numbers; everything else
     * is compared as trimmed, case-insensitive text. Never `==` on raw values, which is how
     * "0" == "abc" surprises arrive.
     */
    private function looselyEquals(mixed $actual, string $expected): bool
    {
        if ($actual === null) {
            return $expected === '';
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return mb_strtolower(trim($this->stringify($actual))) === mb_strtolower(trim($expected));
    }

    /** -1, 0, 1 — or null-safe false via a non-comparable 2 that no operator matches. */
    private function compare(mixed $actual, string $expected): int
    {
        if ($actual === null || $expected === '') {
            return 2;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }

        return strcmp($this->stringify($actual), $expected);
    }

    private function isIn(mixed $actual, string $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        foreach ($this->listFrom($expected) as $candidate) {
            if ($this->looselyEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isBetween(mixed $actual, string $expected): bool
    {
        $bounds = $this->listFrom($expected);

        if (count($bounds) !== 2 || $actual === null) {
            return false;
        }

        return in_array($this->compare($actual, $bounds[0]), [0, 1], true)
            && in_array($this->compare($actual, $bounds[1]), [0, -1], true);
    }

    /**
     * A multi-value operand, from JSON or from a comma-separated list.
     *
     * Both accepted because the builder writes JSON and a human editing the row by hand writes
     * "1,2,3". Refusing the second would be pedantry that costs somebody an afternoon.
     *
     * @return array<int,string>
     */
    private function listFrom(string $value): array
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return array_values(array_map(fn ($item) => trim((string) $item), $decoded));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $item) => $item !== '',
        ));
    }
}
