<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The site map (§3) — one screen that aggregates five modules, which is five chances to leak.
 *
 * WHAT MAKES THIS WORTH ITS OWN TEST FILE. Everywhere else in the application a page shows one
 * register and asks one permission. The map shows locations and then decorates each pin with counts
 * drawn from projects, assets, people, attendance and visitors — five registers with five different
 * permissions, none of which `sitemap.view` grants. A count rendered without its own check is a
 * disclosure that no error message would ever reveal, because the number looks exactly as correct
 * as it would if the viewer were entitled to it.
 *
 * AND ZERO IS NOT THE SAME ANSWER AS "YOU MAY NOT ASK". The tempting shortcut is to render 0 for a
 * module the viewer cannot open. That is a lie that happens to be safe-looking: it tells somebody
 * there are no assets at a yard full of them. So a permitted rollup that finds nothing reports 0,
 * and an unpermitted rollup has NO KEY AT ALL — the two cases are asserted separately below,
 * because an implementation that collapsed them would pass a test that only checked for absence.
 *
 *   Meridian (org A)   Head office      coords, geofence 150 m, two people based there
 *                      Warri site       coords, no geofence, nobody based there
 *                      Unmapped yard    NO coordinates — never a pin
 *                      Closed depot     coords but inactive — never a pin
 *   Kestrel  (org B)   Manchester       coords; must never reach a Meridian viewer
 */
