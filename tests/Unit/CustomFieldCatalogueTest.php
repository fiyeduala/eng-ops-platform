<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Concerns\HasCustomFields;
use App\Support\CustomFieldCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue is a promise, and this is where it is kept.
 *
 * A LINE IN CustomFieldCatalogue SAYS THE WHOLE PATH WORKS — define, render, validate, save,
 * display — for that register. The failure it is guarding against has no error message: an
 * administrator adds a field to a register whose form ignores it, fills it in, saves, and finds it
 * empty, with nothing anywhere saying why. Nothing at runtime catches that, because there is
 * nothing wrong; the field simply was never asked.
 *
 * So the parts of the promise that CAN be checked mechanically are checked here:
 *
 *   - the model can hold answers at all (it uses HasCustomFields)
 *   - the entity_type IS the morph alias, which is what CustomFieldRegistry relies on to work out
 *     which definitions belong to a record it has been handed, and what the value table stores in
 *     `subject_type`
 *   - the model is in the morph map at all, without which Auditable throws the moment a value is
 *     saved against it
 *
 * The part that cannot be checked mechanically — that the register's form renders the section and
 * its service saves it — is what the feature tests and the wiring pass cover. This file is the
 * cheap half, and it is the half that breaks silently when somebody adds a register in a hurry.
 */
final class CustomFieldCatalogueTest extends TestCase
{
    #[Test]
    public function every_catalogued_register_names_a_model_that_can_hold_answers(): void
    {
        foreach (CustomFieldCatalogue::all() as $entityType => [, , $class]) {
            $this->assertTrue(class_exists($class), "{$entityType}: {$class} does not exist.");

            $this->assertContains(
                HasCustomFields::class,
                class_uses_recursive($class),
                "{$entityType}: {$class} is offered on the configuration screen but does not use "
                .'HasCustomFields, so anything defined against it would be asked and then dropped.',
            );
        }
    }

    #[Test]
    public function the_entity_type_is_the_morph_alias(): void
    {
        // THE ONE THAT MATTERS. CustomFieldRegistry works out a record's register from
        // getMorphClass(); the value table stores the same string in subject_type. If the two ever
        // drift, a register's fields stop being found for its own records — no error, just a form
        // that asks nothing and a show page that displays nothing.
        $map = array_flip(Relation::morphMap());

        foreach (CustomFieldCatalogue::all() as $entityType => [, , $class]) {
            $alias = $map[$class] ?? null;

            $this->assertNotNull(
                $alias,
                "{$entityType}: {$class} is not in the morph map, so Auditable would throw a "
                .'ClassMorphViolationException the first time a value was saved against it.',
            );

            $this->assertSame(
                $entityType,
                $alias,
                "The catalogue calls {$class} '{$entityType}' and the morph map calls it "
                ."'{$alias}'. One word per register, everywhere.",
            );
        }
    }

    #[Test]
    public function the_morph_alias_is_what_an_instance_actually_reports(): void
    {
        // The map says one thing; a model overriding getMorphClass() could say another, and the
        // registry asks the instance, not the map.
        foreach (CustomFieldCatalogue::all() as $entityType => [, , $class]) {
            /** @var Model $model */
            $model = new $class;

            $this->assertSame($entityType, $model->getMorphClass(), "{$entityType}: {$class}");
        }
    }

    #[Test]
    public function every_register_has_a_group_and_a_label_an_administrator_can_read(): void
    {
        // §73. The screen groups by the first and prints the second; a blank either way is a tile
        // with no name on it.
        foreach (CustomFieldCatalogue::all() as $entityType => [$group, $label]) {
            $this->assertNotSame('', trim($group), "{$entityType} has no group.");
            $this->assertNotSame('', trim($label), "{$entityType} has no label.");
            $this->assertSame($label, CustomFieldCatalogue::label($entityType));
            $this->assertSame($group, CustomFieldCatalogue::group($entityType));
        }
    }

    #[Test]
    public function a_register_appears_once_and_a_model_appears_once(): void
    {
        // Two catalogue rows pointing at one model would put the same fields on one form twice,
        // under two register names, with the second silently colliding on the key.
        $classes = array_column(CustomFieldCatalogue::all(), 2);

        $this->assertSame(
            count($classes),
            count(array_unique($classes)),
            'Two registers name the same model: '
            .implode(', ', array_keys(array_diff_key($classes, array_unique($classes)))),
        );
    }

    #[Test]
    public function the_things_that_are_not_registers_stay_out(): void
    {
        // Configuration records are not registers — a custom field on a status is a field on the
        // configuration of a field — and line items belong to the form builder's repeating tables,
        // not here.
        foreach (['status', 'option_list', 'custom_field', 'role', 'purchase_order_item'] as $key) {
            $this->assertFalse(
                CustomFieldCatalogue::supports($key),
                "'{$key}' is being offered as a register.",
            );
        }
    }

    #[Test]
    public function the_picker_offers_exactly_what_the_catalogue_holds(): void
    {
        $grouped = CustomFieldCatalogue::grouped();

        $flattened = [];

        foreach ($grouped as $group => $rows) {
            foreach ($rows as $entityType => $label) {
                $flattened[$entityType] = $label;
                $this->assertSame($group, CustomFieldCatalogue::group($entityType));
            }
        }

        $this->assertSame(CustomFieldCatalogue::keys(), array_keys($flattened));
    }
}
