<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\DatabaseTestCase;

class ReportsTest extends DatabaseTestCase
{
    public function test_admin_can_open_paged_read_only_reports_and_operational_profiles_cannot(): void
    {
        $admin = $this->userFor('admin', 'report-admin@example.test');
        $viewer = $this->userFor('viewer', 'report-viewer@example.test');
        $this->actingAs($admin);

        foreach (['sales', 'purchases', 'inventory', 'kardex', 'cancellations'] as $report) {
            $this->get('/reports?report='.$report)->assertOk();
        }
        $this->get('/reports?report=sales&from=bad')->assertSessionHasErrors('from');
        $this->get('/reports?report=cancellations&to=2026-01-01&from=2026-02-01')->assertSessionHasErrors('to');
        $this->flushSession()->actingAs($viewer)->get('/reports')->assertForbidden();
    }

    private function userFor(string $roleCode, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Reporte '.$roleCode,
            'email' => $email,
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $roleCode)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }
}
