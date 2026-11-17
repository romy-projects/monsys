<?php

namespace Tests\Feature;

use App\Filament\Pages\TabungSirkulasiReport;
use App\Filament\Resources\CylinderCirculationResource;
use App\Models\Branch;
use App\Models\CylinderCirculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CylinderCirculationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private CylinderCirculation $circulation;
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        foreach (['owner_pusat', 'regional_leader', 'owner_cabang', 'staff_gudang'] as $role) {
            $this->users[$role] = User::create([
                'name' => $role,
                'email' => "{$role}@circulation-access.test",
                'password' => bcrypt('password'),
                'role' => $role,
                'branch_id' => $this->branch->id,
                'status' => 'active',
            ]);
        }

        $this->circulation = CylinderCirculation::create([
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-11-17',
            'transaction_type' => 'kirim',
            'cylinder_type' => '3kg',
            'direction' => 'debit',
            'quantity' => 5,
        ]);
    }

    #[Test]
    public function only_owner_pusat_can_access_filament_circulation_and_report(): void
    {
        foreach ($this->users as $role => $user) {
            $this->actingAs($user);

            $expected = $role === 'owner_pusat';
            $this->assertSame($expected, CylinderCirculationResource::canAccess(), "{$role} resource access");
            $this->assertSame($expected, TabungSirkulasiReport::canAccess(), "{$role} report access");
            $this->assertSame($expected, CylinderCirculationResource::canCreate(), "{$role} create access");
        }

        $this->actingAs($this->users['owner_pusat']);
        $this->assertTrue(CylinderCirculationResource::canEdit($this->circulation));
        $this->assertTrue(CylinderCirculationResource::canDelete($this->circulation));
    }

    #[Test]
    public function non_hq_roles_cannot_access_any_circulation_api_endpoint(): void
    {
        foreach (['regional_leader', 'owner_cabang', 'staff_gudang'] as $role) {
            $user = $this->users[$role];
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/cylinder-circulations')
                ->assertForbidden();
            $this->getJson('/api/v1/cylinder-circulations/summary')->assertForbidden();
            $this->getJson("/api/v1/cylinder-circulations/{$this->circulation->id}")->assertForbidden();
            $this->postJson('/api/v1/cylinder-circulations', [
                'branch_id' => $this->branch->id,
                'transaction_date' => '2026-11-17',
                'transaction_type' => 'kirim',
                'cylinder_type' => '3kg',
                'quantity' => 1,
            ])->assertForbidden();
            $this->putJson("/api/v1/cylinder-circulations/{$this->circulation->id}", ['quantity' => 10])
                ->assertForbidden();
            $this->deleteJson("/api/v1/cylinder-circulations/{$this->circulation->id}")
                ->assertForbidden();
        }

        $this->assertSame(1, CylinderCirculation::count());
    }

    #[Test]
    public function owner_pusat_retains_circulation_api_access(): void
    {
        $this->actingAs($this->users['owner_pusat'], 'sanctum')
            ->getJson('/api/v1/cylinder-circulations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->circulation->id);

        $this->getJson('/api/v1/cylinder-circulations/summary')->assertOk();
    }
}
