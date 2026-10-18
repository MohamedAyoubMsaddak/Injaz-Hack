<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EndToEndHousingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_worker_admin_student_rental_maintenance_and_payment_workflow(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        $password = 'a-secure-password';

        $this->postJson('/api/auth/register', $this->registrationData('owner', 'owner@example.test', $password))
            ->assertCreated()
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonMissingPath('user.password');
        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('user.role', 'owner');

        $houseResponse = $this->post('/api/owner/houses', [
            ...$this->houseData(),
            'photos' => array_map(fn (int $index) => $this->pngUpload('house-'.$index.'.png'), range(1, 4)),
        ]);
        $houseResponse->assertCreated()
            ->assertJsonPath('house.verification_status', 'pending_negotiation')
            ->assertJsonCount(4, 'house.photos');
        $house = House::with('photos')->findOrFail($houseResponse->json('house.id'));
        $this->assertSame($owner->id, $house->owner_id);
        Storage::disk($disk)->assertExists($house->photos->first()->path);
        $this->assertDatabaseHas('payments', [
            'payer_id' => $owner->id,
            'payment_type' => Payment::TYPE_OWNER_SUBSCRIPTION,
            'amount_tnd' => '20.000',
            'status' => Payment::STATUS_PENDING,
        ]);

        $otherOwner = $this->createRoleUser('owner', 'other-owner@example.test');
        $this->actingAs($otherOwner)->getJson('/api/owner/houses/'.$house->id)->assertNotFound();
        $this->putJson('/api/owner/houses/'.$house->id, $this->houseData())
            ->assertNotFound();

        $worker = $this->createRoleUser('worker', 'worker@example.test', ['governorate' => 'Tunis']);
        $admin = $this->createRoleUser('admin', 'admin@example.test');
        $this->postJson('/api/auth/register', $this->registrationData('student', 'student@example.test', $password))
            ->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonMissingPath('user.password');
        $student = User::where('email', 'student@example.test')->firstOrFail();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('user.role', 'student');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $worker->email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('user.role', 'worker');

        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');
        $this->actingAs($student)->postJson('/api/student/rental-requests', ['house_id' => $house->id])
            ->assertStatus(409);
        $this->actingAs($owner)->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', [
            'worker_id' => $worker->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$house->id.'/submission-decision', [
            'decision' => 'owner_accepted',
        ])->assertOk()
            ->assertJsonPath('house.negotiation_status', 'owner_accepted')
            ->assertJsonPath('house.proposed_owner_rent_tnd', '475.000')
            ->assertJsonPath('house.public_listing_price_tnd', '500.000');
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.verification_status', 'verified')
            ->assertJsonPath('houses.data.0.monthly_rent_tnd', '500.000')
            ->assertJsonPath('houses.data.0.public_listing_price_tnd', '500.000')
            ->assertJsonMissingPath('houses.data.0.original_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.agreed_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.negotiation_notes');
        $this->getJson('/api/student/houses/'.$house->id)
            ->assertOk()
            ->assertJsonPath('house.title', 'Student-friendly house')
            ->assertJsonPath('house.monthly_rent_tnd', '500.000')
            ->assertJsonCount(4, 'house.photos');
        $this->get($this->getJson('/api/student/houses/'.$house->id)->json('house.photos.0.url'))
            ->assertOk();

        $rentalResponse = $this->postJson('/api/student/rental-requests', ['house_id' => $house->id]);
        $rentalResponse->assertCreated()
            ->assertJsonPath('rental_request.status', 'pending')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'not_due')
            ->assertJsonPath('payment_obligation.amount', '520.000')
            ->assertJsonPath('payment_obligation.rent_amount', '500.000')
            ->assertJsonPath('payment_obligation.service_fee_amount', '20.000')
            ->assertJsonPath('checkout_available', false);
        $rentalId = $rentalResponse->json('rental_request.id');
        RentalRequest::query()->findOrFail($rentalId)->conditionReport()->update([
            'status' => 'completed',
            'completed_at' => now(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'condition_checklist' => ['electricity' => 'good'],
            'notes' => 'Admin-reviewed rental inspection report.',
        ]);
        $this->assertSame(0, Payment::where('payment_type', Payment::TYPE_STUDENT_SERVICE_FEE)->count());

        $secondStudent = $this->createRoleUser('student', 'second-student@example.test');
        $secondRental = $this->actingAs($secondStudent)->postJson('/api/student/rental-requests', [
            'house_id' => $house->id,
        ])->assertCreated()->json('rental_request.id');
        RentalRequest::query()->findOrFail($secondRental)->conditionReport()->update([
            'status' => 'completed',
            'completed_at' => now(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'condition_checklist' => ['electricity' => 'good'],
            'notes' => 'Admin-reviewed rental inspection report.',
        ]);
        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$secondRental.'/decision', [
            'decision' => 'rejected',
        ])->assertOk()->assertJsonPath('rental_request.status', 'rejected');
        $this->assertDatabaseHas('payments', [
            'rental_request_id' => $secondRental,
            'payment_type' => Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE,
            'status' => Payment::STATUS_CANCELLED,
        ]);

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rentalId.'/decision', [
            'decision' => 'accepted',
        ])->assertOk()->assertJsonPath('rental_request.status', 'accepted');
        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rentalId.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'accepted')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'not_due');
        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rentalId.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'completed')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'due');
        $this->assertDatabaseHas('payments', [
            'payer_id' => $student->id,
            'rental_request_id' => $rentalId,
            'payment_type' => Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE,
            'amount_tnd' => '520.000',
            'currency' => 'TND',
            'status' => Payment::STATUS_PENDING,
        ]);
        $this->actingAs($student)->getJson('/api/student/payments')
            ->assertOk()
            ->assertJsonPath('payments.0.status', 'pending')
            ->assertJsonPath('payments.0.paid_at', null);

        $this->actingAs($student)->postJson('/api/student/maintenance-requests', [
            'house_id' => $house->id,
            'category' => 'plumbing',
            'description' => 'The kitchen sink pipe is leaking.',
            'priority' => 'normal',
            'reported_at' => now()->toDateTimeString(),
            'photos' => [$this->pngUpload('maintenance.png')],
        ])->assertCreated()
            ->assertJsonPath('maintenance_request.status', 'awaiting_owner_response');
        $maintenanceRequest = MaintenanceRequest::where('house_id', $house->id)->firstOrFail();
        $this->assertSame($student->id, $maintenanceRequest->reporter_id);
        $this->actingAs($owner)->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonPath('subscriptions.0.payment_status', 'pending')
            ->assertJsonPath('subscriptions.0.payments.0.status', 'pending');
        $this->actingAs($student)->getJson('/api/student/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'maintenance_requests.data');
    }

    private function registrationData(string $role, string $email, string $password): array
    {
        return [
            'name' => 'Workflow Account',
            'email' => $email,
            'phone' => '+216 20 000 001',
            'password' => $password,
            'password_confirmation' => $password,
            'role' => $role,
        ];
    }

    private function createRoleUser(string $role, string $email, array $extra = []): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('a-secure-password'),
        ]);
        $user->forceFill(['role' => $role, ...$extra])->save();

        return $user;
    }

    private function houseData(): array
    {
        return [
            'title' => 'Student-friendly house',
            'description' => 'A comfortable place near campus.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Central area',
            'monthly_rent_tnd' => '500.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => true,
        ];
    }

    private function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
    }
}