final class SiteMapTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private Location $headOffice;

    private Location $warri;

    private Role $mapOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->kestrel = $this->makeOrganization('Kestrel Industrial');

        $this->headOffice = $this->makeLocation($this->meridian, 'Head Office', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'geofence_radius' => 150,
            'city' => 'Lagos',
            'type' => 'office',
        ]);

        $this->warri = $this->makeLocation($this->meridian, 'Delta Terminal Site', [
            'latitude' => 5.5160,
            'longitude' => 5.7500,
            'geofence_radius' => null,
            'city' => 'Warri',
            'type' => 'site',
        ]);

        // Coordinates are nullable and most companies fill them in over months. A location without
        // them belongs in the register and nowhere on the map.
        $this->makeLocation($this->meridian, 'Unmapped Yard', [
            'latitude' => null,
            'longitude' => null,
            'type' => 'yard',
        ]);

        $this->makeLocation($this->meridian, 'Closed Depot', [
            'latitude' => 6.5244,
            'longitude' => 3.3792,
            'is_active' => false,
            'type' => 'warehouse',
        ]);

        $this->makeLocation($this->kestrel, 'Manchester Office', [
            'latitude' => 53.4668,
            'longitude' => -2.2339,
            'geofence_radius' => 100,
            'city' => 'Manchester',
            'type' => 'office',
        ]);

        // The baseline grant pair and nothing else: this is what every person in the company holds,
        // and it must be enough to see the pins and not enough to see a single count.
        $this->mapOnly = $this->makeRole($this->meridian, 'Map Reader', [
            'sitemap.view' => 'organization',
            'location.view' => 'organization',
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function makeLocation(Organization $organization, string $name, array $attributes = []): Location
    {
        return $this->withinOrganization($organization, fn () => Location::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'code' => strtoupper(substr(md5($name), 0, 6)),
            'type' => 'office',
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function basePerson(string $name, Role $role): User
    {
        return $this->makePerson($this->meridian, $name, null, [$role]);
    }

    #[Test]
    public function the_map_page_opens_for_a_viewer_holding_the_permission(): void
    {
        $this->actingFresh($this->basePerson('Tunde Baseline', $this->mapOnly))
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('Site map');
    }

    #[Test]
    public function neither_the_page_nor_its_data_opens_without_the_site_map_permission(): void
    {
        // `location.view` alone. Deliberately the person who CAN see the register: the map is a
        // separate gate, and holding the underlying data permission is not holding it.
        $registerOnly = $this->makeRole($this->meridian, 'Register Only', [
            'location.view' => 'organization',
        ]);

        $user = $this->basePerson('Ngozi Register', $registerOnly);

        $this->actingFresh($user)->get(route('map.index'))->assertForbidden();
        $this->actingFresh($user)->get(route('map.locations'))->assertForbidden();
    }

    #[Test]
    public function only_active_locations_with_coordinates_become_pins(): void
    {
        $response = $this->actingFresh($this->basePerson('Sade Pins', $this->mapOnly))
            ->getJson(route('map.locations'))
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection');

        $features = $response->json('features');

        $this->assertCount(2, $features, 'The unmapped yard and the inactive depot must not be pins.');

        $names = array_column(array_column($features, 'properties'), 'name');

        $this->assertSame(['Delta Terminal Site', 'Head Office'], $names, 'Pins come back ordered by name.');
        $this->assertNotContains('Unmapped Yard', $names);
        $this->assertNotContains('Closed Depot', $names);
    }

    #[Test]
    public function coordinates_are_emitted_longitude_first_as_geojson_requires(): void
    {
        $response = $this->actingFresh($this->basePerson('Emeka Geometry', $this->mapOnly))
            ->getJson(route('map.locations'))
            ->assertOk();

        $office = collect($response->json('features'))
            ->firstWhere('properties.name', 'Head Office');

        // The whole point of this assertion: [3.4219, 6.4281] is Lagos and [6.4281, 3.4219] is the
        // Gulf of Guinea. Both are valid GeoJSON and neither throws, so the order is pinned here.
        $this->assertSame('Point', $office['geometry']['type']);
        $this->assertSame(3.4219, $office['geometry']['coordinates'][0]);
        $this->assertSame(6.4281, $office['geometry']['coordinates'][1]);
    }

    #[Test]
    public function a_location_without_a_geofence_reports_a_null_radius_rather_than_zero(): void
    {
        $response = $this->actingFresh($this->basePerson('Kemi Geofence', $this->mapOnly))
            ->getJson(route('map.locations'))
            ->assertOk();

        $features = collect($response->json('features'))->keyBy('properties.name');

        $this->assertSame(150, $features['Head Office']['properties']['radius']);

        // A radius of 0 would be drawn as a circle of zero metres — an invisible ring that says a
        // geofence exists. Null says there is none, and the frontend draws nothing.
        $this->assertNull($features['Delta Terminal Site']['properties']['radius']);
    }

    #[Test]
    public function another_companys_locations_never_appear_on_this_companys_map(): void
    {
        $response = $this->actingFresh($this->basePerson('Chidi Tenant', $this->mapOnly))
            ->getJson(route('map.locations'))
            ->assertOk();

        $response->assertJsonMissing(['name' => 'Manchester Office']);

        // And the geometry too: a leaked pin would be visible as a coordinate long before anybody
        // noticed the name, so §75 is asserted against the numbers as well as the label.
        $longitudes = array_column(
            array_column(array_column($response->json('features'), 'geometry'), 'coordinates'),
            0
        );

        $this->assertNotContains(-2.2339, $longitudes);
    }

    #[Test]
    public function a_viewer_with_no_module_permissions_gets_pins_and_not_one_count(): void
    {
        // Two colleagues based at the head office. The count exists; this viewer may not have it.
        $this->seedPeopleAtHeadOffice();

        $response = $this->actingFresh($this->basePerson('Ifeoma Blind', $this->mapOnly))
            ->getJson(route('map.locations'))
            ->assertOk();

        foreach ($response->json('features') as $feature) {
            $this->assertSame(
                [],
                $feature['properties']['metrics'],
                'sitemap.view grants the pin and nothing filed against it.'
            );
        }
    }

    #[Test]
    public function a_permitted_rollup_reports_its_count_and_reports_zero_where_it_finds_nothing(): void
    {
        $this->seedPeopleAtHeadOffice();

        $role = $this->makeRole($this->meridian, 'Map And People', [
            'sitemap.view' => 'organization',
            'location.view' => 'organization',
            'employee.view' => 'organization',
        ]);

        $response = $this->actingFresh($this->basePerson('Bashir People', $role))
            ->getJson(route('map.locations'))
            ->assertOk();

        $features = collect($response->json('features'))->keyBy('properties.name');

        $office = collect($features['Head Office']['properties']['metrics'])->keyBy('key');
        $site = collect($features['Delta Terminal Site']['properties']['metrics'])->keyBy('key');

        // `employee.view` is the ONLY module permission this role holds, so exactly one metric may
        // appear. If assets, projects, attendance or visitors show up here, something is counting
        // without asking.
        $this->assertSame(['people'], $office->keys()->all());
        $this->assertSame(['people'], $site->keys()->all());

        $this->assertSame(2, $office['people']['value']);

        // Nobody is based at the Warri site. This viewer is entitled to know that, so it is a 0 —
        // present, not omitted. The previous test asserts the other half of the distinction.
        $this->assertSame(0, $site['people']['value']);
    }

    #[Test]
    public function a_rollup_counts_only_what_its_own_scope_allows(): void
    {
        $this->seedPeopleAtHeadOffice();

        // `employee.view` held at `own` scope: the permission is present, so the metric appears,
        // but the compiler narrows what it counts. The number must follow the scope rather than
        // the permission's mere presence — otherwise a narrow grant would read the whole company
        // through the map (§6).
        $role = $this->makeRole($this->meridian, 'Map And Own Record', [
            'sitemap.view' => 'organization',
            'location.view' => 'organization',
            'employee.view' => 'own',
        ]);

        $response = $this->actingFresh($this->basePerson('Yemi Narrow', $role))
            ->getJson(route('map.locations'))
            ->assertOk();

        $office = collect($response->json('features'))
            ->firstWhere('properties.name', 'Head Office');

        $metrics = collect($office['properties']['metrics'])->keyBy('key');

        $this->assertTrue($metrics->has('people'), 'The permission is held, so the metric is offered.');
        $this->assertLessThan(
            2,
            $metrics['people']['value'],
            'A grant at `own` scope must not count two colleagues it cannot open.'
        );
    }

    /**
     * Two people based at the head office, and none at the Warri site.
     *
     * Their employee records already exist — makePerson creates one for every login — so this sets
     * the location rather than inventing a second kind of personnel record.
     */
    private function seedPeopleAtHeadOffice(): void
    {
        foreach (['Adanna Office', 'Musa Office'] as $name) {
            $user = $this->makePerson($this->meridian, $name);

            $this->withinOrganization($this->meridian, function () use ($user) {
                $employee = $user->employee;
                $employee->location_id = $this->headOffice->id;
                $employee->save();
            });
        }
    }
}
