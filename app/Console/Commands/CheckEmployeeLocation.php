<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;

class CheckEmployeeLocation extends Command
{
    protected $signature = 'employee:check-location {id?}';
    protected $description = 'Verify employee location updates';
    
    public function handle()
    {
        $id = $this->argument('id');
        
        if ($id) {
            $emp = Employee::find($id);
            if (!$emp) {
                $this->error("Employee {$id} not found");
                return;
            }
            $this->info("Employee: {$emp->first_name} {$emp->last_name} (ID: {$emp->id})");
            $this->info("Lat: {$emp->latitude}, Lng: {$emp->longitude}");
            $this->info("Last update: {$emp->last_location_update}");
        } else {
            $this->info("=== Employees with recent location updates ===");
            $emps = Employee::whereNotNull('last_location_update')
                        ->orderBy('last_location_update', 'desc')
                        ->take(10)
                        ->get(['id', 'first_name', 'last_name', 'latitude', 'longitude', 'last_location_update']);
            
            if ($emps->isEmpty()) {
                $this->warn("No employees with location data");
                return;
            }
            
            foreach ($emps as $emp) {
                $this->line("ID: {$emp->id} | {$emp->first_name} {$emp->last_name} | Lat: {$emp->latitude}, Lng: {$emp->longitude} | Updated: {$emp->last_location_update}");
            }
        }
    }
}
