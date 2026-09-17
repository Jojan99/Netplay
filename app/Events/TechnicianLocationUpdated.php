<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TechnicianLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int    $employeeId;
    public string  $firstName;
    public string  $lastName;
    public ?string $jobTitle;
    public float   $latitude;
    public float   $longitude;
    public string  $updatedAt;
    public ?int    $companyId;

    public function __construct(int $employeeId, string $firstName, string $lastName, ?string $jobTitle, float $latitude, float $longitude)
    {
        $this->employeeId = $employeeId;
        $this->firstName  = $firstName;
        $this->lastName   = $lastName;
        $this->jobTitle   = $jobTitle;
        $this->latitude   = $latitude;
        $this->longitude  = $longitude;
        $this->updatedAt  = now()->toIso8601String();
        // La empresa sale del empleado: los que disparan el evento no la pasan.
        $this->companyId  = (int) \Illuminate\Support\Facades\DB::table('employees')->where('id', $employeeId)->value('company_id') ?: null;
    }

    public function broadcastOn(): array
    {
        // Antes iba al canal público 'technician-tracking': cualquiera (incluso
        // sin sesión) veía la ubicación de los técnicos de todas las empresas y
        // el mapa las pintaba. Ahora solo va al canal privado de la empresa. El
        // panel compilado sigue escuchando el público, pero recarga las
        // ubicaciones cada 20 s, así que el mapa sigue al día.
        return $this->companyId
            ? [new PrivateChannel('company.' . $this->companyId . '.technicians')]
            : [];
    }

    public function broadcastAs(): string
    {
        return 'technician.location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'first_name'  => $this->firstName,
            'last_name'   => $this->lastName,
            'job_title'   => $this->jobTitle,
            'latitude'    => $this->latitude,
            'longitude'   => $this->longitude,
            'updated_at'  => $this->updatedAt,
        ];
    }
}
