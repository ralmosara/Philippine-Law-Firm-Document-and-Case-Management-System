<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\ClientPortalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_only_see_their_own_matters()
    {
        // Setup Firms and Clients
        $firmId = DB::table('firms')->insertGetId(['name' => 'Test Firm', 'created_at' => now(), 'updated_at' => now()]);
        
        $client1Id = DB::table('clients')->insertGetId(['firm_id' => $firmId, 'name' => 'Client One', 'created_at' => now(), 'updated_at' => now()]);
        $client2Id = DB::table('clients')->insertGetId(['firm_id' => $firmId, 'name' => 'Client Two', 'created_at' => now(), 'updated_at' => now()]);
        
        // Setup Matters
        DB::table('matters')->insert([
            ['firm_id' => $firmId, 'client_id' => $client1Id, 'case_number' => 'C1-01', 'created_at' => now(), 'updated_at' => now()],
            ['firm_id' => $firmId, 'client_id' => $client2Id, 'case_number' => 'C2-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
        
        $controller = new ClientPortalController();
        
        $request = Request::create('/api/client/matters', 'GET');
        $request->headers->set('X-Client-Id', $client1Id);
        
        $response = $controller->getMatters($request);
        $data = json_decode($response->getContent(), true);
        
        $this->assertCount(1, $data);
        $this->assertEquals('C1-01', $data[0]['case_number']);
    }
}
