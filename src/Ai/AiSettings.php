<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Ai;

use FinityLabs\LinCodex\Settings\CodexAiSettings;
use Illuminate\Database\QueryException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * The one place fin-codex reads and writes the AI settings group.
 *
 * Every read sits inside the guard that catches spatie's MissingSettings and
 * a QueryException, and hands back values rather than the settings object. A
 * group that was never seeded, or a host with no settings table at all, means
 * "AI is off", never an error - the rule AiAvailabilityCheck and
 * Sources\DefaultLocale already follow. Returning an array also keeps the
 * load out of a bare property read, which PHPStan level 5 reports as
 * expr.resultUnused.
 *
 * write() seeds by writing: on an unseeded group it binds a settings object
 * built from the package defaults and saves it, which upserts all six rows
 * with the key encrypted. So neither the settings page nor the install step
 * ever has to publish and run lin-codex's settings migration for a host that
 * upgraded the package without it.
 *
 * The guards live here rather than on the page, which is why HelpSettings
 * keeps exactly the two it already carries.
 */
final class AiSettings
{
    /**
     * The stored AI settings, or CodexAiSettings::defaults() when the group is
     * unseeded or the settings table is missing.
     *
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        try {
            return app(CodexAiSettings::class)->toArray();
        } catch (MissingSettings|QueryException) {
            return CodexAiSettings::defaults();
        }
    }

    /**
     * The stored API key, null when none is stored.
     *
     * The placeholder on the key field, the required-when-enabled rule and
     * the blank-means-keep-it rule on save all read it from here, never from
     * the form: the field itself is blanked on fill and never echoes a key.
     */
    public static function storedApiKey(): ?string
    {
        $key = self::values()['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Merge $overrides over the current values and save them.
     *
     * On a group that has never been written this creates all six rows from
     * the package defaults, with api_key encrypted through the settings
     * class's own attribute. The lin-codex group is never touched.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function write(array $overrides): void
    {
        $values = array_merge(self::values(), $overrides);

        try {
            $settings = app(CodexAiSettings::class);

            /*
             * The load, and the statement HelpSettings::save() already
             * carries: a method call, so its unused result is not the
             * expr.resultUnused a bare property read would be.
             */
            $settings->toArray();
        } catch (MissingSettings|QueryException) {
            $settings = new CodexAiSettings(CodexAiSettings::defaults());

            app()->instance(CodexAiSettings::class, $settings);
        }

        $settings->fill($values);
        $settings->save();
    }
}
