<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Expiry\Concerns\HasExpiryDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;

/**
 * Which registers have dates worth watching, and what those dates are called.
 *
 * DISCOVERED, NOT REGISTERED, for the same reason WorkflowCatalogue discovers routable entities: a
 * list maintained by hand is a list that is wrong the first time somebody adds a module and forgets
 * it, and the failure is silent — a register that simply never appears on the screen, with nobody
 * to notice it is missing. A model that uses HasExpiryDates and names a field is offered; nothing
 * else is needed, including for a module built after this class.
 *
 * THIS CLASS IS THE VALIDATOR, and that is its more important job. `entity_type` and `date_field`
 * both arrive from select boxes, which is to say from the browser, and `date_field` ends up in a
 * query builder's `where`. A column name that reached a query unchecked would be a way to ask
 * questions about any column on the table — so nothing is ever trusted from the request, and
 * ExpiryWatchService asks here instead (§84).
 */
final class ExpiryCatalogue
{
    /**
     * Every register that declares at least one watchable date.
     *
     * @return array<string,string> entity_type => label
     */
    public static function watchableEntities(): array
    {
        $entities = [];

        foreach (Relation::morphMap() as $key => $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! in_array(HasExpiryDates::class, class_uses_recursive($class), true)) {
                continue;
            }

            if ($class::expiryDateFields() === []) {
                continue;
            }

            // The same naming rule the workflow catalogue uses, including the escape hatch, plus
            // one step it does not need: a model that already told the WORKFLOW picker what to call
            // it is not asked to say so twice. Two catalogues deriving a name independently is how
            // an administrator ends up choosing "Asset Maintenances" on one screen and "Asset
            // maintenance" on the next and wondering whether they are the same register.
            $entities[$key] = match (true) {
                method_exists($class, 'expiryEntityLabel') => $class::expiryEntityLabel(),
                method_exists($class, 'workflowEntityLabel') => $class::workflowEntityLabel(),
                default => str(class_basename($class))->headline()->plural()->toString(),
            };
        }

        ksort($entities);

        return $entities;
    }

    /**
     * The dates one register offers, in the company's words.
     *
     * @return array<string,string> column => label
     */
    public static function dateFieldsFor(string $entityType): array
    {
        $class = Relation::getMorphedModel($entityType);

        if ($class === null || ! class_exists($class)) {
            return [];
        }

        if (! in_array(HasExpiryDates::class, class_uses_recursive($class), true)) {
            return [];
        }

        return $class::expiryDateFields();
    }

    /**
     * Is this pairing one the platform actually offers?
     *
     * The only question a watch is ever validated with. Asked of the catalogue rather than of the
     * schema, so a column that exists but was never offered — `created_at`, a soft-delete stamp —
     * is refused just as firmly as one that does not exist at all.
     */
    public static function offers(string $entityType, string $dateField): bool
    {
        return array_key_exists($dateField, self::dateFieldsFor($entityType));
    }

    /** The class behind a watched entity type, or null where the key names nothing. */
    public static function modelFor(string $entityType): ?string
    {
        $class = Relation::getMorphedModel($entityType);

        return $class !== null && class_exists($class) ? $class : null;
    }

    /**
     * Where a warning about one record should point.
     *
     * BY CONVENTION, AND GUARDED. Register routes in this platform are named after the plural
     * kebab-case of the model — `vehicle-documents.show`, `form-submissions.show` — and a generic
     * engine has nothing else to go on, because `routePrefix()` is a method each controller
     * declares rather than data anybody can look up.
     *
     * The `Route::has` check is what makes the convention safe to rely on. A register that does not
     * follow it, or has no show screen at all, yields a warning with no link instead of an
     * exception inside a nightly job — and a warning worth sending is still worth sending without
     * one. The same reasoning as the Configuration Center's tiles, for the same reason.
     */
    public static function showRouteFor(Model $record): ?string
    {
        $name = str(class_basename($record))->plural()->kebab()->toString().'.show';

        if (! Route::has($name)) {
            return null;
        }

        return route($name, $record, absolute: false);
    }
}
